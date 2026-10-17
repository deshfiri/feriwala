<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Account\Models\BusinessAccount;
use App\Domain\Audit\Actions\RecordAuditLog;
use App\Domain\Audit\Data\AuditEntry;
use App\Domain\Supplier\Models\Supplier;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\URL;

/**
 * An authorised administrator opening a partner's or a Supplier's own dashboard.
 *
 * Reached through a **signed link** that names the administrator it was made
 * for and expires, so a link copied out of the page, or one an attacker plants
 * in a message, does nothing for anyone else and nothing after half an hour.
 * The route is still authorised by policy on every request: the signature
 * proves the link is genuine, not that its holder may use it.
 *
 * The two portals behave differently, and the difference is forced by the
 * guards rather than chosen:
 *
 * - A **Supplier** has its own guard, so the administrator's session is left
 *   exactly as it was and the Supplier portal simply opens beside it, in the
 *   tab the link opens.
 * - A **Client/Partner** is a `web` user, the same guard staff sign in on, and a
 *   session holds one `web` identity. Opening one therefore swaps the
 *   administrator out for the owner in that browser's session until they
 *   press "Return to admin" — which the banner offers on every screen.
 *
 * Both begin and end are audit-logged with the administrator named.
 */
class DashboardAccessController extends Controller
{
    /** How long a generated link keeps working. */
    public const LINK_MINUTES = 30;

    public function __construct(
        protected RecordAuditLog $audit,
    ) {}

    /**
     * The link an administrator's screen offers for this account.
     */
    public static function partnerLink(BusinessAccount $account, User $administrator): string
    {
        return URL::temporarySignedRoute(
            'admin.accounts.open-dashboard',
            now()->addMinutes(self::LINK_MINUTES),
            ['account' => $account->public_id, 'administrator' => $administrator->id],
        );
    }

    public static function supplierLink(Supplier $supplier, User $administrator): string
    {
        return URL::temporarySignedRoute(
            'admin.suppliers.open-dashboard',
            now()->addMinutes(self::LINK_MINUTES),
            ['supplier' => $supplier->public_id, 'administrator' => $administrator->id],
        );
    }

    public function partner(Request $request, BusinessAccount $account): RedirectResponse
    {
        $administrator = $this->administrator($request);

        Gate::authorize('openDashboard', $account);

        $owner = $account->owner;

        abort_if($owner === null || $owner->is($administrator), 404);

        // Not from inside one: a chain of swaps would lose who the real
        // administrator was, and with it the way back.
        abort_if($request->session()->has('impersonation.admin_id'), 403);

        $this->audit->handle(new AuditEntry(
            action: 'account.dashboard_opened',
            actorId: $administrator->id,
            auditableType: BusinessAccount::class,
            auditableId: $account->id,
            reason: 'Administrator opened the account owner\'s dashboard.',
            accountId: $account->id,
            module: 'account',
            isSensitive: true,
        ));

        $request->session()->put('impersonation.admin_id', $administrator->id);
        $request->session()->put('impersonation.account', $account->public_id);

        Auth::guard('web')->login($owner);
        $request->session()->regenerate();

        return redirect()->route('dashboard');
    }

    public function supplier(Request $request, Supplier $supplier): RedirectResponse
    {
        $administrator = $this->administrator($request);

        Gate::authorize('openDashboard', $supplier);

        $this->audit->handle(new AuditEntry(
            action: 'supplier.dashboard_opened',
            actorId: $administrator->id,
            auditableType: Supplier::class,
            auditableId: $supplier->id,
            reason: 'Administrator opened the Supplier\'s portal.',
            module: 'supplier',
            isSensitive: true,
        ));

        $request->session()->put('impersonation.supplier_by', $administrator->id);
        $request->session()->put('impersonation.supplier', $supplier->public_id);

        Auth::guard('supplier')->login($supplier);
        $request->session()->regenerate();

        return redirect()->route('supplier.dashboard');
    }

    /**
     * End the visit and put the administrator back where they were.
     *
     * Deliberately outside the `admin` and `auth` groups: while a partner is
     * being viewed the session's identity is the partner, who holds no admin
     * permission, so the way out cannot be behind one.
     */
    public function stop(Request $request): RedirectResponse
    {
        $session = $request->session();

        if ($session->has('impersonation.supplier_by')) {
            $supplierId = $session->pull('impersonation.supplier');
            $administratorId = (int) $session->pull('impersonation.supplier_by');

            $supplier = Supplier::query()->where('public_id', $supplierId)->first();

            Auth::guard('supplier')->logout();

            $this->audit->handle(new AuditEntry(
                action: 'supplier.dashboard_closed',
                actorId: $administratorId,
                auditableType: Supplier::class,
                auditableId: $supplier?->id,
                module: 'supplier',
            ));

            return $supplier === null
                ? redirect()->route('admin.dashboard')
                : redirect()->route('admin.suppliers.show', $supplier->public_id);
        }

        $administratorId = $session->pull('impersonation.admin_id');
        $accountId = $session->pull('impersonation.account');

        abort_if($administratorId === null, 403);

        $administrator = User::query()->findOrFail((int) $administratorId);
        $account = BusinessAccount::query()->where('public_id', $accountId)->first();

        Auth::guard('web')->login($administrator);
        $session->regenerate();

        $this->audit->handle(new AuditEntry(
            action: 'account.dashboard_closed',
            actorId: $administrator->id,
            auditableType: BusinessAccount::class,
            auditableId: $account?->id,
            accountId: $account?->id,
            module: 'account',
        ));

        return $account === null
            ? redirect()->route('admin.dashboard')
            : redirect()->route('admin.accounts.show', $account->public_id);
    }

    /**
     * The administrator the link was made for, and nobody else.
     */
    protected function administrator(Request $request): User
    {
        $user = $request->user('web');

        abort_unless(
            $user instanceof User && (int) $request->query('administrator') === $user->id,
            403,
        );

        return $user;
    }
}
