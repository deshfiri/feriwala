<?php

use App\Http\Controllers\Api\Mobile\V1\Onboarding\ApplicationController;
use App\Http\Controllers\Api\Mobile\V1\Onboarding\DashboardController;
use App\Http\Controllers\Api\Mobile\V1\Onboarding\OnboardingController;
use App\Http\Controllers\Api\Mobile\V1\Onboarding\ReferralController;
use Illuminate\Support\Facades\Route;

// The activation status (§33.4, §5.4). Reachable before activation, and after it.
Route::get('onboarding', [OnboardingController::class, 'status'])->name('onboarding.status');

// The application, onboarding v2 (D39): personal details, business setup, the
// preferred-domain check, and the submission Banij verifies.
Route::get('onboarding/application', [ApplicationController::class, 'show'])->name('onboarding.application.show');
Route::put('onboarding/application/details', [ApplicationController::class, 'updateDetails'])->name('onboarding.application.details');
Route::put('onboarding/application/business', [ApplicationController::class, 'updateBusiness'])->name('onboarding.application.business');
Route::get('onboarding/application/domain-check', [ApplicationController::class, 'checkDomain'])
    ->middleware('throttle:30,1')
    ->name('onboarding.application.domain-check');
Route::post('onboarding/application/submit', [ApplicationController::class, 'submit'])
    ->middleware('throttle:10,1')
    ->name('onboarding.application.submit');

// The home summary and the business's referrals: an activated business only.
Route::middleware('mobile.activated')->group(function () {
    Route::get('dashboard', [DashboardController::class, 'show'])->name('dashboard.show');
    Route::get('referrals', [ReferralController::class, 'index'])->name('referrals.index');
});
