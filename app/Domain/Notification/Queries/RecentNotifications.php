<?php

namespace App\Domain\Notification\Queries;

use App\Domain\Access\Enums\PermissionAction;
use App\Domain\Access\Enums\PermissionModule;
use App\Domain\Access\PermissionCatalogue;
use App\Domain\Damage\Queries\MyDamages;
use App\Domain\Notification\Data\HeaderNotification;
use App\Domain\Notification\Enums\StaffQueue;
use App\Domain\Order\Models\OrderReturn;
use App\Models\User;
use Illuminate\Notifications\DatabaseNotification;

/**
 * The notifications behind the header bell (§33.2, D20).
 *
 * Scoped to the signed-in user by the relationship itself, which is what keeps
 * §31.3 honest here — there is no account or user parameter to tamper with,
 * because the only way in is through the person Laravel already authenticated.
 *
 * Capped deliberately. The bell is a summary, not an archive: a person with
 * eight hundred notifications should not pay for eight hundred rows on every
 * page load to render a list that shows ten.
 */
class RecentNotifications
{
    /**
     * How many rows the menu shows. Beyond this the answer is a full page, not
     * a longer dropdown.
     */
    public const LIMIT = 10;

    /**
     * The free-text fields an event may carry, in the order they are preferred.
     *
     * An administrator's own words say what to actually do about the
     * notification; the generic line only says what happened.
     *
     * @var array<int, string>
     */
    protected const DETAIL_KEYS = ['instructions', 'feedback', 'note'];

    /**
     * @return array<int, HeaderNotification>
     */
    public function forUser(User $user): array
    {
        return $user->notifications()
            /*
             * A stable tie-break on top of the relation's `created_at desc`.
             * Two notifications written in the same transaction share a
             * timestamp to the second, and without this their order is whatever
             * the database happens to return — so the bell reshuffles between
             * page loads and the tenth row can be shown twice or not at all.
             */
            ->orderBy('id')
            ->limit(self::LIMIT)
            ->get()
            ->map(fn (DatabaseNotification $notification) => $this->present($notification, $user))
            ->all();
    }

    /**
     * How many of this person's notifications are still unread.
     *
     * Counted rather than derived from the capped list above: someone with
     * twelve unread should not be told they have ten.
     */
    public function unreadCountFor(User $user): int
    {
        return $user->unreadNotifications()->count();
    }

    protected function present(DatabaseNotification $notification, ?User $user = null): HeaderNotification
    {
        /** @var array<string, mixed> $data */
        $data = $notification->data;
        $event = is_string($data['event'] ?? null) ? $data['event'] : 'unknown';

        return new HeaderNotification(
            id: $notification->id,
            title: $this->line($event, 'title') ?? $event,
            description: $this->description($event, $data),
            href: $this->href($event, $data, $user),
            createdAt: $notification->created_at?->diffForHumans(),
            createdAtIso: $notification->created_at?->toIso8601String(),
            readAt: $notification->read_at?->toIso8601String(),
        );
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function description(string $event, array $data): ?string
    {
        foreach (self::DETAIL_KEYS as $key) {
            if (filled($data[$key] ?? null) && is_string($data[$key])) {
                return $data[$key];
            }
        }

        return $this->withReference($this->line($event, 'description'), $data);
    }

    /**
     * Appends the submission's own reference — `WDR-…`, `RET-…` — so a reviewer
     * can match the bell entry to a row. Only ever that reference; the stored
     * notification holds nothing else about the submitter.
     *
     * @param  array<string, mixed>  $data
     */
    protected function withReference(?string $description, array $data): ?string
    {
        $reference = $data['reference'] ?? null;

        if (! is_string($reference) || $reference === '') {
            return $description;
        }

        return trim(($description ?? '').' '.__('notification.reference', ['reference' => $reference]));
    }

    /**
     * A translated line for this event, or null when there is none.
     *
     * The whole `events` array is fetched and indexed here rather than asking
     * the translator for `notification.events.{$event}.{$attribute}` directly.
     * Event names contain dots — `account.activated` — and the translator
     * splits on those, so it would look for a nested `account` array that does
     * not exist and quietly return the key. Indexing keeps the lang file keyed
     * by the event name as it is actually written.
     *
     * A missing title falls back to the raw event name at the call site rather
     * than to an empty string. A visible `kyc.deadline_missed` in the bell is an
     * obvious bug someone fixes; a blank row is one nobody notices.
     */
    protected function line(string $event, string $attribute): ?string
    {
        $events = __('notification.events');

        if (! is_array($events)) {
            return null;
        }

        $line = $events[$event][$attribute] ?? null;

        return is_string($line) ? $line : null;
    }

    /**
     * Where the notification takes someone who acts on it.
     *
     * Null is a legitimate answer — "your account was activated" is news, not a
     * task, and a row that looks clickable but goes nowhere is worse than plain
     * text.
     *
     * @param  array<string, mixed>  $data
     */
    protected function href(string $event, array $data = [], ?User $user = null): ?string
    {
        return match ($event) {
            // Straight to the stock in question, where it can be adjusted (§19).
            'inventory.stock_low',
            'inventory.stock_out' => is_string($data['stock_item'] ?? null)
                ? route('admin.inventory.stock.show', $data['stock_item'])
                : route('admin.inventory.stock.index'),

            'kyc.deadline_approaching',
            'kyc.deadline_missed',
            'kyc.update_requested',
            'account.kyc_resubmission_requested' => route('kyc.history'),

            'account.suspended' => route('onboarding.status'),

            /*
             * Security alerts land where they can be acted on. "A new device
             * signed in" is only useful if the next click is the one that
             * changes the password and ends the other sessions.
             */
            'identity.new_device_sign_in',
            'identity.password_changed' => route('security.edit'),

            'decision.return.approved',
            'decision.return.rejected' => is_string($data['case'] ?? null) && $user !== null
                ? $this->caseHref('case.outcome.decision', ['kind' => 'return', 'case' => $data['case']], $user)
                : (is_string($data['website'] ?? null) && is_string($data['order'] ?? null)
                    ? route('websites.orders.show', [$data['website'], $data['order']])
                    : null),

            // A staff member who raised the refund goes to the platform's
            // payments screen; an account's own user to their own receipt.
            'decision.refund.approved',
            'decision.refund.rejected' => ($data['audience'] ?? null) === 'staff'
                ? route('admin.payments.index')
                : (is_string($data['payment'] ?? null)
                    ? route('subscription.receipts.show', $data['payment'])
                    : null),

            default => StaffQueue::fromEvent($event)?->route() ?? $this->caseHref($event, $data, $user),
        };
    }

    /**
     * Where a return, exchange or damage notification opens (D35).
     *
     * Worked out for the person reading, when they read it, and only to a page
     * they can open: staff are sent to the workspace only while they still hold
     * the permission, and a Partner only to their own case — a damage is on their
     * My Damages only while they bear it. Anything else is plain text, never a link
     * that ends in a refusal.
     *
     * @param  array<string, mixed>  $data
     */
    protected function caseHref(string $event, array $data, ?User $user): ?string
    {
        $caseId = $data['case'] ?? null;
        $kind = $data['kind'] ?? null;

        if ($user === null || ! is_string($caseId) || ! in_array($kind, ['return', 'exchange', 'damage'], true)) {
            return null;
        }

        if (str_starts_with($event, 'case.step.')) {
            $module = match ($kind) {
                'damage' => PermissionModule::Damage,
                'exchange' => PermissionModule::Exchange,
                default => PermissionModule::ReturnCase,
            };

            if (! $user->can(PermissionCatalogue::name($module, PermissionAction::View))) {
                return null;
            }

            return $kind === 'damage' ? route('admin.cases.damages.show', $caseId) : route('admin.cases.returns.show', $caseId);
        }

        if (! str_starts_with($event, 'case.outcome.')) {
            return null;
        }

        $account = $user->businessAccount;

        if ($account === null) {
            return null;
        }

        if ($kind === 'damage') {
            return app(MyDamages::class)->find($account, $caseId) === null ? null : route('damages.show', $caseId);
        }

        return OrderReturn::query()->where('public_id', $caseId)->where('business_account_id', $account->id)->exists()
            ? route('cases.show', $caseId)
            : null;
    }
}
