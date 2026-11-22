<?php

use App\Http\Controllers\Api\Mobile\V1\Cases\CaseController;
use App\Http\Controllers\Api\Mobile\V1\Cases\CaseEvidenceController;
use App\Http\Controllers\Api\Mobile\V1\Cases\DamageController;
use Illuminate\Support\Facades\Route;

/*
 * A Partner's Returns & Exchanges and My Damages (D35), as the web `cases.*` and
 * `damages.*` screens offer them, plus the evidence attached to either.
 *
 * Self-scoped from the signed-in account: another Partner's order, case, damage
 * or file is a 404. Closed until the business is activated, as on the web.
 */
Route::middleware('mobile.activated')->group(function () {
    Route::prefix('cases')->name('cases.')->group(function () {
        Route::get('/', [CaseController::class, 'index'])->name('index');

        Route::post('evidence', [CaseEvidenceController::class, 'store'])
            ->middleware('throttle:store-actions')
            ->name('evidence.store');
        Route::get('evidence/{evidence}', [CaseEvidenceController::class, 'show'])->name('evidence.show');
        Route::delete('evidence/{evidence}', [CaseEvidenceController::class, 'destroy'])->name('evidence.destroy');

        Route::get('request/{order}', [CaseController::class, 'create'])->name('create');
        Route::post('request/{order}/estimate', [CaseController::class, 'estimate'])
            ->middleware('throttle:store-actions')
            ->name('estimate');
        Route::post('request/{order}', [CaseController::class, 'store'])
            ->middleware('throttle:store-actions')
            ->name('store');

        Route::get('{case}', [CaseController::class, 'show'])->name('show');
        Route::post('{case}/cancellation', [CaseController::class, 'cancel'])->name('cancel');
    });

    Route::prefix('damages')->name('damages.')->group(function () {
        Route::get('/', [DamageController::class, 'index'])->name('index');
        Route::get('report/{order}', [DamageController::class, 'create'])->name('create');
        Route::post('report/{order}', [DamageController::class, 'store'])
            ->middleware('throttle:store-actions')
            ->name('store');
        Route::get('{damage}', [DamageController::class, 'show'])->name('show');
        Route::post('{damage}/delivery', [DamageController::class, 'requestDelivery'])
            ->middleware('throttle:store-actions')
            ->name('delivery.store');
        Route::post('{damage}/receipt', [DamageController::class, 'confirmReceipt'])->name('receipt.store');
    });
});
