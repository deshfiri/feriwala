<?php

namespace App\Http\Controllers\Supplier;

use App\Domain\Supplier\Models\Supplier;
use App\Http\Controllers\Controller;
use App\Http\Middleware\SetLocale;
use App\Notifications\Supplier\SupplierLifecycleNotification;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;
use Inertia\Response;

/**
 * A Supplier's own notifications, profile and password (D25). Everything is
 * read from and written to the authenticated Supplier only — no identifier
 * appears in any of these URLs.
 *
 * Email and mobile are not editable here: each is verified, and changing a
 * verified identifier needs its own re-verification flow, which this beta
 * does not build. Bank/payout detail is deliberately not shown on this
 * screen either.
 */
class AccountController extends Controller
{
    public function notifications(Request $request): Response
    {
        /** @var Supplier $supplier */
        $supplier = $request->user('supplier');

        $notifications = $supplier->notifications()->latest()->limit(50)->get()->map(fn ($notification) => [
            'id' => $notification->id,
            'title' => SupplierLifecycleNotification::line((string) ($notification->data['event'] ?? 'unknown'), 'title'),
            'description' => SupplierLifecycleNotification::line((string) ($notification->data['event'] ?? 'unknown'), 'description'),
            'note' => $notification->data['note'] ?? null,
            'read' => $notification->read_at !== null,
            'created_at' => $notification->created_at?->toIso8601String(),
        ]);

        return Inertia::render('supplier/notifications', ['notifications' => $notifications]);
    }

    public function markNotificationsRead(Request $request): RedirectResponse
    {
        /** @var Supplier $supplier */
        $supplier = $request->user('supplier');

        $supplier->unreadNotifications()->update(['read_at' => now()]);

        return back();
    }

    public function profile(Request $request): Response
    {
        /** @var Supplier $supplier */
        $supplier = $request->user('supplier');

        return Inertia::render('supplier/profile', [
            'profile' => [
                'reference' => $supplier->reference,
                'business_name' => $supplier->business_name,
                'contact_person_name' => $supplier->contact_person_name,
                'business_address' => $supplier->business_address,
                'email' => $supplier->email,
                'mobile' => $supplier->mobile,
                'trade_licence_number' => $supplier->trade_licence_number,
                'tax_identification_number' => $supplier->tax_identification_number,
                'locale' => $supplier->locale,
            ],
        ]);
    }

    public function updateProfile(Request $request): RedirectResponse
    {
        /** @var Supplier $supplier */
        $supplier = $request->user('supplier');

        $validated = $request->validate([
            'contact_person_name' => ['required', 'string', 'max:255'],
            'business_address' => ['required', 'string', 'max:1000'],
            'locale' => ['required', 'in:en,bn'],
        ]);

        $supplier->update($validated);

        // The language switcher writes the session, which `SetLocale` reads
        // before the saved column — so the choice made here has to reach it.
        $request->session()->put(SetLocale::SESSION_KEY, $validated['locale']);

        return back()->with('success', 'Profile updated.');
    }

    public function security(): Response
    {
        return Inertia::render('supplier/security');
    }

    public function updatePassword(Request $request): RedirectResponse
    {
        /** @var Supplier $supplier */
        $supplier = $request->user('supplier');

        $validated = $request->validate([
            'current_password' => ['required', 'string', 'current_password:supplier'],
            'password' => ['required', 'string', 'confirmed', Password::default()],
        ]);

        $supplier->update(['password' => $validated['password']]);

        return back()->with('success', 'Password updated.');
    }
}
