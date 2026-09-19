<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Access\Enums\PermissionAction;
use App\Domain\Access\Enums\PermissionModule;
use App\Domain\Access\Exceptions\SensitiveActionRefused;
use App\Domain\Access\PermissionCatalogue;
use App\Domain\Referral\Actions\ReverseReferralByStaff;
use App\Domain\Referral\Enums\CommissionStatus;
use App\Domain\Referral\Enums\ReferralTrigger;
use App\Domain\Referral\Enums\ReversalCause;
use App\Domain\Referral\Exceptions\ReferralRefused;
use App\Domain\Referral\Models\ReferralCommission;
use App\Domain\Referral\Models\ReferralQualifyingEvent;
use App\Domain\Referral\Queries\ReferralRecords;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Platform-wide referral commissions, from each event to every beneficiary
 * (D24, P7-44).
 *
 * Seen with `referral.view`. Taking a commission back needs
 * `referral.reverse_transaction` and the §32.2 escalation, enforced by the
 * action; the route asks for the password first.
 */
class ReferralCommissionController extends Controller
{
    public function __construct(
        protected ReferralRecords $records,
    ) {}

    public function index(Request $request): Response
    {
        Gate::authorize($this->view());

        $filters = [
            // The table's search box: a business on either end of a commission.
            'account' => $request->string('search')->toString() ?: null,
            'level' => $request->filled('level') ? max(0, min(100, $request->integer('level'))) : null,
            'status' => in_array($request->string('status')->toString(), CommissionStatus::values(), true) ? $request->string('status')->toString() : null,
            'trigger' => in_array($request->string('trigger')->toString(), ReferralTrigger::values(), true) ? $request->string('trigger')->toString() : null,
            'from' => $this->date($request->string('from')->toString()),
            'to' => $this->date($request->string('to')->toString()),
        ];

        return Inertia::render('admin/referral-commissions/index', [
            'commissions' => $this->records->platform($filters)->through(fn (ReferralCommission $commission) => $this->records->row($commission)),
            'filters' => $filters,
            'statuses' => array_map(fn (CommissionStatus $status) => [
                'value' => $status->value,
                'label' => __('referral.statuses.'.$status->value),
            ], CommissionStatus::cases()),
            'triggers' => array_map(fn (ReferralTrigger $trigger) => [
                'value' => $trigger->value,
                'label' => __('referral.triggers.'.$trigger->value),
            ], ReferralTrigger::cases()),
        ]);
    }

    public function event(Request $request, string $event): Response
    {
        Gate::authorize($this->view());

        $canReverse = $request->user()?->can(PermissionCatalogue::name(PermissionModule::Referral, PermissionAction::ReverseTransaction)) ?? false;
        $confirmed = time() - (int) $request->session()->get('auth.password_confirmed_at', 0) < (int) config('auth.password_timeout', 10800);

        // Confirming the password brings the person back here, to the forms —
        // not to the reversal endpoint, which only takes a submission.
        if ($canReverse && ! $confirmed) {
            redirect()->setIntendedUrl($request->fullUrl());
        }

        return Inertia::render('admin/referral-commissions/event', [
            'event' => $this->records->event($this->findEvent($event)),
            'can_reverse' => $canReverse,
            'password_confirmed' => $confirmed,
            'causes' => array_map(fn (ReversalCause $cause) => [
                'value' => $cause->value,
                'label' => __('referral.causes.'.$cause->value),
            ], ReversalCause::manual()),
        ]);
    }

    public function reverseCommission(Request $request, string $commission, ReverseReferralByStaff $reverse): RedirectResponse
    {
        $validated = $this->validateReversal($request);

        /** @var ReferralCommission|null $record */
        $record = ReferralCommission::query()->where('public_id', $commission)->first();

        abort_if($record === null, 404);

        try {
            $reverse->commission($record, $this->person($request), ReversalCause::from($validated['cause']), $validated['reason'], passwordConfirmed: true);
        } catch (ReferralRefused|SensitiveActionRefused $refused) {
            throw ValidationException::withMessages(['reason' => $refused->getMessage()]);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('referral.flash.commission_reversed')]);

        return to_route('admin.referral-events.show', $record->event->public_id);
    }

    public function reverseEvent(Request $request, string $event, ReverseReferralByStaff $reverse): RedirectResponse
    {
        $validated = $this->validateReversal($request);
        $record = $this->findEvent($event);

        try {
            $reverse->event($record, $this->person($request), ReversalCause::from($validated['cause']), $validated['reason'], passwordConfirmed: true);
        } catch (SensitiveActionRefused $refused) {
            throw ValidationException::withMessages(['reason' => $refused->getMessage()]);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('referral.flash.event_reversed')]);

        return to_route('admin.referral-events.show', $record->public_id);
    }

    /**
     * @return array{cause: string, reason: string}
     */
    protected function validateReversal(Request $request): array
    {
        Gate::authorize(PermissionCatalogue::name(PermissionModule::Referral, PermissionAction::ReverseTransaction));

        /** @var array{cause: string, reason: string} $validated */
        $validated = $request->validate([
            'cause' => ['required', Rule::in(array_map(fn (ReversalCause $cause) => $cause->value, ReversalCause::manual()))],
            'reason' => ['required', 'string', 'min:10', 'max:500'],
        ]);

        return $validated;
    }

    protected function findEvent(string $publicId): ReferralQualifyingEvent
    {
        /** @var ReferralQualifyingEvent|null $event */
        $event = ReferralQualifyingEvent::query()->where('public_id', $publicId)->first();

        abort_if($event === null, 404);

        return $event;
    }

    protected function date(string $value): ?string
    {
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1 ? $value : null;
    }

    protected function view(): string
    {
        return PermissionCatalogue::name(PermissionModule::Referral, PermissionAction::View);
    }

    protected function person(Request $request): User
    {
        $user = $request->user();

        abort_if(! $user instanceof User, 403);

        return $user;
    }
}
