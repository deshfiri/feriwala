<?php

namespace App\Http\Controllers;

use App\Concerns\ResolvesBusinessAccount;
use App\Domain\Account\Actions\ActivateAccount;
use App\Domain\Account\Enums\AccountStatus;
use App\Domain\Account\Models\BusinessAccount;
use App\Domain\Billing\Queries\AccountSpendSummary;
use App\Domain\Wallet\Models\Wallet;
use App\Http\Controllers\Erp\WalletController;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The business ERP's home screen (§33.3).
 *
 * Everything that reaches this page has an **activated** business account —
 * `business.activated` sends an unactivated one to the stepper, and sends
 * platform staff, who have no account at all, to their own queues (D23). So
 * there is no onboarding funnel here and no admin panel: both were tried and
 * both are unreachable by construction.
 *
 * What is left is not one state but six. {@see AccountStatus::isActivated()}
 * admits `PackageRenewalDue`, `PackageExpired`, `LowWalletBalance`,
 * `WalletTopupRequired` and `TemporarilyRestricted` alongside `Active`, and
 * §33.3 names package status and the minimum-balance warning as things the
 * dashboard should carry. An account whose package lapsed yesterday still
 * reaches this page, and telling it so is the most useful thing here.
 *
 * It carries no invitation list, deliberately. Under D1 only someone with no
 * account of their own can accept an invitation, and someone with no account
 * never reaches this page — the §5.4 gate sends them to the invitation itself.
 * A list here could therefore only ever be shown to people who cannot act on it.
 */
class DashboardController extends Controller
{
    use ResolvesBusinessAccount;

    public function __invoke(Request $request, AccountSpendSummary $spend): Response
    {
        $account = $this->businessAccountFor($request);

        return Inertia::render('dashboard', [
            'greeting' => $this->greeting($request->user('web')),
            'standing' => $this->standing($account),
            'walletUsable' => $this->walletUsable($account),

            /*
             * Deferred: the spend chart is below the fold and costs a second
             * query, while the standing above it is what the page is for. The
             * skeleton resolves in its own round trip rather than holding up
             * the whole screen.
             */
            'spend' => Inertia::defer(fn () => $spend->forAccount($account)),
        ]);
    }

    /**
     * Who this is and what time of day it is for them.
     *
     * The period is chosen server-side. A browser clock can be set to anything,
     * and "Good morning" at nine in the evening is the kind of small wrongness
     * that makes an application feel unreliable.
     *
     * @return array{name: string, period: string}
     */
    protected function greeting(?User $user): array
    {
        $hour = (int) Carbon::now()->format('G');

        return [
            // First name only. "Good morning, Mohammad Abdul Karim" is a form
            // field talking, not a greeting.
            'name' => $user === null
                ? ''
                : explode(' ', trim($user->name))[0],

            'period' => match (true) {
                $hour < 12 => 'morning',
                $hour < 17 => 'afternoon',
                default => 'evening',
            },
        ];
    }

    /**
     * What this account could spend right now (§24.2's "usable" figure — the
     * same one {@see WalletController} shows on the dedicated wallet page),
     * not the full balance breakdown that belongs there. A real activation
     * (see {@see ActivateAccount}) always opens one in the same transaction,
     * but a test-built account is not the only account that reaches this
     * page in practice — null here means "nothing to show yet" exactly like
     * the Supplier dashboard's own wallet card, never a hard failure.
     *
     * @return array<string, mixed>|null
     */
    protected function walletUsable(BusinessAccount $account): ?array
    {
        $wallet = Wallet::query()->where('business_account_id', $account->id)->first();

        return $wallet?->usableBalance()->jsonSerialize();
    }

    /**
     * How the account stands, and the one thing to do about it.
     *
     * `needsAttention` is separate from the tone on purpose. Tone decides how
     * the pill is painted; this decides whether the dashboard leads with the
     * status at all. Reading a colour name to make that decision in the front
     * end would put the rule in the wrong place.
     *
     * @return array{status: string, label: string, tone: string, needsAttention: bool, action: array{label: string, href: string}|null}
     */
    protected function standing(BusinessAccount $account): array
    {
        $status = $account->status;

        return [
            'status' => $status->value,
            'label' => $status->label(),
            'tone' => $status->tone(),
            'needsAttention' => $status !== AccountStatus::Active,
            'action' => $this->action($status),
        ];
    }

    /**
     * The single thing to do about a status that is not plain `Active`.
     *
     * Null wherever there is nothing to click. The wallet statuses resolve to
     * null because the wallet screens are not built — a button leading nowhere
     * is worse than a status that simply states the problem, and inventing a
     * route here would only move the failure to the moment someone believed it.
     *
     * @return array{label: string, href: string}|null
     */
    protected function action(AccountStatus $status): ?array
    {
        return match ($status) {
            AccountStatus::PackageRenewalDue,
            AccountStatus::PackageExpired => [
                'label' => __('dashboard.actions.renew_package'),
                'href' => route('packages.index'),
            ],

            default => null,
        };
    }
}
