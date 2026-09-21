<?php

namespace App\Domain\Order\Actions;

use App\Domain\Account\Exceptions\ResendTooSoon;
use App\Domain\Order\Enums\OrderSource;
use App\Domain\Order\Enums\OrderStatus;
use App\Domain\Order\Exceptions\ConfirmationCodesExhausted;
use App\Domain\Order\Models\Order;
use Throwable;

/**
 * Send again the confirmation codes the SMS provider did not take (§6.2, P6-10).
 *
 * Placing a cash-on-delivery order never fails because the provider is having a
 * bad afternoon, so a customer can be left waiting for a text that never went.
 * This finds exactly those — orders still waiting, whose last code the provider
 * refused or never answered for — and tries once more.
 *
 * **Only failed sends.** An order whose code reached the provider is left
 * alone: resending it automatically would be a fresh set of guesses nobody
 * asked for. Every retry is an ordinary send, so the resend cooldown and the
 * per-order ceiling hold exactly as they do for the customer, and a pass that
 * overlaps another changes nothing the first did not.
 */
class RetryCodConfirmationCodes
{
    public function __construct(
        protected SendCodConfirmationCode $codes,
    ) {}

    /**
     * @return array{retried: int, sent: int}
     */
    public function handle(): array
    {
        $retried = 0;
        $sent = 0;

        Order::query()
            ->where('source', OrderSource::Website)
            ->where('status', OrderStatus::CustomerVerificationPending)
            ->whereHas('payment', fn ($payment) => $payment->whereNull('gateway')->where('expires_at', '>', now()))
            ->orderBy('id')
            ->chunkById(100, function ($orders) use (&$retried, &$sent) {
                foreach ($orders as $order) {
                    if ($this->codes->deliveryState($order) !== SendCodConfirmationCode::DELIVERY_FAILED) {
                        continue;
                    }

                    $retried++;

                    try {
                        $this->codes->handle($order);
                    } catch (ResendTooSoon|ConfirmationCodesExhausted) {
                        // Too soon, or out of codes: next pass, or never.
                        continue;
                    } catch (Throwable) {
                        // Still failing. It stays marked, and is tried again.
                        continue;
                    }

                    if ($this->codes->deliveryState($order) === SendCodConfirmationCode::DELIVERY_SENT) {
                        $sent++;
                    }
                }
            });

        return ['retried' => $retried, 'sent' => $sent];
    }
}
