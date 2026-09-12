<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Billing\Actions\ConfigureGateway;
use App\Domain\Billing\Models\PaymentLog;
use App\Domain\Billing\Policies\BillingSettingsPolicy;
use App\Http\Controllers\Controller;
use App\Integrations\Payment\Data\GatewayOutcome;
use App\Integrations\Payment\PaymentGatewayManager;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;
use RuntimeException;

/**
 * The payment gateways, and what each one still needs (§26.4, D7).
 *
 * All eight §26 names are listed rather than only the working ones. An
 * administrator asking "why can nobody pay by bKash" needs to see that it exists
 * and what is missing, not an empty list — and "enabled but not credentialled"
 * is a state worth being able to see before a customer finds it at checkout.
 *
 * **No credential is ever sent to the browser.** The screen reports whether a
 * credential is present and the *names* of the ones that are not; the value
 * itself only travels inbound. A secret in an Inertia prop is a secret in the
 * page source and in every error report that captures it (§42).
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

        $verified = $this->lastVerifications();

        $catalogue = array_map(
            fn (array $entry) => [
                ...$entry,

                /*
                 * When this provider last confirmed a payment with us (§26.4).
                 *
                 * Read from the payment log rather than stored as a setting,
                 * because it is a fact about what happened rather than something
                 * anybody configured — and because a "last verified" field that
                 * a screen writes is a field that can be wrong.
                 */
                'last_verified_at' => $verified[$entry['name']] ?? null,
            ],
            $gateways->catalogue(),
        );

        return Inertia::render('admin/gateways', [
            'gateways' => $catalogue,
            'modes' => ConfigureGateway::MODES,
            'can' => ['manage' => BillingSettingsPolicy::canManageGateways($actor)],
        ]);
    }

    /**
     * Store credentials for one gateway and one mode.
     */
    public function update(Request $request, PaymentGatewayManager $gateways): RedirectResponse
    {
        $actor = $this->actor($request);

        abort_unless(BillingSettingsPolicy::canManageGateways($actor), 403);

        $validated = $request->validate([
            // Only gateways with a driver can be configured. The rest have
            // nothing to hold credentials for yet.
            'gateway' => ['required', Rule::in($gateways->implemented())],
            'mode' => ['required', Rule::in(ConfigureGateway::MODES)],
            'credentials' => ['array'],
            'credentials.*' => ['nullable', 'string', 'max:1000'],
        ]);

        /** @var array<string, string|null> $credentials */
        $credentials = $validated['credentials'] ?? [];

        try {
            $this->configure->handle($actor, $validated['gateway'], $validated['mode'], $credentials);
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['gateway' => $exception->getMessage()]);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('gateways.saved')]);

        return back();
    }

    /**
     * Switch one gateway on or off (§26.4).
     *
     * Separate from storing credentials on purpose: entering a store password is
     * preparation, but switching a gateway on is the moment real customers can
     * be sent to it.
     */
    public function toggle(Request $request, PaymentGatewayManager $gateways): RedirectResponse
    {
        $actor = $this->actor($request);

        abort_unless(BillingSettingsPolicy::canManageGateways($actor), 403);

        $validated = $request->validate([
            'gateway' => ['required', Rule::in($gateways->implemented())],
            'enabled' => ['required', 'boolean'],
        ]);

        try {
            $this->configure->setEnabled($actor, $validated['gateway'], (bool) $validated['enabled']);
        } catch (InvalidArgumentException|RuntimeException $exception) {
            // A refusal to switch on is an answer to the form, not a failure
            // page: it says which credential is still missing.
            throw ValidationException::withMessages(['enabled' => $exception->getMessage()]);
        }

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __($validated['enabled'] ? 'gateways.enabled' : 'gateways.disabled'),
        ]);

        return back();
    }

    /**
     * The last time each provider confirmed a payment, server-to-server.
     *
     * Only a verification counts. An initiation proves a session could be
     * created, which is not the same as the provider having answered the only
     * question that releases value.
     *
     * @return array<string, string>
     */
    protected function lastVerifications(): array
    {
        /** @var array<int, object{gateway: string, last_verified_at: string|null}> $rows */
        $rows = PaymentLog::query()
            ->where('event', 'verify')
            ->where('outcome', GatewayOutcome::Paid->value)
            ->toBase()
            ->select('gateway')
            ->selectRaw('max(created_at) as last_verified_at')
            ->groupBy('gateway')
            ->get()
            ->all();

        $verified = [];

        foreach ($rows as $row) {
            if ($row->last_verified_at !== null) {
                $verified[$row->gateway] = (string) $row->last_verified_at;
            }
        }

        return $verified;
    }

    protected function actor(Request $request): User
    {
        $actor = $request->user();

        abort_if(! $actor instanceof User, 403);

        return $actor;
    }
}
