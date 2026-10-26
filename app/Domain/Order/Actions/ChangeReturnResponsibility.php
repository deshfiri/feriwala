<?php

namespace App\Domain\Order\Actions;

use App\Domain\Access\Enums\PermissionAction;
use App\Domain\Access\Enums\PermissionModule;
use App\Domain\Access\PermissionCatalogue;
use App\Domain\Audit\Actions\RecordAuditLog;
use App\Domain\Audit\Data\AuditEntry;
use App\Domain\Order\Enums\ReturnParty;
use App\Domain\Order\Exceptions\ReturnRefused;
use App\Domain\Order\Models\OrderReturn;
use App\Domain\Order\Models\ReturnCharge;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Eloquent\Collection;

/**
 * Decide, or change, who a case is attributed to (D35).
 *
 * Only staff do it, with a reason, because it decides who pays. Every change
 * writes a line of responsibility history — previous value, new value, who,
 * when, why — and **never rewrites** an earlier one: what was believed at any
 * moment can be read back.
 *
 * If money had already been posted under the old attribution, it is put right
 * the only way a ledger allows: each live charge is **reversed** by a new entry
 * pointing at the one it answers (the wallet gets back exactly the kinds of
 * money it lost), and the same charge is **posted again** to the new party under
 * a key of its own revision. That is one compensating pair per charge. Nothing
 * already written is edited or deleted, and a second request for the same change
 * finds the first and does nothing.
 *
 * A completed case is not reopened — but its attribution may still be corrected,
 * because the compensating pair is exactly the "compensating action" it is
 * allowed.
 */
class ChangeReturnResponsibility
{
    public const MINIMUM_REASON = 10;

    public function __construct(
        protected PostReturnCharge $charges,
        protected ReverseReturnCharge $reversals,
        protected RecordAuditLog $audit,
        protected DatabaseManager $database,
    ) {}

    /**
     * @throws ReturnRefused
     * @throws AuthorizationException
     */
    public function handle(User $actor, OrderReturn $return, ReturnParty $to, string $reason): OrderReturn
    {
        if (! $actor->can(PermissionCatalogue::name(PermissionModule::ReturnCase, PermissionAction::Review))) {
            throw new AuthorizationException('This account may not decide who is responsible.');
        }

        $reason = trim($reason);

        if (mb_strlen($reason) < self::MINIMUM_REASON) {
            throw ReturnRefused::responsibilityNeedsAReason();
        }

        $previous = null;

        $changed = $this->database->transaction(function () use ($actor, $return, $to, $reason, &$previous) {
            /** @var OrderReturn $locked */
            $locked = OrderReturn::query()->lockForUpdate()->findOrFail($return->id);

            if (! $locked->usesCaseWorkflow()) {
                throw ReturnRefused::wrongWorkflow();
            }

            // Already attributed this way: the first change stands.
            if ($locked->responsible_party === $to) {
                return $locked;
            }

            $previous = $locked->responsible_party;

            $locked->responsibilityChanges()->create([
                'previous_party' => $previous,
                'new_party' => $to,
                'changed_by' => $actor->id,
                'changed_at' => CarbonImmutable::now(),
                'reason' => $reason,
            ]);

            $locked->forceFill(['responsible_party' => $to])->save();

            // The attribution revision: the initial row is revision 0.
            $revision = $locked->responsibilityChanges()->count() - 1;

            /** @var Collection<int, ReturnCharge> $live */
            $live = $locked->charges()
                ->where('entry_type', ReturnCharge::CHARGE)
                ->whereIn('status', ['posted', 'settled', 'outstanding', 'recorded'])
                ->get();

            foreach ($live as $charge) {
                $answer = 'Attribution changed from '.($previous?->label() ?? 'nobody').' to '.$to->label().': '.$reason;

                $this->reversals->handle($charge, $answer, $actor);

                $this->charges->handle(
                    case: $locked,
                    kind: $charge->kind,
                    amount: $charge->amount,
                    bearer: $to,
                    reason: $charge->reason.' (re-attributed to '.$to->label().')',
                    actor: $actor,
                    subject: $this->subjectOf($charge),
                    revision: $revision,
                );
            }

            return $locked;
        });

        $return->setRawAttributes($changed->getAttributes(), sync: true);

        if ($previous !== null || $changed->responsible_party === $to) {
            $this->audit->handle(new AuditEntry(
                action: 'order_return.responsibility_changed',
                actorId: $actor->id,
                auditableType: OrderReturn::class,
                auditableId: $changed->id,
                before: ['responsible_party' => $previous?->value],
                after: ['responsible_party' => $to->value, 'reference' => $changed->reference],
                reason: $reason,
                accountId: $changed->business_account_id,
                module: PermissionModule::ReturnCase->value,
            ));
        }

        return $changed;
    }

    /**
     * The subject part of the charge's key, so a re-posting lands on its own key.
     */
    protected function subjectOf(ReturnCharge $charge): string
    {
        // return-charge:{case}:{kind}[:{subject}]:r{n}
        $parts = explode(':', $charge->idempotency_key);
        $subject = array_slice($parts, 3, -1);

        return implode(':', $subject);
    }
}
