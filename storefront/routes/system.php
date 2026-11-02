<?php

use App\Http\Controllers\SystemController;
use App\Http\Controllers\WebhookController;
use Illuminate\Support\Facades\Route;

/*
| Routes that must work whatever the shop's status and without a session: they
| belong to the ERP, the monitor and the person installing the store. They are
| loaded without the "web" middleware group.
*/

Route::get('/health', [SystemController::class, 'health'])->name('health');
Route::get('/migrate', [SystemController::class, 'migrate'])->name('migrate');
Route::post('/webhooks/feriwala', WebhookController::class)->name('webhooks.feriwala');
