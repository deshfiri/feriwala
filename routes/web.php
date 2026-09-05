<?php

use App\Http\Controllers\Admin\ActivationReviewController;
use App\Http\Controllers\Admin\KycReviewController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\Erp\CheckoutController;
use App\Http\Controllers\Erp\KycController;
use App\Http\Controllers\Erp\KycDocumentController;
use App\Http\Controllers\Erp\OnboardingController;
use App\Http\Controllers\Erp\PackageSelectionController;
use App\Http\Controllers\Erp\PaymentReturnController;
use App\Http\Controllers\Erp\StaffInvitationController;
use App\Http\Controllers\LocaleController;
use App\Http\Controllers\Webhook\PaymentWebhookController;
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
        Route::post('kyc/documents', [KycController::class, 'storeDocument'])->name('kyc.documents.store');
        Route::post('kyc/submit', [KycController::class, 'submit'])->name('kyc.submit');

        // Package selection and the combined activation checkout (§8.2, §9).
        Route::get('packages', [PackageSelectionController::class, 'index'])->name('packages.index');
        Route::post('packages/{package}/select', [PackageSelectionController::class, 'select'])->name('packages.select');

        Route::get('checkout', [CheckoutController::class, 'show'])->name('checkout.show');
        Route::post('checkout', [CheckoutController::class, 'pay'])->name('checkout.pay');

        // Gateways return the user here. The IPN is what settles the payment
        // reliably; this is for the person watching the screen.
        Route::match(['get', 'post'], 'checkout/return', PaymentReturnController::class)
            ->name('checkout.return');

        /*
         * The only route that serves a KYC document (§7.5). Authorisation and
         * access recording both happen in the controller; there is no other way
         * to reach these files.
         */
        Route::get('kyc/documents/{document}', [KycDocumentController::class, 'show'])
            ->name('kyc.documents.show');
        Route::get('kyc/documents/{document}/download', [KycDocumentController::class, 'download'])
            ->name('kyc.documents.download');

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
Route::middleware(['auth', 'noindex'])
    ->prefix('admin')
    ->name('admin.')
    ->group(function () {
        Route::get('kyc', [KycReviewController::class, 'index'])->name('kyc.index');
        Route::get('kyc/{submission}', [KycReviewController::class, 'show'])->name('kyc.show');
        Route::post('kyc/{submission}/decide', [KycReviewController::class, 'decide'])->name('kyc.decide');

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
    ->name('webhooks.payment');

require __DIR__.'/settings.php';
