<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Access\Exceptions\SensitiveActionRefused;
use App\Domain\Billing\Actions\SettlePaymentManually;
use App\Domain\Billing\Data\ManualSettlement;
use App\Domain\Billing\Exceptions\ManualSettlementRefused;
use App\Domain\Billing\Models\Payment;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Marking a payment paid by hand (`payment.settle_manually`).
 *
 * For the payment that was paid at the provider and still reads failed, closed or
 * stuck here. The controller validates and delegates: the permission, the
 * confirmed password, two-factor and the reason are enforced again inside
 * {@see SettlePaymentManually}, so this route is not the only thing standing
 * between a session and the books.
 */
class PaymentManualSettlementController extends Controller
{
    public function __construct(
        protected SettlePaymentManually $settlements,
    ) {}

    public function store(Request $request, string $payment): RedirectResponse
    {
        $actor = $request->user();

        abort_if(! $actor instanceof User, 403);

        $record = Payment::query()->where('public_id', $payment)->firstOrFail();

        $data = $request->validate([
            'gateway_reference' => ['required', 'string', 'max:255'],
            'reason' => ['required', 'string', 'min:10', 'max:1000'],
            'override' => ['sometimes', 'boolean'],
            'confirm' => ['accepted'],
        ]);

        try {
            $settlement = $this->settlements->handle(
                payment: $record,
                actor: $actor,
                gatewayReference: $data['gateway_reference'],
                reason: $data['reason'],
                override: (bool) ($data['override'] ?? false),
                passwordConfirmed: true,
                twoFactorEnabled: $actor->hasEnabledTwoFactorAuthentication(),
            );
        } catch (ManualSettlementRefused $refused) {
            return back()
                ->withErrors(['gateway_reference' => $refused->getMessage()])
                ->with('can_override', $refused->overridable);
        } catch (SensitiveActionRefused $refused) {
            return back()->withErrors(['confirm' => $refused->getMessage()]);
        }

        return back()->with('success', __(
            $settlement->basis === ManualSettlement::ALREADY_SETTLED
                ? 'payments.manual.already'
                : 'payments.manual.done',
            ['reference' => $settlement->payment->reference],
        ));
    }
}
