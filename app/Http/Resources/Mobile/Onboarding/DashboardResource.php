<?php

namespace App\Http\Resources\Mobile\Onboarding;

use App\Domain\Account\Enums\AccountStatus;
use App\Domain\Account\Models\BusinessAccount;
use App\Domain\Wallet\Models\Wallet;
use App\Http\Resources\Mobile\MoneyResource;
use App\Models\User;
use App\Support\Money\Money;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Carbon;

/**
 * What the home screen shows (§33.3): who this is, how the account stands, what
 * it could spend now and what it has spent over the last months.
 *
 * @property array{account: BusinessAccount, user: User, spend: array<string, mixed>} $resource
 */
class DashboardResource extends JsonResource
{
    /**
     * @return array{
     *     greeting: array{name: string, period: string},
     *     standing: array{status: string, label: string, tone: string, needs_attention: bool, action: array{label: string, screen: string}|null},
     *     wallet_usable: MoneyResource|null,
     *     spend: array{total: MoneyResource, months: int, series: list<array{key: string, label: string, points: list<array{label: string, formatted: string}>}>, breakdown: list<array{key: string, label: string, formatted: string}>}
     * }
     */
    public function toArray(Request $request): array
    {
        /** @var BusinessAccount $account */
        $account = $this->resource['account'];
        $status = $account->status;
        $spend = $this->resource['spend'];

        $wallet = Wallet::query()->where('business_account_id', $account->id)->first();

        return [
            'greeting' => [
                /** First name only. */
                'name' => explode(' ', trim($this->resource['user']->name))[0],
                /** `morning`, `afternoon` or `evening`, chosen on the server. */
                'period' => match (true) {
                    (int) Carbon::now()->format('G') < 12 => 'morning',
                    (int) Carbon::now()->format('G') < 17 => 'afternoon',
                    default => 'evening',
                },
            ],
            'standing' => [
                'status' => $status->value,
                'label' => $status->label(),
                'tone' => $status->tone(),
                /** Whether the screen should lead with the status. */
                'needs_attention' => $status !== AccountStatus::Active,
                /** The one thing to do about it, or null. `screen` is `renewal` today. */
                'action' => match ($status) {
                    AccountStatus::PackageRenewalDue,
                    AccountStatus::PackageExpired => [
                        'label' => __('dashboard.actions.renew_package'),
                        'screen' => 'renewal',
                    ],
                    default => null,
                },
            ],
            /** What the account could spend right now. Null before a wallet exists. */
            'wallet_usable' => MoneyResource::nullable($wallet?->usableBalance()),
            'spend' => [
                /** @var Money $total */
                'total' => new MoneyResource($spend['total']),
                /** How many months the series covers. */
                'months' => $spend['months'],
                'series' => array_map(fn (array $series) => [
                    'key' => $series['key'],
                    'label' => $series['label'],
                    'points' => array_map(fn (array $point) => [
                        'label' => $point['label'],
                        'formatted' => $point['formatted'],
                    ], $series['points']),
                ], $spend['series']),
                /** Spend split by purpose, largest first. */
                'breakdown' => array_map(fn (array $slice) => [
                    'key' => $slice['key'],
                    'label' => $slice['label'],
                    'formatted' => $slice['formatted'],
                ], $spend['breakdown']),
            ],
        ];
    }
}
