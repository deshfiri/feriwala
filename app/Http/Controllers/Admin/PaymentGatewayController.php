<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Billing\Actions\ConfigureGateway;
use App\Domain\Billing\Policies\BillingSettingsPolicy;
use App\Http\Controllers\Controller;
use App\Integrations\Payment\PaymentGatewayManager;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;

/**
 * The payment gateways, and what each one still needs (§26.4, D7).
 *
 * All eight §26 names are listed rather than only the working one. An
 * administrator asking "why can nobody pay by bKash" needs to see that it exists
 * and what is missing, not an empty list — and "enabled but not credentialled"
 * is a state worth being able to see before a customer finds it at checkout.
 *
 * **No credential is ever sent to the browser.** The screen reports whether a
 * credential is present; the value itself only travels inbound. A secret in an
 * Inertia prop is a secret in the page source and in every error report that
 * captures it (§42).
 */
class PaymentGatewayController extends Controller
{
    public function __construct(
        protected ConfigureGateway $configure,
    ) {}

    public function index(Request $request, PaymentGatewayManager $gateways): Response
    {
        $actor = $this->actor($request);

        abort_unless(BillingSettingsPolicy::canView($actor), 403);

        return Inertia::render('admin/gateways', [
            'gateways' => $gateways->catalogue(),
            'can' => ['manage' => BillingSettingsPolicy::canManageGateways($actor)],
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $actor = $this->actor($request);

        abort_unless(BillingSettingsPolicy::canManageGateways($actor), 403);

        $validated = $request->validate([
            // Only gateways with a driver can be configured. The rest have
            // nothing to hold credentials for yet.
            'gateway' => ['required', Rule::in(['sslcommerz'])],
            'mode' => ['required', Rule::in(ConfigureGateway::MODES)],
            'store_id' => ['nullable', 'string', 'max:191'],
            'store_password' => ['nullable', 'string', 'max:191'],
        ]);

        try {
            $this->configure->sslCommerz($actor, $validated['mode'], [
                'store_id' => $validated['store_id'] ?? null,
                'store_password' => $validated['store_password'] ?? null,
            ]);
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['mode' => $exception->getMessage()]);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('gateways.saved')]);

        return back();
    }

    protected function actor(Request $request): User
    {
        $actor = $request->user();

        abort_if(! $actor instanceof User, 403);

        return $actor;
    }
}
