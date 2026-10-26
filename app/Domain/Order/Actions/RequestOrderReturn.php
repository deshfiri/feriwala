<?php

namespace App\Domain\Order\Actions;

use App\Domain\Access\Enums\PermissionAction;
use App\Domain\Access\Enums\PermissionModule;
use App\Domain\Access\PermissionCatalogue;
use App\Domain\Notification\Enums\StaffQueue;
use App\Domain\Order\Data\ReturnSubmission;
use App\Domain\Order\Enums\OrderStatusChangeSource;
use App\Domain\Order\Enums\ReturnCaseType;
use App\Domain\Order\Enums\ReturnInitiator;
use App\Domain\Order\Enums\ReturnParty;
use App\Domain\Order\Enums\ReturnStatus;
use App\Domain\Order\Exceptions\ReturnRefused;
use App\Domain\Order\Models\Order;
use App\Domain\Order\Models\OrderItem;
use App\Domain\Order\Models\OrderReturn;
use App\Domain\Order\Queries\ReturnChargeCalculator;
use App\Domain\Order\Queries\ReturnEligibility;
use App\Models\User;
use App\Support\Concurrency\DistributedLock;
use App\Support\Concurrency\Exceptions\LockTimeout;
use App\Support\StatusHistory\StatusChange;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * Open a case: ask for goods to be taken back (§18.2, contract §6.3, P6-12, D35).
 *
 * **A request, and only a request.** Nothing moves: no stock, no money, no
 * order status beyond saying a case was asked for. Approval, the parcel, the
 * inspection and the settlement are separate decisions taken by the people who
 * own them.
 *
 * **Who asked, and who is responsible, are recorded apart.** A request from a
 * Partner's own panel is the Partner's and begins as the Partner's
 * responsibility; it can never begin as Banij's, because Banij being
 * responsible is a finding staff make after seeing the evidence and the goods.
 * A request from the Partner's customer, through the storefront, is the
 * Partner's too. Only staff open a case on behalf of Banij, and only staff say
 * who is responsible — and both are written as the first line of the case's
 * responsibility history, never to be rewritten, only added to.
 *
 * The cost of the return is snapshotted now from the order's own immutable
 * figures — what its delivery cost and half of that — so what the Partner is
 * shown at the start is what will be charged at the end. Nothing is posted yet.
 *
 * What is asked for is checked against the order, never taken on trust: the
 * order must be in a state something can come back from, the window must still
 * be open, every line must be one this order sold, and no more of a line may
 * come back than went out — counting what earlier cases already claim.
 *
 * The same request twice is one case. An idempotency key makes a retry return
 * the first answer, and the order is locked while the quantities are read and
 * written so two requests arriving together cannot each see the last unit as
 * free.
 */
class RequestOrderReturn
{
    public function __construct(
        protected ReturnEligibility $eligibility,
        protected ReturnChargeCalculator $charges,
        protected AnnounceReturnStatus $announcements,
        protected DatabaseManager $database,
        protected DistributedLock $lock,
    ) {}

    /**
     * @return array{0: OrderReturn, 1: bool} the case, and whether this call created it
     *
     * @throws ReturnRefused
     * @throws AuthorizationException
     */
    public function handle(
        Order $order,
        ReturnSubmission $submission,
        OrderStatusChangeSource $source,
        ?User $actor = null,
    ): array {
        if ($submission->quantities() === []) {
            throw ReturnRefused::noLines();
        }

        $parties = $this->parties($submission, $source, $actor);

        if ($existing = $this->existingFor($order, $submission->idempotencyKey)) {
            return [$existing, false];
        }

        try {
            return $this->lock->run(
                key: 'order-return:'.$order->id,
                callback: fn () => [$this->write($order, $submission, $source, $actor, $parties), true],
                ttlSeconds: 20,
                waitSeconds: 10,
            );
        } catch (LockTimeout) {
            throw ReturnRefused::busy();
        } catch (UniqueConstraintViolationException $exception) {
            // A racing retry of the same key won. Its case is the answer.
            $existing = $this->existingFor($order, $submission->idempotencyKey);

            if ($existing === null) {
                throw $exception;
            }

            return [$existing, false];
        }
    }

    /**
     * Who initiated it, who it is on behalf of, and who is responsible — with
     * the rule that a request not made by staff can never put the blame on Banij.
     *
     * @return array{0: ReturnInitiator, 1: ReturnParty, 2: ReturnParty}
     *
     * @throws ReturnRefused
     * @throws AuthorizationException
     */
    protected function parties(ReturnSubmission $submission, OrderStatusChangeSource $source, ?User $actor): array
    {
        $initiator = $submission->initiator ?? match ($source) {
            OrderStatusChangeSource::Storefront => ReturnInitiator::Customer,
            OrderStatusChangeSource::Staff => ReturnInitiator::Staff,
            default => ReturnInitiator::Partner,
        };

        $onBehalfOf = $submission->onBehalfOf ?? ReturnParty::Partner;
        $responsible = $submission->responsibleParty ?? ReturnParty::Partner;

        if ($initiator === ReturnInitiator::Staff) {
            $module = $submission->caseType === ReturnCaseType::Exchange ? PermissionModule::Exchange : PermissionModule::ReturnCase;

            if ($actor === null || ! $actor->can(PermissionCatalogue::name($module, PermissionAction::Create))) {
                throw new AuthorizationException('This account may not open a case on a Partner\'s behalf.');
            }

            return [$initiator, $onBehalfOf, $responsible];
        }

        // A Partner, or their customer, never declares Banij responsible.
        if ($onBehalfOf === ReturnParty::Banij || $responsible === ReturnParty::Banij) {
            throw ReturnRefused::partnerCannotDeclareBanijResponsible();
        }

        return [$initiator, ReturnParty::Partner, ReturnParty::Partner];
    }

    /**
     * @param  array{0: ReturnInitiator, 1: ReturnParty, 2: ReturnParty}  $parties
     *
     * @throws ReturnRefused
     */
    protected function write(Order $order, ReturnSubmission $submission, OrderStatusChangeSource $source, ?User $actor, array $parties): OrderReturn
    {
        [$initiator, $onBehalfOf, $responsible] = $parties;

        $created = $this->database->transaction(function () use ($order, $submission, $source, $actor, $initiator, $onBehalfOf, $responsible) {
            /** @var Order $locked */
            $locked = Order::query()->lockForUpdate()->findOrFail($order->id);
            $locked->load(['items', 'statusHistory']);

            $this->eligibility->check($locked);

            if ($submission->caseType === ReturnCaseType::Exchange) {
                throw ReturnRefused::exchangeNotAvailable();
            }

            $lines = $this->resolve($locked, $submission);

            $return = OrderReturn::create([
                'order_id' => $locked->id,
                'business_account_id' => $locked->business_account_id,
                'website_id' => $locked->website_id,
                'source' => $source,
                'workflow_version' => 2,
                'case_type' => $submission->caseType,
                'initiated_by_type' => $initiator,
                'on_behalf_of' => $onBehalfOf,
                'responsible_party' => $responsible,
                'status' => ReturnStatus::Requested,
                'reason' => $submission->reason,
                'customer_note' => $submission->customerNote,
                'evidence' => $submission->evidence,
                'requested_by' => $actor?->id,
                'requested_at' => CarbonImmutable::now(),
                'currency_code' => $locked->currency_code,
                'idempotency_key' => $submission->idempotencyKey,

                // What the original delivery cost, and half of it: fixed now, from
                // the order's own locked figures, and shown to the Partner up front.
                'original_delivery_charge' => $this->charges->originalDelivery($locked),
                'return_charge' => $this->charges->returnCharge($locked),
            ]);

            foreach ($lines as [$item, $quantity]) {
                $return->items()->create([
                    'order_item_id' => $item->id,
                    'quantity' => $quantity,
                    'currency_code' => $item->currency_code,
                ]);
            }

            $return->responsibilityChanges()->create([
                'previous_party' => null,
                'new_party' => $responsible,
                'changed_by' => $actor?->id,
                'changed_at' => CarbonImmutable::now(),
                'reason' => 'Case opened by '.$initiator->label().' on behalf of '.$onBehalfOf->label().'.',
            ]);

            $return->recordRequest(
                new StatusChange(
                    actorId: $actor?->id,
                    reason: $submission->caseType->label().' asked for: '.$submission->reason->label().'.',
                    publicNote: 'returns.notes.requested',
                ),
                $source,
            );

            $this->announcements->handle($return->refresh()->load('items'));

            return $return;
        });

        StaffQueue::OrderReturn->announce($created);

        return $created;
    }

    /**
     * Turn what was asked for into lines of this order, or refuse.
     *
     * @return array<int, array{0: OrderItem, 1: int}>
     *
     * @throws ReturnRefused
     */
    protected function resolve(Order $order, ReturnSubmission $submission): array
    {
        $report = $this->eligibility->for($order);
        $returnable = [];

        foreach ($report['lines'] as $line) {
            $returnable[$line['sku']] = $line['returnable'];
        }

        $lines = [];

        foreach ($submission->quantities() as $sku => $quantity) {
            /** @var OrderItem|null $item */
            $item = $order->items->firstWhere('sku', $sku);

            if ($item === null) {
                throw ReturnRefused::lineNotOnOrder($sku);
            }

            if ($quantity > ($returnable[$sku] ?? 0)) {
                throw ReturnRefused::lineNotReturnable($sku, $quantity, $returnable[$sku] ?? 0);
            }

            $lines[] = [$item, $quantity];
        }

        return $lines;
    }

    protected function existingFor(Order $order, ?string $key): ?OrderReturn
    {
        if ($key === null) {
            return null;
        }

        /** @var OrderReturn|null $return */
        $return = OrderReturn::query()
            ->where('order_id', $order->id)
            ->where('idempotency_key', $key)
            ->with('items')
            ->first();

        return $return;
    }
}
