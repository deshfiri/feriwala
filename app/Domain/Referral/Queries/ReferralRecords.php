<?php

namespace App\Domain\Referral\Queries;

use App\Domain\Account\Models\BusinessAccount;
use App\Domain\Referral\Enums\CommissionStatus;
use App\Domain\Referral\Enums\RewardType;
use App\Domain\Referral\Models\AccountReferral;
use App\Domain\Referral\Models\ReferralCommission;
use App\Domain\Referral\Models\ReferralQualifyingEvent;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

/**
 * Reading referral commissions and chains (D24, P7-18, P7-44).
 *
 * Two audiences, two shapes. Staff with `referral.view` see the platform: every
 * commission, both ends of it, and chains. An account sees **its own**: its
 * earnings by level, status and date — never whose activation paid a deeper
 * level — and its direct referrals, never anything below them.
 */
class ReferralRecords
{
    /**
     * Every commission on the platform, newest first, filtered.
     *
     * @param  array{account?: string|null, level?: int|null, status?: string|null, trigger?: string|null, from?: string|null, to?: string|null}  $filters
     * @return LengthAwarePaginator<int, ReferralCommission>
     */
    public function platform(array $filters, int $perPage = 25): LengthAwarePaginator
    {
        $account = $filters['account'] ?? null;

        /** @var LengthAwarePaginator<int, ReferralCommission> $page */
        $page = ReferralCommission::query()
            ->with(['beneficiary:id,public_id,name', 'sourceAccount:id,public_id,name', 'event:id,public_id,trigger_event'])
            ->when($account !== null && $account !== '', fn (Builder $query) => $query->where(fn (Builder $either) => $either
                ->whereHas('beneficiary', fn (Builder $who) => $this->matching($who, (string) $account))
                ->orWhereHas('sourceAccount', fn (Builder $who) => $this->matching($who, (string) $account))))
            ->when(($filters['level'] ?? null) !== null, fn (Builder $query) => $query->where('level', $filters['level']))
            ->when(($filters['status'] ?? null) !== null, fn (Builder $query) => $query->where('status', $filters['status']))
            ->when(($filters['trigger'] ?? null) !== null, fn (Builder $query) => $query
                ->whereHas('event', fn (Builder $event) => $event->where('trigger_event', $filters['trigger'])))
            ->when(($filters['from'] ?? null) !== null, fn (Builder $query) => $query->where('created_at', '>=', CarbonImmutable::parse((string) $filters['from'])->startOfDay()))
            ->when(($filters['to'] ?? null) !== null, fn (Builder $query) => $query->where('created_at', '<=', CarbonImmutable::parse((string) $filters['to'])->endOfDay()))
            ->orderByDesc('id')
            ->paginate($perPage)
            ->withQueryString();

        return $page;
    }

    /**
     * One commission as staff read it.
     *
     * @return array<string, mixed>
     */
    public function row(ReferralCommission $commission): array
    {
        return [
            'id' => $commission->public_id,
            'event' => $commission->event->public_id,
            'trigger_label' => __('referral.triggers.'.$commission->event->trigger_event->value),
            'source' => ['id' => $commission->sourceAccount->public_id, 'name' => $commission->sourceAccount->name],
            'beneficiary' => ['id' => $commission->beneficiary->public_id, 'name' => $commission->beneficiary->name],
            ...$this->earning($commission),
            'reversal' => $commission->reversed_at === null ? null : [
                'at' => $commission->reversed_at->toIso8601String(),
                'cause' => $commission->reversal_cause?->value,
                'cause_label' => $commission->reversal_cause === null ? null : __('referral.causes.'.$commission->reversal_cause->value),
                'reason' => $commission->reversal_reason,
            ],
        ];
    }

    /**
     * The part of a commission its own beneficiary may see.
     *
     * @return array<string, mixed>
     */
    public function earning(ReferralCommission $commission): array
    {
        return [
            'id' => $commission->public_id,
            'level' => $commission->level,
            'is_joining_reward' => $commission->isJoiningReward(),
            'amount' => $commission->amount->jsonSerialize(),
            'rule' => $this->rule($commission),
            'status' => $commission->status->value,
            'status_label' => __('referral.statuses.'.$commission->status->value),
            'skip_reason' => $commission->skip_reason === null ? null : __('referral.skip_reasons.'.$commission->skip_reason->value),
            'capped' => $commission->capped,
            'available_at' => $commission->available_at->toIso8601String(),
            'paid_at' => $commission->paid_at?->toIso8601String(),
            'created_at' => $commission->created_at->toIso8601String(),
        ];
    }

    /**
     * One qualifying event, from its trigger to every beneficiary.
     *
     * @return array<string, mixed>
     */
    public function event(ReferralQualifyingEvent $event): array
    {
        $event->loadMissing(['sourceAccount', 'payment', 'plan', 'commissions.beneficiary', 'commissions.sourceAccount', 'commissions.event']);

        $names = BusinessAccount::query()
            ->whereIn('public_id', array_filter(array_column($event->chain, 'account')))
            ->pluck('name', 'public_id');

        return [
            'id' => $event->public_id,
            'trigger_label' => __('referral.triggers.'.$event->trigger_event->value),
            'occurred_at' => $event->occurred_at->toIso8601String(),
            'status' => $event->status,
            'source' => ['id' => $event->sourceAccount->public_id, 'name' => $event->sourceAccount->name],
            'payment' => $event->payment?->reference,
            'base' => $event->commission_base->jsonSerialize(),
            'plan' => [
                'id' => $event->plan->public_id,
                'max_depth' => $event->plan->max_depth,
                'effective_from' => $event->plan->effective_from->toIso8601String(),
            ],
            'chain' => array_map(fn (array $level) => [
                'level' => $level['level'],
                'account' => $level['account'] === null ? null : ['id' => $level['account'], 'name' => $names[$level['account']] ?? null],
                'outcome' => $level['outcome'],
                'outcome_label' => __('referral.outcomes.'.$level['outcome']),
            ], $event->chain),
            'reversed_at' => $event->reversed_at?->toIso8601String(),
            'reversal_reason' => $event->reversal_reason,
            'commissions' => $event->commissions->map(fn (ReferralCommission $commission) => [
                ...$this->row($commission),
                'can_reverse' => in_array($commission->status, [CommissionStatus::Pending, CommissionStatus::Paid, CommissionStatus::ReversalOwed], true),
            ])->all(),
        ];
    }

    /**
     * An account's place in the hierarchy, for staff: its referrer, its chain
     * upward, and its direct referrals.
     *
     * @return array<string, mixed>
     */
    public function chain(BusinessAccount $account, ReferralHierarchy $hierarchy, int $depth = 30): array
    {
        /** @var AccountReferral|null $link */
        $link = AccountReferral::query()->with('attachedBy:id,name')->where('referred_account_id', $account->id)->first();

        $ancestors = $hierarchy->ancestors($account->id, $depth);
        $accounts = BusinessAccount::query()->whereIn('id', array_values($ancestors))->get()->keyBy('id');

        $direct = AccountReferral::query()
            ->with('referred:id,public_id,name,status')
            ->where('referrer_account_id', $account->id)
            ->orderByDesc('id')
            ->limit(50)
            ->get();

        return [
            'account' => $this->account($account),
            'link' => $link === null ? null : [
                'attached_via' => $link->attached_via->value,
                'attached_by' => $link->attachedBy?->name,
                'reason' => $link->reason,
                'locked' => $link->isLocked(),
                'since' => $link->created_at->toIso8601String(),
            ],
            'upline' => $this->upline($ancestors, $accounts),
            'direct_count' => AccountReferral::query()->where('referrer_account_id', $account->id)->count(),
            'direct' => $direct->map(fn (AccountReferral $referral) => [
                ...$this->account($referral->referred),
                'since' => $referral->created_at->toIso8601String(),
                'locked' => $referral->isLocked(),
            ])->all(),
        ];
    }

    /**
     * @param  array<int, int>  $ancestors  level => account id
     * @param  Collection<int, BusinessAccount>  $accounts
     * @return list<array{level: int, id: string, name: string, status: string, status_label: string}>
     */
    protected function upline(array $ancestors, Collection $accounts): array
    {
        $upline = [];

        foreach ($ancestors as $level => $id) {
            $account = $accounts->get($id);

            if ($account instanceof BusinessAccount) {
                $upline[] = ['level' => $level, ...$this->account($account)];
            }
        }

        return $upline;
    }

    /**
     * @return array{id: string, name: string, status: string, status_label: string}
     */
    protected function account(BusinessAccount $account): array
    {
        return [
            'id' => $account->public_id,
            'name' => $account->name,
            'status' => $account->status->value,
            'status_label' => $account->status->label(),
        ];
    }

    /**
     * The rule that was applied, read from the commission's own snapshot.
     *
     * @return array{type: string, percent: string|null, amount: array<string, mixed>|null}
     */
    protected function rule(ReferralCommission $commission): array
    {
        $snapshot = $commission->rule_snapshot;
        $type = RewardType::from($snapshot['type']);
        $rate = $snapshot['rate_bps'] ?? null;

        return [
            'type' => $type->value,
            'percent' => $type === RewardType::Percentage && $rate !== null
                ? rtrim(rtrim(sprintf('%d.%02d', intdiv((int) $rate, 100), (int) $rate % 100), '0'), '.')
                : null,
            'amount' => $type === RewardType::Fixed ? ($snapshot['amount'] ?? null) : null,
        ];
    }

    /**
     * @param  Builder<Model>  $query
     */
    protected function matching(Builder $query, string $search): void
    {
        $query->where(fn (Builder $either) => $either
            ->where('public_id', $search)
            ->orWhere('name', 'ilike', '%'.addcslashes($search, '%_\\').'%'));
    }
}
