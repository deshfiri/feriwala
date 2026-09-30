<?php

namespace App\Domain\Order\Actions;

use App\Domain\Account\Exceptions\ResendTooSoon;
use App\Domain\Account\VerificationCodes;
use App\Domain\Inventory\ReservationWindows;
use App\Domain\Notification\Enums\SmsEvent;
use App\Domain\Notification\SmsEventSwitch;
use App\Domain\Order\Enums\OrderStatus;
use App\Domain\Order\Exceptions\ConfirmationCodesExhausted;
use App\Domain\Order\Models\Order;
use App\Domain\Website\Actions\PublishWebsiteEvent;
use App\Domain\Website\Enums\WebhookEvent;
use App\Domain\Website\Models\Website;
use App\Integrations\Sms\Contracts\SmsProvider;
use App\Integrations\Sms\Data\SmsMessage;
use App\Support\Localization\Locale;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Contracts\Translation\Translator;
use Throwable;

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

    /**
     * Codes one order may be sent, first and resends together.
     *
     * Each code has five guesses; without a ceiling on codes, "resend" is an
     * unlimited supply of guesses at a million possibilities, and an unlimited
     * supply of texts at Feriwala's cost to whoever the number belongs to.
     */
    public const MAX_SENDS = 5;

    /** Whether the last code reached the provider. */
    public const DELIVERY_SENT = 'sent';

    public const DELIVERY_FAILED = 'failed';

    public function __construct(
        protected VerificationCodes $codes,
        protected SmsProvider $sms,
        protected PublishWebsiteEvent $events,
        protected Translator $translator,
        protected Cache $cache,
        protected SmsEventSwitch $smsEvents,
    ) {}

    /**
     * @throws ResendTooSoon when the last one was sent moments ago
     * @throws ConfirmationCodesExhausted when this order has had every code it may
     */
    public function handle(Order $order, ?Locale $locale = null): void
    {
        if ($order->status !== OrderStatus::CustomerVerificationPending) {
            return;
        }

        /*
         * Switched off under Admin → SMS: send nothing, and spend none of the
         * order's codes or its cooldown. Nothing is raised — this runs inside
         * placing an order through the frozen storefront API, which has no
         * error to say it with. The order waits unconfirmed and expires on the
         * usual schedule; the admin screen warns of exactly that.
         */
        if (! $this->smsEvents->isSwitchedOn(SmsEvent::CodConfirmation->value)) {
            return;
        }

        $identifier = $this->identifierFor($order);

        if ($this->sendsUsed($order) >= self::MAX_SENDS) {
            throw new ConfirmationCodesExhausted;
        }

        if (! $this->codes->canIssue(self::PURPOSE, $identifier)) {
            throw ResendTooSoon::wait($this->codes->secondsUntilResend(self::PURPOSE, $identifier));
        }

        $code = $this->codes->issue(self::PURPOSE, $identifier);
        $locale ??= Locale::English;

        // Counted before the provider is asked: a send that fails still spent
        // one of the order's codes, or a failing provider would be a way round
        // the ceiling.
        $this->cache->put($this->sendsKey($order), $this->sendsUsed($order) + 1, $this->memory());

        try {
            $result = $this->sms->send(new SmsMessage(
                to: (string) ($order->customer['mobile'] ?? ''),
                body: $this->translator->get(
                    'sms.templates.cod_confirmation',
                    ['code' => $code, 'reference' => $order->reference],
                    $locale->value,
                ),
                locale: $locale,
                event: SmsEvent::CodConfirmation->value,
            ));
        } catch (Throwable $exception) {
            $this->recordDelivery($order, self::DELIVERY_FAILED);

            throw $exception;
        }

        $this->recordDelivery($order, $result->accepted ? self::DELIVERY_SENT : self::DELIVERY_FAILED);

        $this->tellTheShop($order);
    }

    /**
     * Whether the last code reached the SMS provider — `sent`, `failed`, or
     * null when none has been tried. Never the code, never the message.
     */
    public function deliveryState(Order $order): ?string
    {
        $state = $this->cache->get($this->deliveryKey($order));

        return is_string($state) ? $state : null;
    }

    public function sendsUsed(Order $order): int
    {
        return (int) $this->cache->get($this->sendsKey($order), 0);
    }

    protected function recordDelivery(Order $order, string $state): void
    {
        $this->cache->put($this->deliveryKey($order), $state, $this->memory());
    }

    /**
     * Kept for the longest a confirmation window can be (a week), so the
     * ceiling holds for as long as the order can still be confirmed.
     */
    protected function memory(): int
    {
        return ReservationWindows::MAXIMUM_COD_HOURS * 3600;
    }

    protected function sendsKey(Order $order): string
    {
        return 'cod-confirmation:sends:'.$order->public_id;
    }

    protected function deliveryKey(Order $order): string
    {
        return 'cod-confirmation:delivery:'.$order->public_id;
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
