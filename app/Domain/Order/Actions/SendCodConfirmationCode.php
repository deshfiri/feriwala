<?php

namespace App\Domain\Order\Actions;

use App\Domain\Account\Exceptions\ResendTooSoon;
use App\Domain\Account\VerificationCodes;
use App\Domain\Order\Enums\OrderStatus;
use App\Domain\Order\Models\Order;
use App\Domain\Website\Actions\PublishWebsiteEvent;
use App\Domain\Website\Enums\WebhookEvent;
use App\Domain\Website\Models\Website;
use App\Integrations\Sms\Contracts\SmsProvider;
use App\Integrations\Sms\Data\SmsMessage;
use App\Support\Localization\Locale;
use Illuminate\Contracts\Translation\Translator;

/**
 * Send a website customer the code that confirms their cash-on-delivery order
 * (contract §6.1.2, §6.2, §18.4, P6-10).
 *
 * The code is issued by the same thing that issues a mobile verification code:
 * held hashed with a short life, a small budget of wrong guesses and a cooldown
 * between sends, and scoped here to **one order** — a code for one order
 * confirms nothing else.
 *
 * The customer has no ERP account, so it goes by SMS to the number the order
 * was placed with, in the language the shop's customer was written to in.
 * Nothing returns the code to the storefront, and nothing writes it down.
 */
class SendCodConfirmationCode
{
    public const PURPOSE = 'cod-order';

    public function __construct(
        protected VerificationCodes $codes,
        protected SmsProvider $sms,
        protected PublishWebsiteEvent $events,
        protected Translator $translator,
    ) {}

    /**
     * @throws ResendTooSoon when the last one was sent moments ago
     */
    public function handle(Order $order, ?Locale $locale = null): void
    {
        if ($order->status !== OrderStatus::CustomerVerificationPending) {
            return;
        }

        $identifier = $this->identifierFor($order);

        if (! $this->codes->canIssue(self::PURPOSE, $identifier)) {
            throw ResendTooSoon::wait($this->codes->secondsUntilResend(self::PURPOSE, $identifier));
        }

        $code = $this->codes->issue(self::PURPOSE, $identifier);
        $locale ??= Locale::English;

        $this->sms->send(new SmsMessage(
            to: (string) ($order->customer['mobile'] ?? ''),
            body: $this->translator->get(
                'sms.templates.cod_confirmation',
                ['code' => $code, 'reference' => $order->reference],
                $locale->value,
            ),
            locale: $locale,
            event: 'cod_confirmation',
        ));

        $this->tellTheShop($order);
    }

    /**
     * Tell the shop its customer has been asked to confirm (contract §7.1).
     *
     * The deadline and nothing else: a storefront showing "confirm your order"
     * needs to know until when, and must never be near the code itself. Asking
     * again before the first delivery has gone out updates that one rather than
     * queueing a second (§7.3).
     */
    protected function tellTheShop(Order $order): void
    {
        /** @var Website|null $website */
        $website = $order->website_id === null ? null : Website::query()->find($order->website_id);

        if ($website === null) {
            return;
        }

        $this->events->handle($website, WebhookEvent::CodConfirmationRequired, [
            'order' => [
                'id' => $order->public_id,
                'reference' => $order->reference,
                'storefront_order_reference' => $order->storefront_order_reference,
                'status' => $order->status->value,
                'confirmation_expires_at' => $order->payment?->expires_at?->toIso8601String(),
            ],
        ], 'order', $order->id);
    }

    /**
     * The code belongs to this order and to nothing else.
     */
    public function identifierFor(Order $order): string
    {
        return $order->public_id;
    }

    public function secondsUntilResend(Order $order): int
    {
        return $this->codes->secondsUntilResend(self::PURPOSE, $this->identifierFor($order));
    }

    public function isPending(Order $order): bool
    {
        return $this->codes->isPending(self::PURPOSE, $this->identifierFor($order));
    }

    /**
     * Forget the code: the order has been confirmed, cancelled or has run out
     * of time, and a code that still works after that is a code that should
     * not (§6.2).
     */
    public function forget(Order $order): void
    {
        $this->codes->forget(self::PURPOSE, $this->identifierFor($order));
    }
}
