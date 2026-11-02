<?php

use App\Http\Controllers\Api\Mobile\V1\Kyc\KycController;
use App\Http\Controllers\Api\Mobile\V1\Kyc\KycDocumentController;
use App\Http\Controllers\Api\Mobile\V1\Kyc\KycEvidenceController;
use Illuminate\Support\Facades\Route;

// The applicant's KYC (§7). Part of the funnel, so open before activation (§5.4).
Route::prefix('kyc')->name('kyc.')->group(function () {
    Route::get('/', [KycController::class, 'show'])->name('show');
    Route::get('history', [KycController::class, 'history'])->name('history');
    Route::post('documents', [KycController::class, 'storeDocument'])->name('documents.store');
    Route::post('submit', [KycController::class, 'submit'])->name('submit');

    Route::get('documents/{document}', [KycDocumentController::class, 'show'])->name('documents.show');
    Route::get('documents/{document}/download', [KycDocumentController::class, 'download'])->name('documents.download');

    Route::prefix('evidence')->name('evidence.')->group(function () {
        Route::post('file', [KycEvidenceController::class, 'file'])->name('file');
        Route::post('value', [KycEvidenceController::class, 'value'])->name('value');
        Route::post('remove', [KycEvidenceController::class, 'remove'])->name('remove');
        Route::post('choose', [KycEvidenceController::class, 'choose'])->name('choose');
        Route::post('deselect', [KycEvidenceController::class, 'deselect'])->name('deselect');
    });
});
