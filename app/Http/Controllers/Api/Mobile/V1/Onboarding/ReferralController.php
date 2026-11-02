<?php

namespace App\Http\Controllers\Api\Mobile\V1\Onboarding;

use App\Concerns\ResolvesBusinessAccount;
use App\Domain\Account\Enums\AccountPermission;
use App\Domain\Account\Enums\AccountStatus;
use App\Domain\Account\Models\BusinessAccount;
use App\Domain\Referral\Enums\CommissionStatus;
use App\Domain\Referral\Models\AccountReferral;
use App\Domain\Referral\Models\ReferralCommission;
use App\Domain\Referral\Queries\ReferralRecords;
use App\Domain\Referral\ReferralCode;
use App\Http\Controllers\Api\Mobile\V1\MobileController;
use App\Http\Resources\Mobile\MoneyResource;
use App\Models\User;
use App\Support\Money\Currency;
use App\Support\Money\Money;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * A business's own referrals (§25.1, D24).
 *
 * Self-scoped by construction: everything is read through the signed-in
 * person's own business. Shows the directly referred businesses by name and a
 * plain state, never their KYC, wallet or anything below them, and the
 * business's own earnings.
 */
class ReferralController extends MobileController
{
    use ResolvesBusinessAccount;

    /**
     * Referrals
     *
     * The business's referral code and link, a summary, the businesses it referred
     * directly and its own earnings, 20 to a page. Page the two lists independently
     * with `referrals_page` and `earnings_page`. Activated accounts only. Requires
     * the account permission to view referrals (`403` otherwise).
     *
     * @response array{code: string|null, link: string|null, summary: array{direct: int, direct_active: int, paid: MoneyResource, pending: MoneyResource, reversed: MoneyResource}, direct: array{data: list<array{id: string, name: string, state: string, since: string}>, meta: array{current_page: int, last_page: int, per_page: int, total: int}}, earnings: array{data: list<array<string, mixed>>, meta: array{current_page: int, last_page: int, per_page: int, total: int}}}
     */
    public function index(Request $request, ReferralRecords $records, ReferralCode $codes): JsonResponse
    {
        $account = $this->businessAccountFor($request);
        $person = $request->user();

        abort_if(! $person instanceof User || ! $person->hasAccountPermission($account, AccountPermission::ViewReferrals), 403);

        // A code only an active business may use (§25.1).
        $code = $account->canTransact() && $account->owner !== null ? $codes->issueTo($account->owner) : null;

        $sum = fn (CommissionStatus $status) => new MoneyResource(Money::fromDecimal(
            (string) ReferralCommission::query()
                ->where('beneficiary_account_id', $account->id)
                ->where('status', $status->value)
                ->sum('amount'),
            Currency::BDT,
        ));

        $direct = AccountReferral::query()
            ->with('referred:id,public_id,name,status')
            ->where('referrer_account_id', $account->id)
            ->orderByDesc('id')
            ->paginate(20, pageName: 'referrals_page');

        $earnings = ReferralCommission::query()
            ->where('beneficiary_account_id', $account->id)
            ->where('status', '!=', CommissionStatus::Skipped->value)
            ->orderByDesc('id')
            ->paginate(20, pageName: 'earnings_page');

        return new JsonResponse([
            'code' => $code,
            'link' => $code !== null ? route('register', ['ref' => $code]) : null,
            'summary' => [
                'direct' => AccountReferral::query()->where('referrer_account_id', $account->id)->count(),
                'direct_active' => AccountReferral::query()
                    ->where('referrer_account_id', $account->id)
                    ->whereHas('referred', fn ($referred) => $referred->where('status', AccountStatus::Active->value))
                    ->count(),
                'paid' => $sum(CommissionStatus::Paid),
                'pending' => $sum(CommissionStatus::Pending),
                'reversed' => $sum(CommissionStatus::Reversed),
            ],
            'direct' => $this->page($direct, fn (AccountReferral $referral) => [
                'id' => $referral->referred->public_id,
                'name' => $referral->referred->name,
                'state' => $this->state($referral->referred),
                'since' => $referral->created_at->toIso8601String(),
            ]),
            'earnings' => $this->page($earnings, fn (ReferralCommission $commission) => $records->earning($commission)),
        ]);
    }

    /**
     * @template T
     *
     * @param  LengthAwarePaginator<int, T>  $paginator
     * @param  callable(T): array<string, mixed>  $map
     * @return array{data: list<array<string, mixed>>, meta: array{current_page: int, last_page: int, per_page: int, total: int}}
     */
    protected function page(LengthAwarePaginator $paginator, callable $map): array
    {
        return [
            'data' => array_values(array_map($map, $paginator->items())),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
        ];
    }

    /**
     * A referred business's state in three words: `active`, `joining` or `inactive`.
     */
    protected function state(BusinessAccount $account): string
    {
        return match (true) {
            $account->status === AccountStatus::Active => 'active',
            $account->status->isOnboarding() => 'joining',
            default => 'inactive',
        };
    }
}
