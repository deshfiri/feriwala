<?php

namespace App\Notifications\Referral;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

/**
 * A referral commission reached the business's wallet (D24).
 *
 * The amount and the level only — never whose activation paid it: a level-3
 * earning must not tell its beneficiary who joined three steps below them.
 */
class ReferralCommissionPaid extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * @param  array<string, mixed>  $amount  the money, as the API serialises it
     */
    public function __construct(
        public readonly array $amount,
        public readonly int $level,
    ) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'event' => 'referral.commission_paid',
            'amount' => $this->amount,
            'level' => $this->level,
        ];
    }
}
