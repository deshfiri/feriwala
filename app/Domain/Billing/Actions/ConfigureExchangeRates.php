<?php

namespace App\Domain\Billing\Actions;

use App\Domain\Audit\Actions\RecordAuditLog;
use App\Domain\Audit\Data\AuditEntry;
use App\Domain\Billing\ExchangeRates;
use App\Domain\Settings\Enums\SettingType;
use App\Domain\Settings\SettingsRepository;
use App\Domain\Website\Jobs\AnnouncePaymentMethodsChanged;
use App\Integrations\Payment\PaymentGatewayManager;
use App\Models\User;
use App\Support\Money\Currency;
use InvalidArgumentException;

/**
 * Sets the rates at which Taka payments are taken through international
 * gateways (D4, amended).
 *
 * A rate is the administrator's own decision and nothing fetches one. Setting
 * a rate is what makes a gateway that cannot take Taka available for Taka
 * payments, so it is audited as the payment-affecting change it is, and
 * storefronts are told when it changes whether online payment is possible.
 *
 * A blank value removes that currency's rate, which takes the gateways that
 * depend on it off the Taka checkout again. Existing payments are untouched:
 * each carries the rate it was quoted at.
 */
class ConfigureExchangeRates
{
    public function __construct(
        protected SettingsRepository $settings,
        protected PaymentGatewayManager $gateways,
        protected RecordAuditLog $audit,
    ) {}

    /**
     * @param  array<string, string|null>  $rates  Taka per one unit, keyed by currency code
     *
     * @throws InvalidArgumentException when a currency cannot have a rate or a rate is not usable
     */
    public function handle(User $actor, array $rates): void
    {
        $allowed = array_map(fn (Currency $currency) => $currency->value, ExchangeRates::convertible());
        $before = $this->onlineCurrencies();
        $previous = app(ExchangeRates::class)->all();

        $next = [];

        foreach ($rates as $code => $value) {
            if (! in_array($code, $allowed, true)) {
                throw new InvalidArgumentException("[{$code}] cannot have an exchange rate.");
            }

            $value = is_string($value) ? trim($value) : '';

            if ($value === '') {
                continue;
            }

            if (! ExchangeRates::isValidRate($value)) {
                throw new InvalidArgumentException(
                    "The rate for {$code} must be a number above zero with at most six decimal places."
                );
            }

            $next[$code] = $value;
        }

        $this->settings->define(
            ExchangeRates::SETTING,
            'payment',
            SettingType::Json,
            [],
            label: 'Exchange rates for international gateways (Taka per unit)',
        );

        $this->settings->set(ExchangeRates::SETTING, $next, $actor->id);

        $this->audit->handle(new AuditEntry(
            action: 'payment.exchange_rates_updated',
            actorId: $actor->id,
            before: ['rates' => $previous],
            after: ['rates' => $next],
            module: 'payment',
            isSensitive: true,
        ));

        $this->announceIfChanged($before);
    }

    /**
     * The currencies a customer can pay online in right now.
     *
     * @return list<string>
     */
    protected function onlineCurrencies(): array
    {
        return array_values(array_map(
            fn (Currency $currency) => $currency->value,
            array_filter(Currency::cases(), fn (Currency $currency) => $this->gateways->availableFor($currency) !== []),
        ));
    }

    /**
     * @param  list<string>  $before
     */
    protected function announceIfChanged(array $before): void
    {
        $after = $this->onlineCurrencies();
        $changed = array_values(array_unique([...array_diff($before, $after), ...array_diff($after, $before)]));

        if ($changed !== []) {
            AnnouncePaymentMethodsChanged::dispatch($changed)->afterCommit();
        }
    }
}
