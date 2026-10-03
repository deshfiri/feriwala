<?php

namespace App\Http\Middleware;

use App\Domain\Access\Enums\PermissionAction;
use App\Domain\Access\Enums\PermissionModule;
use App\Domain\Access\PermissionCatalogue;
use App\Domain\Account\Data\AccountContext;
use App\Domain\Account\StaffAllowance;
use App\Domain\Notification\Queries\RecentNotifications;
use App\Domain\Package\Entitlements;
use App\Domain\Settings\Branding;
use App\Models\User;
use App\Support\Localization\Locale;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\File;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    public function __construct(
        protected StaffAllowance $allowance,
        protected RecentNotifications $notifications,
        protected Entitlements $entitlements,
        protected Branding $branding,
    ) {}

    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Every ability the sidebar and command palette gate a link on.
     *
     * Declared as data so the list can be read back by a test and compared
     * against what `resources/js/hooks/use-navigation.ts` actually asks for.
     * Add a navigation entry, add its ability here.
     *
     * @var array<int, array{0: PermissionModule, 1: PermissionAction}>
     */
    public const NAVIGATION_ABILITIES = [
        [PermissionModule::Kyc, PermissionAction::View],
        [PermissionModule::Account, PermissionAction::View],

        // Configuring what applicants are asked for is a different job from
        // reviewing one application (§7.2).
        [PermissionModule::Kyc, PermissionAction::ManageSettings],

        [PermissionModule::Package, PermissionAction::View],

        /*
         * The central catalogue (§11, §12). Its own permission rather than a
         * broader admin check: §12 makes catalogue authorship a platform
         * privilege, and a business account holds none of it — so this ability
         * is false for every partner and the link never appears.
         */
        [PermissionModule::Catalog, PermissionAction::View],

        // Central stock and the warehouses holding it (§19). Its own permission:
        // counting stock is not writing the catalogue, and a partner holds neither.
        [PermissionModule::Inventory, PermissionAction::View],

        // Every order, for the staff who review them (§18.4). A partner follows
        // their own orders on their own page and holds none of this.
        [PermissionModule::Order, PermissionAction::View],

        // Every partner storefront, for the staff who administer them (§16.3).
        // A partner runs their own on their own pages and holds none of this.
        [PermissionModule::Website, PermissionAction::View],

        // What every partner may charge for what they sell (§15.1). Its own
        // permission: setting the bounds for the platform is not the same job
        // as administering one storefront.
        [PermissionModule::Website, PermissionAction::ManageSettings],

        // The multi-level referral configuration (D24). Seeing it and changing
        // it are separate permissions; the screen is reached by seeing.
        [PermissionModule::Referral, PermissionAction::ViewSettings],

        // Platform-wide referral commissions and chains (D24). A partner sees
        // only their own, on their own page, and holds none of this.
        [PermissionModule::Referral, PermissionAction::View],

        // Billing rules, payment gateways and the payment log (§9, §26.4, §42).
        [PermissionModule::Payment, PermissionAction::View],

        // Deciding, sending or settling a refund, each of which is worked from
        // the returns desk (P6-12): these reach it without `order.view`.
        [PermissionModule::Payment, PermissionAction::Approve],
        [PermissionModule::Payment, PermissionAction::ReverseTransaction],

        // Whether customers hear about their own payments is its own decision,
        // and its own permission (§30).
        [PermissionModule::Sms, PermissionAction::View],

        // Account wallets and their ledgers (§23, §33.7). Separate from
        // `payment.view`: reconciling what a gateway sent and reading what a
        // business holds are different jobs.
        [PermissionModule::Wallet, PermissionAction::View],

        // The platform's logo and browser icon.
        [PermissionModule::System, PermissionAction::ManageSettings],

        // The Supplier account domain's staff screens (D25). One per screen,
        // each its own permission — seeing pricing is never implied by
        // seeing the listing queue.
        [PermissionModule::Supplier, PermissionAction::View],
        [PermissionModule::SupplierListing, PermissionAction::View],
        [PermissionModule::SupplierPricing, PermissionAction::View],
        [PermissionModule::SupplierStock, PermissionAction::View],

        // Supplier payables and their settlement queue (D25, P13-22/P13-23).
        // Missing since the payables screen itself was added (888a6a9) — the
        // link was gated on this key from the start, but the key was never
        // added here, so it silently never appeared for anyone, Super Admin
        // included (§33's Gate::before grants the ability; it does not add a
        // missing entry to this list).
        [PermissionModule::SupplierPayable, PermissionAction::View],

        // Supplier withdrawals (D25, P13-24), reusing the existing
        // Client/Partner Withdrawal module rather than a Supplier-specific one.
        [PermissionModule::Withdrawal, PermissionAction::View],

        // The Roles & Permissions screens (commit-order item 6). Missing
        // since the "Roles & permissions" nav link itself was added — the
        // exact same failure mode as the SupplierPayable entry above: the
        // link was gated on this key from the start, but the key was never
        // added here, so it silently never appeared for anyone, Super Admin
        // included (Gate::before grants the ability; it does not add a
        // missing entry to this list).
        [PermissionModule::Access, PermissionAction::View],

        // Product Sourcing Groups under Catalogue.
        [PermissionModule::SourcingGroup, PermissionAction::View],

        // Delivery settings under Shipments & Delivery. The link was gated on
        // this key but the key was never shipped, so it stayed hidden for
        // everyone, Super Admin included.
        [PermissionModule::DeliverySettings, PermissionAction::View],
    ];

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        // The `web` guard named explicitly. `auth:supplier` makes `supplier`
        // the *default* guard for the rest of the request (Authenticate calls
        // `shouldUse()`), so a bare `user()` here would hand a Supplier to
        // code that only understands `App\Models\User` (D25).
        $user = $request->user('web');

        return [
            ...parent::share($request),
            'name' => config('app.name'),

            /*
             * The brand images, as addresses a browser can load — the one
             * contract every layout and the document head read, for guests and
             * signed-in people alike. Never a storage path.
             */
            'branding' => fn () => $this->branding->toArray(),

            'auth' => [
                'user' => $user,
            ],

            /*
             * The signed-in Supplier (D25), if any — a separate identity from
             * `auth.user`, on its own guard. Only what the portal shell needs;
             * never the password, payout detail or any confidential figure.
             */
            'supplierAccount' => fn () => $this->supplierAccount($request),
            'sidebarOpen' => ! $request->hasCookie('sidebar_state') || $request->cookie('sidebar_state') === 'true',

            /*
             * One account, shared as a fact rather than a choice (D1).
             *
             * This replaces the starter kit's `currentTeam` plus `teams` pair.
             * There is no list, because a person belongs to one business account
             * and cannot switch; sending a list is what makes a front end build
             * a switcher for it.
             */
            'account' => fn () => $user === null
                ? null
                : AccountContext::forUser($user, $this->allowance, $this->entitlements),
            'locale' => [
                'current' => App::getLocale(),
                'direction' => Locale::parse(App::getLocale())->direction(),
                'available' => Locale::options(),
            ],
            'translations' => fn () => $this->translations(App::getLocale()),
            'permissions' => fn () => $this->navigationPermissions($user),

            /*
             * The header bell (§33.2, D20).
             *
             * Lazy, so the query runs once per full page load rather than on
             * every partial reload — a notification arriving mid-session is not
             * urgent enough to pay for on every filter change.
             */
            'notifications' => fn () => $user === null
                ? []
                : $this->notifications->forUser($user),
            'unreadNotificationCount' => fn () => $user === null
                ? 0
                : $this->notifications->unreadCountFor($user),
        ];
    }

    /**
     * @return array{business_name: string, reference: string, status: string, status_label: string, operational: bool, unread_notifications: int}|null
     */
    protected function supplierAccount(Request $request): ?array
    {
        $supplier = $request->user('supplier');

        if ($supplier === null) {
            return null;
        }

        return [
            'business_name' => $supplier->business_name,
            'reference' => $supplier->reference,
            'status' => $supplier->status->value,
            'status_label' => $supplier->status->label(),
            'operational' => $supplier->isOperational(),
            'unread_notifications' => $supplier->unreadNotifications()->count(),
        ];
    }

    /**
     * The abilities the navigation gates on.
     *
     * An explicit short list, not the user's whole permission set. Shipping all
     * 161 would put the entire access model in the browser on every page load
     * for the sake of a handful of menu entries — and hiding a link is only a
     * convenience anyway. The policy on the route is what actually refuses.
     *
     * **A key missing from this list hides its link silently.** The browser
     * reads `permissions['payment.view']`, an absent key is `undefined`, and
     * `undefined` is falsy — so forgetting to add one here looks exactly like
     * not having the permission, with nothing failing anywhere. That is how
     * Billing rules, Payment gateways, Payments and SMS all became invisible to
     * a Super Admin who could open every one of them by typing the address.
     *
     * `tests/Feature/Ui/NavigationPermissionsTest.php` now reads the keys the
     * navigation actually asks for and fails if one is not shipped, so the next
     * screen cannot go missing the same way.
     *
     * @return array<string, bool>
     */
    protected function navigationPermissions(?User $user): array
    {
        if ($user === null) {
            return [];
        }

        $permissions = [];

        foreach (self::NAVIGATION_ABILITIES as [$module, $action]) {
            $name = PermissionCatalogue::name($module, $action);

            $permissions[$name] = $user->can($name);
        }

        return $permissions;
    }

    /**
     * The translation lines for the active locale.
     *
     * Sent as a lazy prop so it is resolved once per full page load rather than on
     * every partial reload. Missing lines fall back to English rather than
     * rendering a raw key at the user (decision D6).
     *
     * @return array<string, mixed>
     */
    protected function translations(string $locale): array
    {
        $fallback = $this->loadTranslations(Locale::default()->value);

        if ($locale === Locale::default()->value) {
            return $fallback;
        }

        return array_replace_recursive($fallback, $this->loadTranslations($locale));
    }

    /**
     * @return array<string, mixed>
     */
    protected function loadTranslations(string $locale): array
    {
        $path = lang_path($locale);

        if (! File::isDirectory($path)) {
            return [];
        }

        $lines = [];

        foreach (File::files($path) as $file) {
            if ($file->getExtension() === 'php') {
                $lines[$file->getFilenameWithoutExtension()] = require $file->getPathname();
            }
        }

        return $lines;
    }
}
