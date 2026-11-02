<?php

use App\Http\Controllers\Api\Mobile\V1\Onboarding\DashboardController;
use App\Http\Controllers\Api\Mobile\V1\Onboarding\OnboardingController;
use App\Http\Controllers\Api\Mobile\V1\Onboarding\ReferralController;
use Illuminate\Support\Facades\Route;

// The activation status (§33.4, §5.4). Reachable before activation, and after it.
Route::get('onboarding', [OnboardingController::class, 'status'])->name('onboarding.status');

// The home summary and the business's referrals: an activated business only.
Route::middleware('mobile.activated')->group(function () {
    Route::get('dashboard', [DashboardController::class, 'show'])->name('dashboard.show');
    Route::get('referrals', [ReferralController::class, 'index'])->name('referrals.index');
});
