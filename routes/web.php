<?php

use App\Http\Controllers\Admin\AccountController;
use App\Http\Controllers\Admin\ActivationReviewController;
use App\Http\Controllers\Admin\AvailabilityController;
use App\Http\Controllers\Admin\BillingController;
use App\Http\Controllers\Admin\BrandController;
use App\Http\Controllers\Admin\BrandingController;
use App\Http\Controllers\Admin\CategoryController;
use App\Http\Controllers\Admin\DashboardController as AdminDashboardController;
use App\Http\Controllers\Admin\DepositRuleController;
use App\Http\Controllers\Admin\IdentityAccessController;
use App\Http\Controllers\Admin\KycDocumentTypeController;
use App\Http\Controllers\Admin\KycReviewController;
use App\Http\Controllers\Admin\KycUpdateRequestController;
use App\Http\Controllers\Admin\OrderController;
use App\Http\Controllers\Admin\OrderReturnController;
use App\Http\Controllers\Admin\PackageAssignmentController;
use App\Http\Controllers\Admin\PackageController;
use App\Http\Controllers\Admin\PaymentGatewayController;
use App\Http\Controllers\Admin\PaymentLogController;
use App\Http\Controllers\Admin\PaymentRefundController;
use App\Http\Controllers\Admin\ProductAttributeController;
use App\Http\Controllers\Admin\ProductBulkController;
use App\Http\Controllers\Admin\ProductChannelController;
use App\Http\Controllers\Admin\ProductController;
use App\Http\Controllers\Admin\ProductEligibilityController;
use App\Http\Controllers\Admin\ProductMediaController;
use App\Http\Controllers\Admin\ProductMerchandisingController;
use App\Http\Controllers\Admin\ProductPriceTierController;
use App\Http\Controllers\Admin\ProductStatusController;
use App\Http\Controllers\Admin\ProductVariantController;
use App\Http\Controllers\Admin\ReferralChainController;
use App\Http\Controllers\Admin\ReferralCommissionController;
use App\Http\Controllers\Admin\ReferralSettingsController;
use App\Http\Controllers\Admin\ReservationController;
use App\Http\Controllers\Admin\SmsController;
use App\Http\Controllers\Admin\StockAdjustmentController;
use App\Http\Controllers\Admin\StockAllocationController;
use App\Http\Controllers\Admin\StockController;
use App\Http\Controllers\Admin\SupplierAllocationController;
use App\Http\Controllers\Admin\SupplierController;
use App\Http\Controllers\Admin\SupplierListingController;
use App\Http\Controllers\Admin\SupplierOfferController;
use App\Http\Controllers\Admin\SupplierPayableController;
use App\Http\Controllers\Admin\SupplierStockController;
use App\Http\Controllers\Admin\SupplierWalletController;
use App\Http\Controllers\Admin\SupplierWithdrawalController;
use App\Http\Controllers\Admin\WalletAdjustmentController;
use App\Http\Controllers\Admin\WalletController as AdminWalletController;
use App\Http\Controllers\Admin\WalletCreditRetryController;
use App\Http\Controllers\Admin\WarehouseController;
use App\Http\Controllers\Admin\WebsiteController as AdminWebsiteController;
use App\Http\Controllers\Admin\WebsitePricingController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\Erp\AllocatedStockController;
use App\Http\Controllers\Erp\CatalogController as BusinessCatalogController;
use App\Http\Controllers\Erp\CheckoutController;
use App\Http\Controllers\Erp\KycController;
use App\Http\Controllers\Erp\KycDocumentController;
use App\Http\Controllers\Erp\MobileVerificationController;
use App\Http\Controllers\Erp\OnboardingController;
use App\Http\Controllers\Erp\PackageSelectionController;
use App\Http\Controllers\Erp\PaymentReturnController;
use App\Http\Controllers\Erp\ReferralController;
use App\Http\Controllers\Erp\StaffInvitationController;
use App\Http\Controllers\Erp\WalletController;
use App\Http\Controllers\Erp\WalletTopUpController;
use App\Http\Controllers\Erp\WebsiteCategoryController;
use App\Http\Controllers\Erp\WebsiteController;
use App\Http\Controllers\Erp\WebsiteIntegrationController;
use App\Http\Controllers\Erp\WebsiteOrderController;
use App\Http\Controllers\Erp\WebsiteProductController;
use App\Http\Controllers\Erp\WebsiteSettingsController;
use App\Http\Controllers\Erp\WholesaleCartController;
use App\Http\Controllers\Erp\WholesaleCheckoutController;
use App\Http\Controllers\Erp\WholesaleOrderController;
use App\Http\Controllers\Erp\WholesaleOrderPaymentReturnController;
use App\Http\Controllers\LocaleController;
use App\Http\Controllers\Webhook\GatewayReturnController;
use App\Http\Controllers\Webhook\PaymentWebhookController;
use Illuminate\Auth\Middleware\RequirePassword;
use Illuminate\Support\Facades\Route;

Route::inertia('/', 'welcome')->name('home');

// Language switching is available to guests as well, so the public site and the
// login screen can be read in Bangla before an account exists (D6).
Route::put('locale', [LocaleController::class, 'update'])->name('locale.update');

/*
 * One dashboard at one address (D1).
 *
 * The `{current_team}` prefix that used to wrap this is gone, not renamed. A
 * person belongs to exactly one business account and cannot switch, so an
 * account segment in the URL had nothing to vary — it only offered somebody
 * else's identifier to try.
 */
Route::middleware(['auth', 'verified', 'business.activated'])
    ->group(function () {
        Route::get('dashboard', DashboardController::class)->name('dashboard');
    });

/*
 * `business.activated` is the §5.4 funnel gate. It works as an allow-list, so a
 * new ERP route is shut to an unactivated account by default — forgetting to
 * list a route locks it down, where forgetting to add it to a block-list would
 * quietly expose it.
 *
 * The identity gate is not here because it is global (bootstrap/app.php): a
 * suspended login must lose every panel, and a gate that has to be remembered
 * per route group is one somebody will eventually forget.
 */
/*
 * Joining somebody else's account (§8.1, D23).
 *
 * Outside `business.activated`, and it has to be: the invitee has no business
 * account of their own — that is what being invited means — so a gate asking
 * whether their business is activated would refuse every invitation ever sent.
 * The global identity gate still applies, and the action checks that the person
 * signing in is the one the invitation was addressed to.
 */
Route::middleware(['auth', 'noindex'])->group(function () {
    Route::get('staff/invitation/{token}', [StaffInvitationController::class, 'show'])
        ->name('staff.invitation.show');
    Route::post('staff/invitation/{token}', [StaffInvitationController::class, 'accept'])
        ->name('staff.invitation.accept');
});

Route::middleware(['auth', 'business.activated'])->group(function () {
    /*
     * Onboarding is one of the seven areas an unactivated account may reach
     * (§5.4), and stays reachable after activation so a newly active user can
     * see that rather than hitting a 404 on the page that has been guiding them.
     *
     */
    Route::middleware('noindex')->group(function () {
        Route::get('onboarding', [OnboardingController::class, 'status'])
            ->name('onboarding.status');

        /*
         * Confirming the registered mobile number (§5.1, P1-9). Opening the
         * page sends nothing; a code goes out only when it is asked for.
         * Outside `verified`, because the mobile can be confirmed first.
         */
        Route::get('verification/mobile', [MobileVerificationController::class, 'show'])
            ->name('verification.mobile');
        Route::post('verification/mobile/code', [MobileVerificationController::class, 'send'])
            ->name('verification.mobile.send');
        Route::post('verification/mobile', [MobileVerificationController::class, 'verify'])
            ->name('verification.mobile.verify');

        // KYC (§7). Documents save one at a time so a rejected upload never
        // costs the applicant the ones that were fine.
        Route::get('kyc', [KycController::class, 'create'])->name('kyc.create');

        // The applicant's own history (§7.3). Self-scoped from their
        // membership; there is no account identifier in the URL to change.
        Route::get('kyc/history', [KycController::class, 'history'])->name('kyc.history');
        Route::post('kyc/documents', [KycController::class, 'storeDocument'])->name('kyc.documents.store');
        Route::post('kyc/submit', [KycController::class, 'submit'])->name('kyc.submit');

        // Package selection and the combined activation checkout (§8.2, §9).
        Route::get('packages', [PackageSelectionController::class, 'index'])->name('packages.index');
        Route::post('packages/{package}/select', [PackageSelectionController::class, 'select'])->name('packages.select');

        Route::get('checkout', [CheckoutController::class, 'show'])->name('checkout.show');
        Route::post('checkout', [CheckoutController::class, 'pay'])->name('checkout.pay');

        // Holding a coupon code against this checkout (§9). Nothing is spent
        // here — the code is revalidated on every render and again at payment.
        Route::post('checkout/coupon', [CheckoutController::class, 'applyCoupon'])
            ->name('checkout.coupon');

        /*
         * Where a gateway sends the person back to (§26.4).
         *
         * Three endpoints, because a gateway is told three URLs and uses them
         * to say which of the three happened. One shared URL would throw that
         * away and leave us reading a status field out of the browser.
         *
         * None of them settles anything on its own: the IPN is the reliable
         * half, and these exist for the person watching the screen. GET only,
         * inside the session: a gateway that posts the person back reaches the
         * stateless receiver at the same address first (see the end of this file).
         */
        Route::get('checkout/return', [PaymentReturnController::class, 'success'])
            ->name('checkout.return');
        Route::get('checkout/cancelled', [PaymentReturnController::class, 'cancelled'])
            ->name('checkout.cancelled');
        Route::get('checkout/failed', [PaymentReturnController::class, 'failed'])
            ->name('checkout.failed');

        /*
         * The only route that serves a KYC document (§7.5). Authorisation and
         * access recording both happen in the controller; there is no other way
         * to reach these files.
         */
        Route::get('kyc/documents/{document}', [KycDocumentController::class, 'show'])
            ->name('kyc.documents.show');
        Route::get('kyc/documents/{document}/download', [KycDocumentController::class, 'download'])
            ->name('kyc.documents.download');

        /*
         * The account's own wallet and statement (§23, §33.7, P2-8).
         *
         * Not on the §5.4 allow-list, deliberately: a wallet opens with the
         * activation, so before that there is nothing to show and the funnel
         * gate turns the visitor back to onboarding rather than to an empty
         * screen that looks broken.
         *
         * Self-scoped — the wallet is reached through the membership, so no
         * identifier appears in any of these URLs (§31.3).
         */
        Route::get('wallet', [WalletController::class, 'show'])->name('wallet.show');

        /*
         * Putting money in (§24, §26.3, P2-19).
         *
         * The amount is the only thing the browser sends; everything else — what
         * it would do, which purpose it is, what it may not be less than — comes
         * back from the server. Settlement stays where it is: the gateway hands
         * off to the same return and IPN endpoints the checkout uses, and
         * SettlePayment remains the only thing that decides a payment was made.
         */
        Route::get('wallet/top-up', [WalletTopUpController::class, 'create'])
            ->name('wallet.top-up.create');
        Route::post('wallet/top-up', [WalletTopUpController::class, 'store'])
            ->name('wallet.top-up.store');

        // `download` rather than `export`: the generated TypeScript helper takes
        // its name from the last segment, and `export` is a reserved word there.
        Route::get('wallet/statement.csv', [WalletController::class, 'export'])
            ->name('wallet.download');
        Route::get('wallet/transactions/{transaction}', [WalletController::class, 'transaction'])
            ->name('wallet.transactions.show');

        /*
         * The central catalogue for a business account (§10, §12, §13).
         *
         * Read-only, and one route set per channel. Dropshipping and wholesale
         * are separate acts on the same products, each with its own eligibility
         * query — a product switched on only for wholesale cannot be opened on
         * the dropshipping route by typing its address. Nothing here writes.
         */
        Route::get('catalog/wholesale', [BusinessCatalogController::class, 'wholesale'])
            ->name('catalog.wholesale.index');
        Route::get('catalog/wholesale/{product}', [BusinessCatalogController::class, 'showWholesale'])
            ->name('catalog.wholesale.show');
        Route::get('catalog/dropshipping', [BusinessCatalogController::class, 'dropshipping'])
            ->name('catalog.dropshipping.index');
        Route::get('catalog/dropshipping/{product}', [BusinessCatalogController::class, 'showDropshipping'])
            ->name('catalog.dropshipping.show');

        /*
         * Central stock set aside for this account (§19, P3-30). Read-only and
         * self-scoped through the membership: no account identifier in the URL.
         */
        Route::get('allocated-stock', [AllocatedStockController::class, 'index'])
            ->name('allocated-stock.index');

        /*
         * The ERP wholesale cart (§14, P4-4, P4-5). Self-scoped through the
         * signed-in person: no cart identifier in any URL, and a line is found
         * only inside the requester's own cart. Every figure is priced again on
         * the server; nothing the browser sends is charged.
         */
        Route::get('wholesale/cart', [WholesaleCartController::class, 'show'])
            ->name('wholesale.cart.show');
        Route::post('wholesale/cart/items', [WholesaleCartController::class, 'store'])
            ->name('wholesale.cart.items.store');
        Route::patch('wholesale/cart/items/{item}', [WholesaleCartController::class, 'update'])
            ->name('wholesale.cart.items.update');
        Route::delete('wholesale/cart/items/{item}', [WholesaleCartController::class, 'destroy'])
            ->name('wholesale.cart.items.destroy');
        Route::post('wholesale/cart/prices', [WholesaleCartController::class, 'acceptPrices'])
            ->name('wholesale.cart.prices.accept');

        // Wholesale checkout (§14, P4-6), over the person's own cart.
        Route::get('wholesale/checkout', [WholesaleCheckoutController::class, 'show'])
            ->name('wholesale.checkout.show');
        Route::put('wholesale/checkout/coupon', [WholesaleCheckoutController::class, 'applyCoupon'])
            ->name('wholesale.checkout.coupon.apply');
        Route::delete('wholesale/checkout/coupon', [WholesaleCheckoutController::class, 'removeCoupon'])
            ->name('wholesale.checkout.coupon.remove');
        Route::put('wholesale/checkout/addresses/{type}', [WholesaleCheckoutController::class, 'updateAddress'])
            ->whereIn('type', ['billing', 'shipping'])
            ->name('wholesale.checkout.addresses.update');
        Route::post('wholesale/checkout/confirmation', [WholesaleCheckoutController::class, 'confirm'])
            ->name('wholesale.checkout.confirmation.store');
        Route::delete('wholesale/checkout/confirmation', [WholesaleCheckoutController::class, 'withdrawConfirmation'])
            ->name('wholesale.checkout.confirmation.destroy');

        // The account's own wholesale orders (§10.2, P4-9–P4-12).
        Route::get('wholesale/orders', [WholesaleOrderController::class, 'index'])
            ->name('wholesale.orders.index');
        Route::post('wholesale/orders', [WholesaleOrderController::class, 'store'])
            ->name('wholesale.orders.store');
        Route::get('wholesale/orders/{order}', [WholesaleOrderController::class, 'show'])
            ->name('wholesale.orders.show');
        Route::post('wholesale/orders/{order}/payment', [WholesaleOrderController::class, 'pay'])
            ->name('wholesale.orders.payment.store');
        Route::post('wholesale/orders/{order}/cancellation', [WholesaleOrderController::class, 'cancel'])
            ->name('wholesale.orders.cancellation.store');

        /*
         * The business's own referrals (D24, P7-10, P7-18). Self-scoped
         * through the membership; no identifier in the URL.
         */
        Route::get('referrals', [ReferralController::class, 'index'])->name('referrals.index');

        /*
         * The account's own dedicated websites (§16, P5-8–P5-11, P5-15).
         *
         * Self-scoped through the membership: no account identifier in any of
         * these URLs, and a website is found among this account's or not at
         * all. Every charge is priced on the server and paid from the wallet;
         * the browser names which charge, never how much.
         */
        Route::get('websites', [WebsiteController::class, 'index'])
            ->name('websites.index');
        Route::get('websites/create', [WebsiteController::class, 'create'])
            ->name('websites.create');
        Route::post('websites', [WebsiteController::class, 'store'])
            ->name('websites.store');
        Route::get('websites/{website}', [WebsiteController::class, 'show'])
            ->name('websites.show');
        Route::post('websites/{website}/charges/{charge}/payment', [WebsiteController::class, 'payCharge'])
            ->name('websites.charges.pay');
        Route::post('websites/{website}/domains/{domain}/renewal', [WebsiteController::class, 'renewDomain'])
            ->name('websites.domains.renew');
        Route::post('websites/{website}/hostings/{hosting}/renewal', [WebsiteController::class, 'renewHosting'])
            ->name('websites.hostings.renew');

        // The one §16.4 state a partner chooses for themselves (P5-15).
        Route::put('websites/{website}/maintenance', [WebsiteController::class, 'updateMaintenance'])
            ->name('websites.maintenance.update');

        /*
         * Managing the storefront itself (§16.3, P5-12, P5-14).
         *
         * Exactly what §16.3 lists: information, branding, contact details and
         * the theme. **No route here creates a product** — §16.3 forbids it,
         * and the surface simply does not exist. Uploads are POST because a
         * browser cannot send a file with PUT.
         */
        Route::get('websites/{website}/settings', [WebsiteSettingsController::class, 'edit'])
            ->name('websites.settings.edit');
        Route::put('websites/{website}/settings', [WebsiteSettingsController::class, 'update'])
            ->name('websites.settings.update');
        Route::post('websites/{website}/settings/{asset}', [WebsiteSettingsController::class, 'updateImage'])
            ->whereIn('asset', ['logo', 'banner'])
            ->name('websites.settings.image.update');
        Route::delete('websites/{website}/settings/{asset}', [WebsiteSettingsController::class, 'destroyImage'])
            ->whereIn('asset', ['logo', 'banner'])
            ->name('websites.settings.image.destroy');

        /*
         * What one storefront sells (§15, §15.1, P5-1–P5-7).
         *
         * **Selection, never authorship.** Every product here already exists in
         * the central catalogue; `store` records that this shop sells one of
         * them and creates nothing (§12, §16.3). Prices are checked against the
         * administrator's bounds on the server, every time.
         */
        Route::get('websites/{website}/products', [WebsiteProductController::class, 'index'])
            ->name('websites.products.index');
        Route::post('websites/{website}/products', [WebsiteProductController::class, 'store'])
            ->name('websites.products.store');
        Route::patch('websites/{website}/products/{selection}', [WebsiteProductController::class, 'update'])
            ->name('websites.products.update');
        Route::put('websites/{website}/products/{selection}/publication', [WebsiteProductController::class, 'updatePublication'])
            ->name('websites.products.publication.update');
        Route::delete('websites/{website}/products/{selection}', [WebsiteProductController::class, 'destroy'])
            ->name('websites.products.destroy');

        /*
         * Connecting the storefront (§17.3, contract §3, P5-17, P5-27, P5-28).
         * A secret is shown once, as flash data, in the response that made it.
         */
        /*
         * The orders this shop took (§16.3, §18.4, P5-13, P6-8, P6-10).
         *
         * Self-scoped through the website, which is resolved through the
         * account: another partner's shop, and every order on it, is a 404.
         */
        Route::get('websites/{website}/orders', [WebsiteOrderController::class, 'index'])
            ->name('websites.orders.index');
        Route::get('websites/{website}/orders/{order}', [WebsiteOrderController::class, 'show'])
            ->name('websites.orders.show');
        Route::post('websites/{website}/orders/{order}/cancellation', [WebsiteOrderController::class, 'cancel'])
            ->name('websites.orders.cancellation.store');
        // Asking for a return on the customer's behalf, and withdrawing one (P6-12).
        Route::post('websites/{website}/orders/{order}/returns', [WebsiteOrderController::class, 'requestReturn'])
            ->name('websites.orders.returns.store');
        Route::post('websites/{website}/orders/{order}/returns/{return}/cancellation', [WebsiteOrderController::class, 'cancelReturn'])
            ->name('websites.orders.returns.cancellation.store');

        Route::get('websites/{website}/integration', [WebsiteIntegrationController::class, 'show'])
            ->name('websites.integration.show');
        Route::post('websites/{website}/credentials', [WebsiteIntegrationController::class, 'storeCredential'])
            ->name('websites.credentials.store');
        Route::post('websites/{website}/credentials/{credential}/rotation', [WebsiteIntegrationController::class, 'rotateCredential'])
            ->name('websites.credentials.rotate');
        Route::post('websites/{website}/credentials/{credential}/revocation', [WebsiteIntegrationController::class, 'revokeCredential'])
            ->name('websites.credentials.revoke');

        // Where the storefront is told things, and what it was told (contract
        // §7, P5-20, P5-22, P5-25, P5-26). One endpoint per website in v1.
        Route::put('websites/{website}/webhook', [WebsiteIntegrationController::class, 'storeWebhook'])
            ->name('websites.webhook.store');
        Route::post('websites/{website}/webhook/rotation', [WebsiteIntegrationController::class, 'rotateWebhook'])
            ->name('websites.webhook.rotate');
        Route::delete('websites/{website}/webhook', [WebsiteIntegrationController::class, 'disableWebhook'])
            ->name('websites.webhook.disable');
        Route::post('websites/{website}/deliveries/{delivery}/retry', [WebsiteIntegrationController::class, 'retryDelivery'])
            ->name('websites.deliveries.retry');
        Route::post('websites/{website}/sync', [WebsiteIntegrationController::class, 'syncNow'])
            ->name('websites.sync.store');

        // The shop's own arrangement of what it sells (§15, P5-4).
        Route::get('websites/{website}/categories', [WebsiteCategoryController::class, 'index'])
            ->name('websites.categories.index');
        Route::post('websites/{website}/categories', [WebsiteCategoryController::class, 'store'])
            ->name('websites.categories.store');
        Route::post('websites/{website}/categories/order', [WebsiteCategoryController::class, 'reorder'])
            ->name('websites.categories.reorder');
        Route::patch('websites/{website}/categories/{category}', [WebsiteCategoryController::class, 'update'])
            ->name('websites.categories.update');
        Route::delete('websites/{website}/categories/{category}', [WebsiteCategoryController::class, 'destroy'])
            ->name('websites.categories.destroy');

        // Where the gateway sends the person back to, naming the order (§26.4).
        // None of them settles anything on the browser's word. GET only; a
        // posted return reaches the stateless receiver first.
        Route::get('wholesale/orders/{order}/payment/return', [WholesaleOrderPaymentReturnController::class, 'success'])
            ->name('wholesale.orders.payment.return');
        Route::get('wholesale/orders/{order}/payment/cancelled', [WholesaleOrderPaymentReturnController::class, 'cancelled'])
            ->name('wholesale.orders.payment.cancelled');
        Route::get('wholesale/orders/{order}/payment/failed', [WholesaleOrderPaymentReturnController::class, 'failed'])
            ->name('wholesale.orders.payment.failed');
    });
});

/*
 * Administration (§32, D23).
 *
 * Outside `business.activated` on purpose, and this is the whole point of the
 * identity/account split: a Feriwala staff member administers the platform
 * without owning a business, so requiring commercial KYC and an activation
 * payment to open the KYC queue was never right.
 *
 * It is not a bypass. Two things still stand between a request and these
 * routes — the global identity gate, which takes the panel from a suspended or
 * locked login before anything here runs, and a policy on every action. What
 * changed is which question closes the panel: being barred from the platform,
 * rather than not having bought a package.
 */
Route::middleware(['auth', 'noindex', 'two-factor'])
    ->prefix('admin')
    ->name('admin.')
    ->group(function () {
        // The platform staff overview (§33.3-equivalent for the Admin portal).
        // Every card is gated on the same permission the screen it links to
        // already requires, so it never shows a figure its viewer could not
        // otherwise reach.
        Route::get('dashboard', AdminDashboardController::class)->name('dashboard');

        /*
         * The requirement catalogue (§7.2).
         *
         * Platform configuration, with no account in any of it: scoping is
         * expressed as rules about packages and countries, so nothing here can
         * reveal what one particular business was asked for.
         */
        Route::get('kyc/document-types', [KycDocumentTypeController::class, 'index'])
            ->name('kyc.document-types.index');
        Route::post('kyc/document-types', [KycDocumentTypeController::class, 'store'])
            ->name('kyc.document-types.store');
        Route::post('kyc/document-types/reorder', [KycDocumentTypeController::class, 'reorder'])
            ->name('kyc.document-types.reorder');
        Route::patch('kyc/document-types/{documentType}', [KycDocumentTypeController::class, 'update'])
            ->name('kyc.document-types.update');
        Route::patch('kyc/document-types/{documentType}/active', [KycDocumentTypeController::class, 'setActive'])
            ->name('kyc.document-types.active');
        Route::post('kyc/document-types/{documentType}/archive', [KycDocumentTypeController::class, 'archive'])
            ->name('kyc.document-types.archive');
        Route::delete('kyc/document-types/{documentType}', [KycDocumentTypeController::class, 'destroy'])
            ->name('kyc.document-types.destroy');

        Route::get('kyc', [KycReviewController::class, 'index'])->name('kyc.index');
        Route::get('kyc/{submission}', [KycReviewController::class, 'show'])->name('kyc.show');
        Route::post('kyc/{submission}/decide', [KycReviewController::class, 'decide'])->name('kyc.decide');

        /*
         * The Supplier account domain (D25, P13-1, P13-7, P13-9, P13-13). A
         * wholly separate queue from the Client/Partner KYC above — see
         * app/Domain/Supplier/Policies for who may reach each action.
         */
        Route::get('suppliers', [SupplierController::class, 'index'])->name('suppliers.index');
        Route::get('suppliers/{supplier}', [SupplierController::class, 'show'])->name('suppliers.show');
        Route::get('suppliers/{supplier}/kyc/documents/{document}', [SupplierController::class, 'showDocument'])
            ->name('suppliers.kyc.documents.show');
        Route::post('suppliers/{supplier}/kyc/correction', [SupplierController::class, 'requestCorrection'])
            ->name('suppliers.kyc.correction.store');
        Route::post('suppliers/{supplier}/approval', [SupplierController::class, 'approve'])->name('suppliers.approval.store');
        Route::post('suppliers/{supplier}/rejection', [SupplierController::class, 'reject'])->name('suppliers.rejection.store');
        Route::post('suppliers/{supplier}/suspension', [SupplierController::class, 'suspend'])->name('suppliers.suspension.store');
        Route::post('suppliers/{supplier}/reactivation', [SupplierController::class, 'reactivate'])->name('suppliers.reactivation.store');

        // The Product Listing Request queue (P13-9, P13-11).
        Route::get('supplier-listings', [SupplierListingController::class, 'index'])->name('supplier-listings.index');
        Route::get('supplier-listings/{listing}', [SupplierListingController::class, 'show'])->name('supplier-listings.show');
        Route::post('supplier-listings/{listing}/correction', [SupplierListingController::class, 'requestCorrection'])
            ->name('supplier-listings.correction.store');
        Route::post('supplier-listings/{listing}/decision', [SupplierListingController::class, 'decide'])
            ->name('supplier-listings.decision.store');

        // Supplier offers, pricing, and catalogue connection (P13-13, D25 pricing rules).
        Route::get('supplier-offers', [SupplierOfferController::class, 'index'])->name('supplier-offers.index');
        Route::get('supplier-offers/{offer}', [SupplierOfferController::class, 'show'])->name('supplier-offers.show');
        Route::post('supplier-offers/{offer}/rates', [SupplierOfferController::class, 'setRates'])->name('supplier-offers.rates.store');
        Route::post('supplier-offers/{offer}/activation', [SupplierOfferController::class, 'activate'])->name('supplier-offers.activation.store');
        Route::post('supplier-offers/{offer}/suspension', [SupplierOfferController::class, 'suspend'])->name('supplier-offers.suspension.store');
        Route::post('supplier-offers/{offer}/preferred', [SupplierOfferController::class, 'makePreferred'])->name('supplier-offers.preferred.store');

        // Supplier stock/availability review (P13-15).
        Route::get('supplier-stock', [SupplierStockController::class, 'index'])->name('supplier-stock.index');
        Route::post('supplier-stock/{stockUpdate}/decision', [SupplierStockController::class, 'decide'])
            ->name('supplier-stock.decision.store');
        Route::post('supplier-offers/{offer}/stock-adjustment', [SupplierStockController::class, 'adjust'])
            ->name('supplier-offers.stock-adjustment.store');

        // Order allocation and Supplier payables (P13-21, P13-22).
        Route::get('supplier-allocations', [SupplierAllocationController::class, 'index'])->name('supplier-allocations.index');
        Route::get('supplier-allocations/{item}', [SupplierAllocationController::class, 'show'])->name('supplier-allocations.show');
        Route::get('supplier-payables', [SupplierPayableController::class, 'index'])->name('supplier-payables.index');
        Route::get('supplier-payables/{payable}', [SupplierPayableController::class, 'show'])->name('supplier-payables.show');
        Route::post('supplier-payables/{payable}/settle', [SupplierPayableController::class, 'settle'])
            ->middleware(RequirePassword::class)
            ->name('supplier-payables.settle');
        Route::post('supplier-payables/bulk-settle', [SupplierPayableController::class, 'bulkSettle'])
            ->middleware(RequirePassword::class)
            ->name('supplier-payables.bulk-settle');

        // Supplier wallets, read-only (D25, P13-23).
        Route::get('supplier-wallets', [SupplierWalletController::class, 'index'])->name('supplier-wallets.index');
        Route::get('supplier-wallets/{wallet}', [SupplierWalletController::class, 'show'])->name('supplier-wallets.show');

        // Supplier withdrawals (D25, P13-24).
        Route::get('supplier-withdrawals', [SupplierWithdrawalController::class, 'index'])->name('supplier-withdrawals.index');
        Route::get('supplier-withdrawals/{withdrawal}', [SupplierWithdrawalController::class, 'show'])->name('supplier-withdrawals.show');
        Route::post('supplier-withdrawals/{withdrawal}/approve', [SupplierWithdrawalController::class, 'approve'])->name('supplier-withdrawals.approve');
        Route::post('supplier-withdrawals/{withdrawal}/reject', [SupplierWithdrawalController::class, 'reject'])->name('supplier-withdrawals.reject');
        Route::post('supplier-withdrawals/{withdrawal}/process', [SupplierWithdrawalController::class, 'process'])->name('supplier-withdrawals.process');
        Route::post('supplier-withdrawals/{withdrawal}/paid', [SupplierWithdrawalController::class, 'markPaid'])
            ->middleware(RequirePassword::class)
            ->name('supplier-withdrawals.paid');
        Route::post('supplier-withdrawals/{withdrawal}/failed', [SupplierWithdrawalController::class, 'markFailed'])->name('supplier-withdrawals.failed');

        /*
         * One trading business, in full (P1-79).
         *
         * Separate from the activation queue, which only ever holds accounts
         * awaiting activation — a trading account is not in it, so this is the
         * only screen from which its KYC can be asked for again.
         */
        /*
         * The operations list of every Client/Partner business.
         *
         * Declared before the `{account}` route so "accounts" is never read
         * as a public id. "Partner" is not a second account type — it is a
         * trading business account using wholesale, dropshipping or both, so
         * this lists `business_accounts` and nothing else. Suppliers have
         * their own module.
         */
        Route::get('accounts', [AccountController::class, 'index'])
            ->name('accounts.index');

        Route::get('accounts/{account}', [AccountController::class, 'show'])
            ->name('accounts.show');

        /*
         * Asking a trading business for fresh KYC (§7.2).
         *
         * Keyed on the account, not a submission: there is no round to name
         * until this creates one, and the business being asked is the subject.
         */
        Route::post('accounts/{account}/kyc-update', KycUpdateRequestController::class)
            ->name('kyc.request-update');

        /*
         * Locking and unlocking a login (§6, P1-17).
         *
         * Keyed on the **person**, not on the account they belong to: locking an
         * owner takes their access away and leaves the business exactly where it
         * was (D23). Reached from the account dossier only because that is where
         * an administrator is standing when the question comes up.
         */
        /*
         * What Feriwala charges (§9, P1-43).
         *
         * Fee rules, coupons and tax rules on one screen: one decision with
         * three shapes, all of which change the amount on somebody's invoice.
         * Behind `payment.manage_settings` — the person who reconciles the money
         * is the person who should be able to price it.
         */
        Route::get('billing', [BillingController::class, 'index'])->name('billing.index');
        Route::post('billing/fee-rules', [BillingController::class, 'storeFeeRule'])
            ->name('billing.fee-rules.store');
        Route::delete('billing/fee-rules/{rule}', [BillingController::class, 'closeFeeRule'])
            ->name('billing.fee-rules.close');
        Route::post('billing/coupons', [BillingController::class, 'storeCoupon'])
            ->name('billing.coupons.store');
        Route::delete('billing/coupons/{coupon}', [BillingController::class, 'withdrawCoupon'])
            ->name('billing.coupons.withdraw');

        /*
         * Tax rates and the rules that point at them (D19, P1-47).
         *
         * Two things, not one: a rate is what a code is worth on a date, a rule
         * is what that code applies to. Editing either in place would rewrite
         * the arithmetic of invoices already issued, so both are opened and
         * closed rather than changed.
         */
        Route::post('billing/tax-rates', [BillingController::class, 'storeTaxRate'])
            ->name('billing.tax-rates.store');
        Route::delete('billing/tax-rates/{rate}', [BillingController::class, 'closeTaxRate'])
            ->name('billing.tax-rates.close');
        Route::post('billing/tax-rules', [BillingController::class, 'storeTaxRule'])
            ->name('billing.tax-rules.store');
        Route::delete('billing/tax-rules/{rule}', [BillingController::class, 'closeTaxRule'])
            ->name('billing.tax-rules.close');

        // How long an unpaid checkout stays open (§9, P1-48). Not
        // retrospective: every payment carries the deadline it was given.
        Route::put('billing/payment-deadline', [BillingController::class, 'updateDeadline'])
            ->name('billing.payment-deadline');

        /*
         * The gateways themselves (§26.4, P1-51).
         *
         * Separate from billing rules, and behind a separate permission:
         * setting a fee changes an invoice, but holding a merchant account's
         * credentials means holding the keys to where the money lands.
         */
        Route::get('gateways', [PaymentGatewayController::class, 'index'])
            ->name('gateways.index');
        Route::put('gateways', [PaymentGatewayController::class, 'update'])
            ->name('gateways.update');

        // Switching a gateway on is its own action: entering a store password
        // is preparation, but this is the moment real customers reach it.
        Route::put('gateways/enabled', [PaymentGatewayController::class, 'toggle'])
            ->name('gateways.toggle');

        /*
         * Payments and their gateway trail (§42, P1-54).
         *
         * Read-only, and behind `payment.view`. The screen somebody opens when
         * the money and the records disagree — including the payments that
         * arrived after their checkout closed and are waiting on a person.
         */
        Route::get('payments', [PaymentLogController::class, 'index'])
            ->name('payments.index');
        Route::get('payments/{payment}', [PaymentLogController::class, 'show'])
            ->name('payments.show');

        /*
         * SMS (§30, P1-55). Its own permission: turning messaging off decides
         * whether customers hear about their own payments.
         */
        Route::get('sms', [SmsController::class, 'index'])->name('sms.index');
        Route::put('sms', [SmsController::class, 'update'])->name('sms.update');

        /*
         * The platform's logo and browser icon, behind `system.manage_settings`.
         * Uploads are POST because a browser cannot send a file with PUT.
         */
        Route::get('settings/branding', [BrandingController::class, 'edit'])->name('branding.edit');
        Route::post('settings/branding/{asset}', [BrandingController::class, 'update'])
            ->whereIn('asset', ['logo', 'favicon'])
            ->name('branding.update');
        Route::delete('settings/branding/{asset}', [BrandingController::class, 'destroy'])
            ->whereIn('asset', ['logo', 'favicon'])
            ->name('branding.destroy');

        /*
         * Account wallets and their ledgers (§23, §33.7, P2-8).
         *
         * Read-only behind `wallet.view`, and the same figures the account
         * holder sees on their own screen — so the two sides of a support call
         * are not looking at different money.
         */
        /*
         * What accounts are required to deposit and keep (§24.1, P2-11, P2-12).
         *
         * Separate from the wallets themselves, and behind a different
         * permission: reading what a business holds and deciding what every
         * business must hold are different jobs.
         */
        Route::get('deposit-rules', [DepositRuleController::class, 'index'])
            ->name('deposit-rules.index');
        Route::post('deposit-rules', [DepositRuleController::class, 'store'])
            ->name('deposit-rules.store');
        Route::post('deposit-rules/{rule}/close', [DepositRuleController::class, 'close'])
            ->name('deposit-rules.close');

        Route::get('wallets', [AdminWalletController::class, 'index'])
            ->name('wallets.index');
        Route::get('wallets/{wallet}', [AdminWalletController::class, 'show'])
            ->name('wallets.show');
        Route::get('wallets/{wallet}/statement.csv', [AdminWalletController::class, 'export'])
            ->name('wallets.download');
        Route::get('wallets/{wallet}/transactions/{transaction}', [AdminWalletController::class, 'transaction'])
            ->name('wallets.transactions.show');

        /*
         * The two operations where a person, rather than an event, decides a
         * balance should change (§23.2, §32.2).
         *
         * Behind a freshly confirmed password on top of the panel's two-factor
         * requirement: a session left open on a shared desk must not be able to
         * post money. Each also carries its own permission and a mandatory
         * reason, and neither edits anything — both write new entries.
         */
        /*
         * Confirmed money that never reached the wallet it was paid into
         * (P2-19). Behind the adjustment permission, because moving money into a
         * wallet by hand is an adjustment in everything but name — and
         * idempotent on the payment's reference, so pressing it twice cannot pay
         * anybody twice.
         */
        Route::post('payments/{payment}/wallet-credit', [WalletCreditRetryController::class, 'store'])
            ->middleware(RequirePassword::class)
            ->name('payments.wallet-credit');

        /*
         * Sending an approved refund to the gateway (§26.3, P2-31).
         *
         * Separate from approving it: D17 makes the refund a decision, and this
         * is the moment that decision becomes money leaving the platform.
         * Behind the reversal permission rather than the payment one, because
         * looking at a payment and undoing it are different jobs.
         */
        Route::post('refunds/{refund}/process', [PaymentRefundController::class, 'store'])
            ->middleware(RequirePassword::class)
            ->name('refunds.process');

        Route::post('wallets/{wallet}/adjustments', [WalletAdjustmentController::class, 'store'])
            ->middleware(RequirePassword::class)
            ->name('wallets.adjustments.store');
        Route::post('wallets/{wallet}/transactions/{transaction}/reversal', [WalletAdjustmentController::class, 'reverse'])
            ->middleware(RequirePassword::class)
            ->name('wallets.reversals.store');

        /*
         * Giving an account a package without a sale (§8.3, P1-40).
         *
         * Keyed on the account, because the business being given something is
         * the subject. Guarded by the **package** policy: writing a plan and
         * handing one out are different decisions.
         */
        Route::post('accounts/{account}/package', PackageAssignmentController::class)
            ->name('accounts.assign-package');

        Route::post('identities/{user}/lock', [IdentityAccessController::class, 'lock'])
            ->name('identities.lock');
        Route::delete('identities/{user}/lock', [IdentityAccessController::class, 'unlock'])
            ->name('identities.unlock');

        /*
         * The package catalogue (§8.1).
         *
         * Archived packages stay listed: a subscription, a payment and an
         * invoice all name the package they were for, so a catalogue that drops
         * retired plans makes "which plan were they on" unanswerable.
         */
        Route::get('packages', [PackageController::class, 'index'])->name('packages.index');
        Route::post('packages', [PackageController::class, 'store'])->name('packages.store');
        Route::patch('packages/{package}', [PackageController::class, 'update'])->name('packages.update');
        Route::patch('packages/{package}/active', [PackageController::class, 'setActive'])
            ->name('packages.active');
        Route::post('packages/{package}/archive', [PackageController::class, 'archive'])
            ->name('packages.archive');

        /*
         * The central product catalogue (§11, §12).
         *
         * Every one of these is a platform privilege. §12 is explicit that a
         * regular user cannot create a product, a category, a brand or a
         * variation, and a business account holds no platform permission at
         * all — so a partner reaching any of these meets a 403, not a screen
         * with its buttons hidden.
         */
        Route::get('catalog/categories', [CategoryController::class, 'index'])
            ->name('catalog.categories.index');
        Route::post('catalog/categories', [CategoryController::class, 'store'])
            ->name('catalog.categories.store');
        // A branch's whole order in one request (§11.3).
        Route::post('catalog/categories/reorder', [CategoryController::class, 'reorder'])
            ->name('catalog.categories.reorder');
        Route::patch('catalog/categories/{category}', [CategoryController::class, 'update'])
            ->name('catalog.categories.update');
        /*
         * Switching a range off takes it and its subcategories off every
         * partner storefront, so it is its own endpoint rather than a field
         * somebody flips while correcting a typo (§11.3).
         */
        Route::patch('catalog/categories/{category}/active', [CategoryController::class, 'toggle'])
            ->name('catalog.categories.active');
        Route::delete('catalog/categories/{category}', [CategoryController::class, 'destroy'])
            ->name('catalog.categories.destroy');

        /*
         * Brands (§11.3). The same shape as categories without the tree. The
         * update route is PATCH, and the form reaches it as a POST carrying
         * `_method`, because a browser cannot send a file with PATCH.
         */
        Route::get('catalog/brands', [BrandController::class, 'index'])
            ->name('catalog.brands.index');
        Route::post('catalog/brands', [BrandController::class, 'store'])
            ->name('catalog.brands.store');
        Route::patch('catalog/brands/{brand}', [BrandController::class, 'update'])
            ->name('catalog.brands.update');
        Route::patch('catalog/brands/{brand}/active', [BrandController::class, 'toggle'])
            ->name('catalog.brands.active');
        Route::patch('catalog/brands/{brand}/position', [BrandController::class, 'move'])
            ->name('catalog.brands.position');
        Route::delete('catalog/brands/{brand}', [BrandController::class, 'destroy'])
            ->name('catalog.brands.destroy');

        /*
         * Central products (§11.1, §12). The editor is a page of its own, and
         * every write behind it is a platform privilege a partner never holds.
         */
        Route::get('catalog/products', [ProductController::class, 'index'])
            ->name('catalog.products.index');
        Route::get('catalog/products/create', [ProductController::class, 'create'])
            ->name('catalog.products.create');
        Route::post('catalog/products', [ProductController::class, 'store'])
            ->name('catalog.products.store');

        // One change to many products, each checked and reported on its own (§11.2).
        Route::post('catalog/products/bulk', ProductBulkController::class)
            ->name('catalog.products.bulk');
        Route::get('catalog/products/{product}/edit', [ProductController::class, 'edit'])
            ->name('catalog.products.edit');
        Route::patch('catalog/products/{product}', [ProductController::class, 'update'])
            ->name('catalog.products.update');
        Route::delete('catalog/products/{product}', [ProductController::class, 'destroy'])
            ->name('catalog.products.destroy');

        /*
         * Variations, always addressed through their product (§11.1, §12), and
         * the shared attributes they are built from.
         */
        Route::post('catalog/products/{product}/variants', [ProductVariantController::class, 'store'])
            ->name('catalog.products.variants.store');
        Route::post('catalog/products/{product}/variants/generate', [ProductVariantController::class, 'generate'])
            ->name('catalog.products.variants.generate');
        Route::patch('catalog/products/{product}/variants/{variant}', [ProductVariantController::class, 'update'])
            ->name('catalog.products.variants.update');
        Route::delete('catalog/products/{product}/variants/{variant}', [ProductVariantController::class, 'destroy'])
            ->name('catalog.products.variants.destroy');

        /*
         * The lifecycle (§11.2). One endpoint whose permission depends on the
         * move: publishing, archiving and taking off sale are separate
         * privileges, checked by the controller and again by the action.
         */
        Route::patch('catalog/products/{product}/status', [ProductStatusController::class, 'update'])
            ->name('catalog.products.status.update');

        // What a product recommends, and whether it is featured (§11.1).
        Route::put('catalog/products/{product}/related', [ProductMerchandisingController::class, 'related'])
            ->name('catalog.products.related.update');
        Route::patch('catalog/products/{product}/featured', [ProductMerchandisingController::class, 'featured'])
            ->name('catalog.products.featured.update');

        // Dropshipping and wholesale, one channel per request (§11.1).
        Route::patch('catalog/products/{product}/channels/{channel}', [ProductChannelController::class, 'update'])
            ->whereIn('channel', ['dropshipping', 'wholesale'])
            ->name('catalog.products.channels.update');

        // Which packages and accounts may see a product, as one decision (§11.1, §12).
        Route::put('catalog/products/{product}/eligibility', [ProductEligibilityController::class, 'update'])
            ->name('catalog.products.eligibility.update');

        // Quantity pricing, replaced as a whole table per scope (§11.1).
        Route::put('catalog/products/{product}/price-tiers', [ProductPriceTierController::class, 'update'])
            ->name('catalog.products.price-tiers.update');

        // Images and videos, addressed through their product (§11.1).
        Route::post('catalog/products/{product}/media', [ProductMediaController::class, 'store'])
            ->name('catalog.products.media.store');
        Route::post('catalog/products/{product}/media/reorder', [ProductMediaController::class, 'reorder'])
            ->name('catalog.products.media.reorder');
        Route::patch('catalog/products/{product}/media/{media}', [ProductMediaController::class, 'update'])
            ->name('catalog.products.media.update');
        Route::delete('catalog/products/{product}/media/{media}', [ProductMediaController::class, 'destroy'])
            ->name('catalog.products.media.destroy');

        Route::get('catalog/attributes', [ProductAttributeController::class, 'index'])
            ->name('catalog.attributes.index');
        Route::post('catalog/attributes', [ProductAttributeController::class, 'store'])
            ->name('catalog.attributes.store');
        Route::patch('catalog/attributes/{attribute}', [ProductAttributeController::class, 'update'])
            ->name('catalog.attributes.update');
        Route::delete('catalog/attributes/{attribute}', [ProductAttributeController::class, 'destroy'])
            ->name('catalog.attributes.destroy');
        Route::post('catalog/attributes/{attribute}/values', [ProductAttributeController::class, 'storeValue'])
            ->name('catalog.attributes.values.store');
        Route::patch('catalog/attribute-values/{value}', [ProductAttributeController::class, 'updateValue'])
            ->name('catalog.attribute-values.update');
        Route::delete('catalog/attribute-values/{value}', [ProductAttributeController::class, 'destroyValue'])
            ->name('catalog.attribute-values.destroy');

        /*
         * Central stock (§19). Reading is `inventory.view`; holding stock and
         * changing it by hand is `inventory.edit`. A partner holds neither, and
         * the Gate refuses them even if handed one (§19).
         */
        Route::get('inventory/stock', [StockController::class, 'index'])
            ->name('inventory.stock.index');
        Route::post('inventory/stock', [StockController::class, 'store'])
            ->name('inventory.stock.store');
        Route::get('inventory/stock/{item}', [StockController::class, 'show'])
            ->name('inventory.stock.show');
        Route::post('inventory/stock/{item}/adjustments', [StockAdjustmentController::class, 'store'])
            ->name('inventory.stock.adjustments.store');
        // When a SKU in a warehouse counts as running low (P3-29).
        Route::patch('inventory/stock/{item}/threshold', [StockController::class, 'threshold'])
            ->name('inventory.stock.threshold');

        // What every website is told about stock (§19.1, contract §5.2).
        Route::get('inventory/availability', [AvailabilityController::class, 'index'])
            ->name('inventory.availability.index');

        /*
         * Orders (§18.4, §18.5). Reading every order is `order.view`; cancelling
         * one nobody has paid for is `order.edit`, with a reason, audited.
         */
        Route::get('orders', [OrderController::class, 'index'])
            ->name('orders.index');
        Route::get('orders/{order}', [OrderController::class, 'show'])
            ->name('orders.show');
        Route::post('orders/{order}/cancellation', [OrderController::class, 'cancel'])
            ->name('orders.cancellation.store');

        /*
         * The returns desk (§18.2, §19.1, §26.3, P6-12). Each step asks for its
         * own permission — see OrderReturnController — and recording a refund
         * settled by hand sits behind a freshly confirmed password, as sending
         * one does.
         */
        Route::get('returns', [OrderReturnController::class, 'index'])
            ->name('returns.index');
        Route::get('returns/{return}', [OrderReturnController::class, 'show'])
            ->name('returns.show');
        Route::post('returns/{return}/approval', [OrderReturnController::class, 'approve'])
            ->name('returns.approval.store');
        Route::post('returns/{return}/rejection', [OrderReturnController::class, 'reject'])
            ->name('returns.rejection.store');
        Route::post('returns/{return}/receipt', [OrderReturnController::class, 'receive'])
            ->name('returns.receipt.store');
        Route::post('returns/{return}/refund', [OrderReturnController::class, 'refund'])
            ->name('returns.refund.store');
        Route::post('returns/{return}/refund/decision', [OrderReturnController::class, 'decideRefund'])
            ->name('returns.refund.decision.store');
        Route::post('returns/{return}/refund/settlement', [OrderReturnController::class, 'settle'])
            ->middleware(RequirePassword::class)
            ->name('returns.refund.settlement.store');

        /*
         * Stock reservations (contract §6.1.2). Reading is `inventory.view`;
         * overriding one and changing how long they last is `inventory.approve`.
         */
        Route::get('inventory/reservations', [ReservationController::class, 'index'])
            ->name('inventory.reservations.index');
        Route::put('inventory/reservations/windows', [ReservationController::class, 'windows'])
            ->name('inventory.reservations.windows');
        Route::post('inventory/reservations/{reservation}/release', [ReservationController::class, 'release'])
            ->name('inventory.reservations.release');
        Route::patch('inventory/reservations/{reservation}/expiry', [ReservationController::class, 'extend'])
            ->name('inventory.reservations.extend');

        /*
         * Stock set aside for business accounts (§19, P3-30). Reading is
         * `inventory.view`; allocating and releasing are `inventory.approve`.
         */
        Route::get('inventory/allocations', [StockAllocationController::class, 'index'])
            ->name('inventory.allocations.index');
        Route::post('inventory/stock/{item}/allocations', [StockAllocationController::class, 'store'])
            ->name('inventory.stock.allocations.store');
        Route::post('inventory/allocations/{allocation}/release', [StockAllocationController::class, 'release'])
            ->name('inventory.allocations.release');

        Route::get('inventory/warehouses', [WarehouseController::class, 'index'])
            ->name('inventory.warehouses.index');
        Route::post('inventory/warehouses', [WarehouseController::class, 'store'])
            ->name('inventory.warehouses.store');
        Route::patch('inventory/warehouses/{warehouse}', [WarehouseController::class, 'update'])
            ->name('inventory.warehouses.update');
        Route::patch('inventory/warehouses/{warehouse}/default', [WarehouseController::class, 'makeDefault'])
            ->name('inventory.warehouses.default');

        /*
         * Partner websites (§16.3, §16.4, P5-9, P5-11). Reading is
         * `website.view`; moving one through its lifecycle and recording what
         * was provisioned by hand (D9) is `website.edit`. A partner holds
         * neither — they run their own storefront on their own pages.
         */
        Route::get('websites', [AdminWebsiteController::class, 'index'])
            ->name('websites.index');
        Route::get('websites/{website}', [AdminWebsiteController::class, 'show'])
            ->name('websites.show');
        Route::post('websites/{website}/status', [AdminWebsiteController::class, 'updateStatus'])
            ->name('websites.status.store');
        Route::post('websites/{website}/domains', [AdminWebsiteController::class, 'storeDomain'])
            ->name('websites.domains.store');
        Route::post('websites/{website}/hostings', [AdminWebsiteController::class, 'storeHosting'])
            ->name('websites.hostings.store');

        /*
         * What partners may charge for what they sell (§15.1, P5-5). Behind
         * `website.manage_settings`: deciding the bounds for every partner is
         * not the same job as administering one storefront. Rules are opened
         * and closed, never edited.
         */
        Route::get('website-pricing', [WebsitePricingController::class, 'index'])
            ->name('website-pricing.index');
        Route::post('website-pricing', [WebsitePricingController::class, 'store'])
            ->name('website-pricing.store');
        Route::delete('website-pricing/{rule}', [WebsitePricingController::class, 'close'])
            ->name('website-pricing.close');

        /*
         * The multi-level referral configuration (§25.4.1, D24, P7-12, P7-44).
         * Seen with `referral.view_settings`, changed with
         * `referral.manage_settings`. Plan versions are opened and closed,
         * never edited.
         */
        Route::get('referral-settings', [ReferralSettingsController::class, 'index'])
            ->name('referral-settings.index');
        Route::post('referral-settings/switch', [ReferralSettingsController::class, 'toggle'])
            ->name('referral-settings.switch');
        Route::post('referral-settings/plans', [ReferralSettingsController::class, 'store'])
            ->name('referral-settings.plans.store');
        Route::post('referral-settings/plans/{plan}/close', [ReferralSettingsController::class, 'close'])
            ->name('referral-settings.plans.close');

        /*
         * Platform-wide referral commissions and chains (D24, P7-44). Seen
         * with `referral.view`; taking commission back needs
         * `referral.reverse_transaction` and a freshly confirmed password;
         * attaching a referrer needs `referral.edit`.
         */
        Route::get('referral-commissions', [ReferralCommissionController::class, 'index'])
            ->name('referral-commissions.index');
        Route::get('referral-events/{event}', [ReferralCommissionController::class, 'event'])
            ->name('referral-events.show');
        Route::post('referral-commissions/{commission}/reversal', [ReferralCommissionController::class, 'reverseCommission'])
            ->middleware(RequirePassword::class)
            ->name('referral-commissions.reverse');
        Route::post('referral-events/{event}/reversal', [ReferralCommissionController::class, 'reverseEvent'])
            ->middleware(RequirePassword::class)
            ->name('referral-events.reverse');
        Route::get('referral-chains', [ReferralChainController::class, 'show'])
            ->name('referral-chains.show');
        Route::post('referral-chains/{account}/referrer', [ReferralChainController::class, 'attach'])
            ->name('referral-chains.attach');

        // The last gate before an account can trade (§5.1, §44).
        Route::get('activations', [ActivationReviewController::class, 'index'])->name('activations.index');
        Route::get('activations/{account}', [ActivationReviewController::class, 'show'])->name('activations.show');
        // Three outcomes, three routes. §5.3 gives approval-pending no generic
        // "reject", and a shared decline endpoint would invite one.
        Route::post('activations/{account}/approve', [ActivationReviewController::class, 'approve'])
            ->name('activations.approve');
        Route::post('activations/{account}/request-resubmission', [ActivationReviewController::class, 'requestResubmission'])
            ->name('activations.request-resubmission');
        Route::post('activations/{account}/suspend', [ActivationReviewController::class, 'suspend'])
            ->name('activations.suspend');
        // Not a fourth outcome: a hold decides nothing about the account, it
        // only takes it off the automatic path so a person decides instead
        // (D27). Release is the same route with `release`.
        Route::post('activations/{account}/hold', [ActivationReviewController::class, 'hold'])
            ->name('activations.hold');
    });

/*
 * Gateway IPN. Unauthenticated by necessity and outside the web session, so the
 * signature is the only thing between it and an anonymous claim that money
 * arrived — the controller checks that first (§17.3, §26.4).
 *
 * CSRF is exempted in bootstrap/app.php; a gateway cannot carry our token.
 */
Route::post('webhooks/payment/{gateway}', PaymentWebhookController::class)
    ->middleware('throttle:payment-webhooks')
    ->name('webhooks.payment');

/*
 * A gateway posting the payer's browser back (§26.4, §36).
 *
 * SSLCommerz and aamarPay return the person with a form POST from their own
 * site, which carries neither the `SameSite=Lax` session cookie nor a CSRF token.
 * These exact POST routes — and nothing else — therefore run **without the web
 * middleware group**: no session, no cookies written, no CSRF check. They settle
 * nothing; a return is verified with the gateway only on a valid signature or an
 * identifier we already hold, and the browser is sent on with a 303 to the
 * signed-in GET page at the same address.
 */
Route::withoutMiddleware('web')
    ->middleware('throttle:payment-returns')
    ->group(function () {
        Route::post('checkout/return', [GatewayReturnController::class, 'checkoutReturn'])
            ->name('checkout.return.receive');
        Route::post('checkout/cancelled', [GatewayReturnController::class, 'checkoutCancelled'])
            ->name('checkout.cancelled.receive');
        Route::post('checkout/failed', [GatewayReturnController::class, 'checkoutFailed'])
            ->name('checkout.failed.receive');
        Route::post('wholesale/orders/{order}/payment/return', [GatewayReturnController::class, 'orderReturn'])
            ->name('wholesale.orders.payment.return.receive');
        Route::post('wholesale/orders/{order}/payment/cancelled', [GatewayReturnController::class, 'orderCancelled'])
            ->name('wholesale.orders.payment.cancelled.receive');
        Route::post('wholesale/orders/{order}/payment/failed', [GatewayReturnController::class, 'orderFailed'])
            ->name('wholesale.orders.payment.failed.receive');

        /*
         * A website's customer coming back from the gateway (§17, D12, P5-23).
         *
         * Feriwala is merchant of record, so the gateway returns the customer
         * here — by POST from the provider, or by GET where one redirects — and
         * this sends them on to the storefront they came from. There is no
         * signed-in page behind these: the customer has no ERP account.
         */
        Route::match(['get', 'post'], 'website-orders/{order}/payment/return', [GatewayReturnController::class, 'websiteOrderReturn'])
            ->name('website-orders.payment.return');
        Route::match(['get', 'post'], 'website-orders/{order}/payment/cancelled', [GatewayReturnController::class, 'websiteOrderCancelled'])
            ->name('website-orders.payment.cancelled');
        Route::match(['get', 'post'], 'website-orders/{order}/payment/failed', [GatewayReturnController::class, 'websiteOrderFailed'])
            ->name('website-orders.payment.failed');
    });

require __DIR__.'/settings.php';

// The Supplier account domain's own routes (D25, P13-1) — a separate tree,
// on the `supplier` guard throughout, never mixed into the group above.
require __DIR__.'/supplier.php';
