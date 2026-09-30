<?php

namespace App\Domain\Supplier\Actions;

use App\Domain\Account\Exceptions\ResendTooSoon;
use App\Domain\Account\VerificationCodes;
use App\Domain\Notification\Enums\SmsEvent;
use App\Domain\Notification\Exceptions\SmsEventSwitchedOff;
use App\Domain\Notification\SmsEventSwitch;
use App\Domain\Supplier\Models\Supplier;
use App\Integrations\Sms\Contracts\SmsProvider;
use App\Integrations\Sms\Data\SmsMessage;
use App\Support\Localization\Locale;
use Illuminate\Contracts\Translation\Translator;

/**
 * Issues and sends a Supplier mobile verification code (D25, P13-1).
 *
 * Reuses the shared {@see VerificationCodes} store with a Supplier-specific
 * purpose, so a code issued here can never be replayed against a Client/
 * Partner mobile verification and vice versa — the cache key already includes
 * the purpose string.
 */
class SendSupplierMobileVerificationCode
{
    public const PURPOSE = 'supplier-mobile';

    public function __construct(
        protected VerificationCodes $codes,
        protected SmsProvider $sms,
        protected Translator $translator,
        protected SmsEventSwitch $smsEvents,
    ) {}

    /**
     * @throws ResendTooSoon
     * @throws SmsEventSwitchedOff when an administrator has switched these codes off
     */
    public function handle(Supplier $supplier): void
    {
        // Asked before a code is issued, so a switched-off send spends no
        // code and no cooldown.
        if (! $this->smsEvents->isSwitchedOn(SmsEvent::SupplierMobileVerification->value)) {
            throw new SmsEventSwitchedOff(SmsEvent::SupplierMobileVerification);
        }

        $mobile = (string) $supplier->mobile;

        if (! $this->codes->canIssue(self::PURPOSE, $mobile)) {
            throw ResendTooSoon::wait(
                $this->codes->secondsUntilResend(self::PURPOSE, $mobile)
            );
        }

        $code = $this->codes->issue(self::PURPOSE, $mobile);

        $locale = Locale::parse($supplier->locale);

        $this->sms->send(new SmsMessage(
            to: $mobile,
            body: $this->translator->get(
                'sms.templates.mobile_verification',
                ['code' => $code],
                $locale->value,
            ),
            locale: $locale,
            event: SmsEvent::SupplierMobileVerification->value,
            userId: null,
        ));
    }
}
