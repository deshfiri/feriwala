<?php

namespace App\Domain\Account\Actions;

use App\Domain\Account\Exceptions\ResendTooSoon;
use App\Domain\Account\VerificationCodes;
use App\Integrations\Sms\Contracts\SmsProvider;
use App\Integrations\Sms\Data\SmsMessage;
use App\Models\User;
use App\Support\Localization\Locale;
use Illuminate\Contracts\Translation\Translator;

/**
 * Issues and sends a mobile verification code (§5.1).
 *
 * Sending is throttled: without a cooldown, "resend" becomes a way to send
 * someone dozens of texts, at Feriwala's cost, from a form that needs no login.
 */
class SendMobileVerificationCode
{
    public const PURPOSE = 'mobile';

    public function __construct(
        protected VerificationCodes $codes,
        protected SmsProvider $sms,
        protected Translator $translator,
    ) {}

    /**
     * @throws ResendTooSoon
     */
    public function handle(User $user): void
    {
        $mobile = (string) $user->mobile;

        if (! $this->codes->canIssue(self::PURPOSE, $mobile)) {
            throw ResendTooSoon::wait(
                $this->codes->secondsUntilResend(self::PURPOSE, $mobile)
            );
        }

        $code = $this->codes->issue(self::PURPOSE, $mobile);

        $locale = Locale::parse($user->locale);

        $this->sms->send(new SmsMessage(
            to: $mobile,
            // Sent in the user's own language — a Bangla-speaking user should
            // not have to read an English SMS to finish signing up (D6).
            body: $this->translator->get(
                'sms.mobile_verification',
                ['code' => $code],
                $locale->value,
            ),
            locale: $locale,
            event: 'mobile_verification',
            userId: $user->id,
        ));
    }
}
