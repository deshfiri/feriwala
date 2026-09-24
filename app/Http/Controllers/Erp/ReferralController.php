<?php

namespace App\Http\Controllers\Erp;

use App\Concerns\ResolvesBusinessAccount;
use App\Domain\Account\Enums\AccountPermission;
use App\Domain\Account\Enums\AccountStatus;
use App\Domain\Account\Models\BusinessAccount;
use App\Domain\Referral\Enums\CommissionStatus;
use App\Domain\Referral\Models\AccountReferral;
use App\Domain\Referral\Models\ReferralCommission;
use App\Domain\Referral\Queries\ReferralRecords;
use App\Domain\Referral\ReferralCode;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\Money\Currency;
use App\Support\Money\Money;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * A business's own referrals (§25.1, D24, P7-10, P7-18).
 *
 * **Self-scoped by construction**: everything is read through the signed-in
 * person's own business, and there is no identifier in the URL to change. It
 * shows the business's code and link, the businesses it referred **directly**
 * — by name and a plain state, never their KYC, wallet or anything below them —
 * and its own earnings by level, status and date. A level-2 earning never says
 * whose activation paid it.
 */
class ReferralController extends Controller
{
    use ResolvesBusinessAccount;

    public function index(Request $request, ReferralRecords $records, ReferralCode $codes): Response
    {
        $account = $this->businessAccountFor($request);
        $person = $request->user();

        abort_if(! $person instanceof User || ! $person->hasAccountPermission($account, AccountPermission::ViewReferrals), 403);

        // A code only an active business may use (§25.1). Registration issues
        // every code; an owner created another way gets theirs here, once.
        $code = $account->canTransact() && $account->owner !== null ? $codes->issueTo($account->owner) : null;
        $currency = Currency::BDT;

        $sum = fn (CommissionStatus $status) => Money::fromDecimal(
            (string) ReferralCommission::query()
                ->where('beneficiary_account_id', $account->id)
                ->where('status', $status->value)
                ->sum('amount'),
            $currency,
        )->jsonSerialize();

        $direct = AccountReferral::query()
            ->with('referred:id,public_id,name,status')
            ->where('referrer_account_id', $account->id)
            ->orderByDesc('id')
            ->paginate(20, pageName: 'referrals')
            ->withQueryString();

        $earnings = ReferralCommission::query()
            ->where('beneficiary_account_id', $account->id)
            ->where('status', '!=', CommissionStatus::Skipped->value)
            ->orderByDesc('id')
            ->paginate(20, pageName: 'earnings')
            ->withQueryString();

        return Inertia::render('referrals/index', [
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
            'direct' => $direct->through(fn (AccountReferral $referral) => [
                'id' => $referral->referred->public_id,
                'name' => $referral->referred->name,
                'state' => $this->state($referral->referred),
                'since' => $referral->created_at->toIso8601String(),
            ]),
            'earnings' => $earnings->through(fn (ReferralCommission $commission) => $records->earning($commission)),
        ]);
    }

    /**
     * A referred business's state in three words: nothing about its KYC, its
     * payments or its standing beyond whether it is trading.
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
