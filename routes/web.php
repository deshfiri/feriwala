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
use App\Http\Controllers\LocaleController;
use App\Http\Controllers\Teams\TeamInvitationController;
use App\Http\Controllers\Webhook\PaymentWebhookController;
use App\Http\Middleware\EnsureTeamMembership;
use Illuminate\Support\Facades\Route;

Route::inertia('/', 'welcome')->name('home');

// Language switching is available to guests as well, so the public site and the
// login screen can be read in Bangla before an account exists (D6).
Route::put('locale', [LocaleController::class, 'update'])->name('locale.update');

Route::prefix('{current_team}')
    ->middleware(['auth', 'verified', 'activated', EnsureTeamMembership::class])
    ->group(function () {
        Route::get('dashboard', DashboardController::class)->name('dashboard');
    });

/*
 * `activated` is the §5.4 funnel gate, and it is applied to the whole
 * authenticated group rather than to the routes that need closing. It works as
 * an allow-list, so a new ERP route is shut to an unactivated account by
 * default — forgetting to list a route locks it down, where forgetting to add
 * it to a block-list would quietly expose it.
 */
Route::middleware(['auth', 'activated'])->group(function () {
    Route::post('invitations/{invitation}/accept', [TeamInvitationController::class, 'accept'])->name('invitations.accept');
    Route::delete('invitations/{invitation}', [TeamInvitationController::class, 'decline'])->name('invitations.decline');

    /*
     * Onboarding is one of the seven areas an unactivated account may reach
     * (§5.4), and stays reachable after activation so a newly active user can
     * see that rather than hitting a 404 on the page that has been guiding them.
     *
     * Deliberately outside the {current_team} prefix — a partly-registered
     * account has no team context to resolve, and D1 removes that prefix anyway.
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

        // Administrative review queues.
        Route::prefix('admin')->name('admin.')->group(function () {
            Route::get('kyc', [KycReviewController::class, 'index'])->name('kyc.index');
            Route::get('kyc/{submission}', [KycReviewController::class, 'show'])->name('kyc.show');
            Route::post('kyc/{submission}/decide', [KycReviewController::class, 'decide'])->name('kyc.decide');

            // The last gate before an account can trade (§5.1, §44).
            Route::get('activations', [ActivationReviewController::class, 'index'])->name('activations.index');
            Route::get('activations/{account}', [ActivationReviewController::class, 'show'])->name('activations.show');
            // Three outcomes, three routes. §5.3 gives approval-pending no
            // generic "reject", and a shared decline endpoint would invite one.
            Route::post('activations/{account}/approve', [ActivationReviewController::class, 'approve'])
                ->name('activations.approve');
            Route::post('activations/{account}/request-resubmission', [ActivationReviewController::class, 'requestResubmission'])
                ->name('activations.request-resubmission');
            Route::post('activations/{account}/suspend', [ActivationReviewController::class, 'suspend'])
                ->name('activations.suspend');
        });
    });
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
