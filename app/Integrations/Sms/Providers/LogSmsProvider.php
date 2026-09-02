<?php

namespace App\Integrations\Sms\Providers;

use App\Integrations\Sms\Contracts\SmsProvider;
use App\Integrations\Sms\Data\SmsMessage;
use App\Integrations\Sms\Data\SmsResult;
use Illuminate\Log\LogManager;

/**
 * Writes messages to the log instead of sending them.
 *
 * The default in development so onboarding can be walked end to end before any
 * merchant account exists. The message body is logged in full — that is the
 * point in development — but the recipient is masked even here, so a shared
 * development log does not become a list of phone numbers.
 */
class LogSmsProvider implements SmsProvider
{
    public function __construct(
        protected LogManager $log,
    ) {}

    public function send(SmsMessage $message): SmsResult
    {
        $this->log->channel('sms')->info('SMS (not sent — log driver)', [
            'to' => $this->mask($message->to),
            'event' => $message->event,
            'locale' => $message->locale->value,
            'segments' => $message->segments(),
            'unicode' => $message->isUnicode(),
            'body' => $message->body,
        ]);

        return SmsResult::accepted('log-'.uniqid());
    }

    public function balance(): ?string
    {
        return null;
    }

    public function name(): string
    {
        return 'log';
    }

    /**
     * `+8801712345678` becomes `+88017****5678`.
     */
    protected function mask(string $number): string
    {
        $length = strlen($number);

        if ($length <= 8) {
            return str_repeat('*', $length);
        }

        return substr($number, 0, 6).str_repeat('*', $length - 10).substr($number, -4);
    }
}
