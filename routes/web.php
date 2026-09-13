<?php

use App\Http\Controllers\Admin\AccountController;
use App\Http\Controllers\Admin\ActivationReviewController;
use App\Http\Controllers\Admin\BillingController;
use App\Http\Controllers\Admin\BrandController;
use App\Http\Controllers\Admin\CategoryController;
use App\Http\Controllers\Admin\DepositRuleController;
use App\Http\Controllers\Admin\IdentityAccessController;
use App\Http\Controllers\Admin\KycDocumentTypeController;
use App\Http\Controllers\Admin\KycReviewController;
use App\Http\Controllers\Admin\KycUpdateRequestController;
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
use App\Http\Controllers\Admin\SmsController;
use App\Http\Controllers\Admin\WalletAdjustmentController;
use App\Http\Controllers\Admin\WalletController as AdminWalletController;
use App\Http\Controllers\Admin\WalletCreditRetryController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\Erp\CatalogController as BusinessCatalogController;
use App\Http\Controllers\Erp\CheckoutController;
use App\Http\Controllers\Erp\KycController;
use App\Http\Controllers\Erp\KycDocumentController;
use App\Http\Controllers\Erp\OnboardingController;
use App\Http\Controllers\Erp\PackageSelectionController;
use App\Http\Controllers\Erp\PaymentReturnController;
use App\Http\Controllers\Erp\StaffInvitationController;
use App\Http\Controllers\Erp\WalletController;
use App\Http\Controllers\Erp\WalletTopUpController;
use App\Http\Controllers\LocaleController;
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
         * half, and these exist for the person watching the screen.
         */
        Route::match(['get', 'post'], 'checkout/return', [PaymentReturnController::class, 'success'])
            ->name('checkout.return');
        Route::match(['get', 'post'], 'checkout/cancelled', [PaymentReturnController::class, 'cancelled'])
            ->name('checkout.cancelled');
        Route::match(['get', 'post'], 'checkout/failed', [PaymentReturnController::class, 'failed'])
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
         * One trading business, in full (P1-79).
         *
         * Separate from the activation queue, which only ever holds accounts
         * awaiting activation — a trading account is not in it, so this is the
         * only screen from which its KYC can be asked for again.
         */
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

require __DIR__.'/settings.php';
