<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Order\Actions\AmendOrder;
use App\Domain\Order\Actions\ReviseOrderDetails;
use App\Domain\Order\Data\OrderAmendment;
use App\Domain\Order\Data\OrderDetailsRevision;
use App\Domain\Order\Exceptions\OrderRevisionRefused;
use App\Domain\Order\Models\Order;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

/**
 * Staff correcting an order through an applied revision (D38).
 *
 * Validates what was sent and hands it to the action, which checks each part
 * against its own `order.*` permission, the order's state and the revision the
 * staff saw. A refusal comes back as a form error that says what to do.
 */
class OrderRevisionController extends Controller
{
    /**
     * Who the order is for and where it goes: the customer's contact details,
     * both addresses and the customer's note.
     */
    public function details(Request $request, string $order, ReviseOrderDetails $revise): RedirectResponse
    {
        $actor = $request->user();
        abort_if(! $actor instanceof User, 403);

        /** @var Order $record */
        $record = Order::query()->where('public_id', $order)->firstOrFail();

        Gate::forUser($actor)->authorize('viewAny', Order::class);

        abort_unless(
            $actor->can('order.edit_customer') || $actor->can('order.edit_address'),
            403,
        );

        $text = ['nullable', 'string', 'max:255'];
        $addressRules = [];

        foreach (['shipping_address', 'billing_address'] as $address) {
            $addressRules[$address] = ['sometimes', 'array'];

            foreach (ReviseOrderDetails::ADDRESS_FIELDS as $field) {
                $addressRules["{$address}.{$field}"] = $text;
            }
        }

        $validated = $request->validate([
            'customer' => ['sometimes', 'array'],
            'customer.name' => ['nullable', 'string', 'max:160'],
            'customer.contact_name' => ['nullable', 'string', 'max:160'],
            'customer.business_name' => ['nullable', 'string', 'max:160'],
            'customer.mobile' => ['nullable', 'string', 'max:20', 'regex:/^\+?[0-9 ()-]{6,20}$/'],
            'customer.phone' => ['nullable', 'string', 'max:20', 'regex:/^\+?[0-9 ()-]{6,20}$/'],
            'customer.email' => ['nullable', 'email:rfc', 'max:191'],
            ...$addressRules,
            'shipping_address.contact_mobile' => ['nullable', 'string', 'max:20', 'regex:/^\+?[0-9 ()-]{6,20}$/'],
            'customer_note' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'reason' => ['required', 'string', 'min:'.ReviseOrderDetails::MINIMUM_REASON, 'max:1000'],
            'internal_note' => ['nullable', 'string', 'max:2000'],
            'base_revision' => ['required', 'integer', 'min:0'],
            'idempotency_key' => ['required', 'string', 'max:64', 'regex:/^[A-Za-z0-9-]+$/'],
        ]);

        try {
            $revision = $revise->handle($actor, $record, new OrderDetailsRevision(
                customer: $this->strings($validated['customer'] ?? []),
                shippingAddress: $this->strings($validated['shipping_address'] ?? []),
                billingAddress: $this->strings($validated['billing_address'] ?? []),
                customerNote: $validated['customer_note'] ?? null,
                noteGiven: array_key_exists('customer_note', $validated),
                reason: $validated['reason'],
                internalNote: $validated['internal_note'] ?? null,
                baseRevision: (int) $validated['base_revision'],
                idempotencyKey: $validated['idempotency_key'],
            ));
        } catch (OrderRevisionRefused $refused) {
            throw ValidationException::withMessages([$refused->field => $refused->getMessage()]);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('orders.revision.applied', ['number' => $revision->revision_number])]);

        return back();
    }

    /**
     * The Order Details editor's save: every tab in one applied revision.
     */
    public function amend(Request $request, string $order, AmendOrder $amend): RedirectResponse
    {
        [$actor, $record] = $this->authorizeEditor($request, $order);
        $amendment = $this->amendment($request, $actor, requireReason: true);

        try {
            $revision = $amend->handle($actor, $record, $amendment);
        } catch (OrderRevisionRefused $refused) {
            throw ValidationException::withMessages([$refused->field => $refused->getMessage()]);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('orders.revision.applied', ['number' => $revision->revision_number])]);

        return back();
    }

    /**
     * What saving would do — totals, money, stock and profit — worked out on
     * the server by doing it and rolling it back. Nothing is kept.
     */
    public function preview(Request $request, string $order, AmendOrder $amend): JsonResponse
    {
        [$actor, $record] = $this->authorizeEditor($request, $order);

        return response()->json($amend->preview($actor, $record, $this->amendment($request, $actor, requireReason: false)));
    }

    /**
     * @return array{0: User, 1: Order}
     */
    protected function authorizeEditor(Request $request, string $order): array
    {
        $actor = $request->user();
        abort_if(! $actor instanceof User, 403);

        /** @var Order $record */
        $record = Order::query()->where('public_id', $order)->firstOrFail();

        Gate::forUser($actor)->authorize('viewAny', Order::class);

        abort_unless(
            $actor->can('order.edit_customer') || $actor->can('order.edit_address')
                || $actor->can('order.revise_items') || $actor->can('order.revise_financials'),
            403,
        );

        return [$actor, $record];
    }

    protected function amendment(Request $request, User $actor, bool $requireReason): OrderAmendment
    {
        $text = ['nullable', 'string', 'max:255'];
        $addressRules = [];

        foreach (['shipping_address', 'billing_address'] as $address) {
            $addressRules[$address] = ['sometimes', 'array'];

            foreach (ReviseOrderDetails::ADDRESS_FIELDS as $field) {
                $addressRules["{$address}.{$field}"] = $text;
            }
        }

        $amount = ['nullable', 'string', 'max:20', 'regex:/^\d+(\.\d{1,2})?$/'];

        $validated = $request->validate([
            'customer' => ['sometimes', 'array'],
            'customer.name' => ['nullable', 'string', 'max:160'],
            'customer.contact_name' => ['nullable', 'string', 'max:160'],
            'customer.business_name' => ['nullable', 'string', 'max:160'],
            'customer.mobile' => ['nullable', 'string', 'max:20', 'regex:/^\+?[0-9 ()-]{6,20}$/'],
            'customer.phone' => ['nullable', 'string', 'max:20', 'regex:/^\+?[0-9 ()-]{6,20}$/'],
            'customer.email' => ['nullable', 'email:rfc', 'max:191'],
            ...$addressRules,
            'shipping_address.contact_mobile' => ['nullable', 'string', 'max:20', 'regex:/^\+?[0-9 ()-]{6,20}$/'],
            'customer_note' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'lines' => ['sometimes', 'nullable', 'array', 'max:100'],
            'lines.*.id' => ['nullable', 'string', 'max:26'],
            'lines.*.sku' => ['nullable', 'string', 'max:64'],
            'lines.*.quantity' => ['required', 'integer', 'min:1', 'max:100000'],
            'lines.*.unit_price' => $amount,
            'discount' => $amount,
            'delivery' => $amount,
            'reason' => $requireReason
                ? ['required', 'string', 'min:'.ReviseOrderDetails::MINIMUM_REASON, 'max:1000']
                : ['nullable', 'string', 'max:1000'],
            'internal_note' => ['nullable', 'string', 'max:2000'],
            'password' => ['nullable', 'string', 'max:255'],
            'base_revision' => ['required', 'integer', 'min:0'],
            'idempotency_key' => ['required', 'string', 'max:64', 'regex:/^[A-Za-z0-9-]+$/'],
        ]);

        $passwordConfirmed = time() - (int) $request->session()->get('auth.password_confirmed_at', 0) < (int) config('auth.password_timeout', 10800);

        if (! $passwordConfirmed && $requireReason && filled($validated['password'] ?? null)) {
            if (! Hash::check((string) $validated['password'], (string) $actor->getAuthPassword())) {
                throw ValidationException::withMessages(['password' => __('auth.password')]);
            }

            $request->session()->put('auth.password_confirmed_at', time());
            $passwordConfirmed = true;
        }

        return new OrderAmendment(
            details: new OrderDetailsRevision(
                customer: $this->strings($validated['customer'] ?? []),
                shippingAddress: $this->strings($validated['shipping_address'] ?? []),
                billingAddress: $this->strings($validated['billing_address'] ?? []),
                customerNote: $validated['customer_note'] ?? null,
                noteGiven: array_key_exists('customer_note', $validated),
                reason: (string) ($validated['reason'] ?? ''),
                internalNote: $validated['internal_note'] ?? null,
                baseRevision: (int) $validated['base_revision'],
                idempotencyKey: $validated['idempotency_key'],
            ),
            lines: array_key_exists('lines', $validated) && is_array($validated['lines'])
                ? array_values(array_map(fn (array $line) => [
                    'id' => isset($line['id']) ? (string) $line['id'] : null,
                    'sku' => isset($line['sku']) ? (string) $line['sku'] : null,
                    'quantity' => (int) $line['quantity'],
                    'unit_price' => isset($line['unit_price']) ? (string) $line['unit_price'] : null,
                ], $validated['lines']))
                : null,
            discount: $validated['discount'] ?? null,
            delivery: $validated['delivery'] ?? null,
            passwordConfirmed: $passwordConfirmed,
            twoFactorEnabled: $actor->hasEnabledTwoFactorAuthentication(),
        );
    }

    /**
     * @param  array<string, mixed>  $values
     * @return array<string, string|null>
     */
    protected function strings(array $values): array
    {
        $strings = [];

        foreach ($values as $key => $value) {
            $strings[(string) $key] = is_scalar($value) ? (string) $value : null;
        }

        return $strings;
    }
}
