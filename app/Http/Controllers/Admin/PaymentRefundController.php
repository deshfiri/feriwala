<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Access\Exceptions\SensitiveActionRefused;
use App\Domain\Billing\Actions\ProcessRefund;
use App\Domain\Billing\Exceptions\RefundRefused;
use App\Domain\Billing\Models\RefundRequest;
use App\Http\Controllers\Controller;
use App\Http\Middleware\RequireTwoFactorForSensitiveRoles;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Sending an approved refund back through the gateway (§26.3, §32.2, D17).
 *
 * Separate from approving it, and deliberately. D17 makes a refund an
 * administrator's decision; this is the moment that decision becomes money
 * leaving the platform, and it carries the same escalation as the wallet's
 * manual operations:
 *
 *   - `payment.reverse_transaction`, a narrower permission than being able to
 *     look at payments or configure a gateway;
 *   - two-factor across the whole panel for any role holding a sensitive action
 *     ({@see RequireTwoFactorForSensitiveRoles});
 *   - a freshly confirmed password on this route specifically, because a session
 *     left open on a shared desk must not be able to send money back;
 *   - an explicit confirmation, so a misplaced click on a list cannot do it;
 *   - a reason, which is already on the request and cannot be blank.
 *
 * The controller validates and delegates. Every one of those controls is
 * enforced again inside {@see ProcessRefund}, so reaching that action from a
 * job or a command cannot skip them.
 */
class PaymentRefundController extends Controller
{
    public function __construct(
        protected ProcessRefund $refunds,
    ) {}

    public function store(Request $request, string $refund): RedirectResponse
    {
        $actor = $this->actor($request);
        $record = $this->refund($refund);

        $request->validate([
            /*
             * Deliberate rather than accidental. The dialog asks the person to
             * confirm in so many words, and the server refuses without it.
             */
            'confirm' => ['accepted'],
        ]);

        try {
            $processed = $this->refunds->handle(
                request: $record,
                actor: $actor,

                // The route's middleware has already established both. Passed
                // explicitly so the action states what it requires rather than
                // trusting its caller to have arranged it.
                passwordConfirmed: true,
                twoFactorEnabled: $actor->hasEnabledTwoFactorAuthentication(),
            );
        } catch (RefundRefused $refused) {
            // An answer to what was asked — "only ৳2,000 is still refundable" —
            // rather than a failure of the request.
            return back()->withErrors(['confirm' => $refused->getMessage()]);
        } catch (SensitiveActionRefused $refused) {
            return back()->withErrors(['confirm' => $refused->getMessage()]);
        }

        return back()->with('success', __('payment.refund.recorded', [
            'reference' => $processed->public_id,
            'status' => $processed->status->label(),
        ]));
    }

    protected function refund(string $publicId): RefundRequest
    {
        /** @var RefundRequest $refund */
        $refund = RefundRequest::query()->where('public_id', $publicId)->firstOrFail();

        return $refund;
    }

    protected function actor(Request $request): User
    {
        $actor = $request->user();

        abort_if(! $actor instanceof User, 403);

        return $actor;
    }
}
