<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Nejcc\PaymentGateway\Http\Controllers\WebhookController;

/*
|--------------------------------------------------------------------------
| Payment Gateway Webhook Routes
|--------------------------------------------------------------------------
|
| These routes handle incoming webhooks from payment providers.
| They are excluded from CSRF verification by default.
|
*/

Route::prefix('webhooks/payment')
    ->middleware('api')
    ->group(function (): void {
        Route::post('/stripe', [WebhookController::class, 'stripe'])->name('payment.webhook.stripe');
        Route::post('/paypal', [WebhookController::class, 'paypal'])->name('payment.webhook.paypal');
        Route::post('/crypto', [WebhookController::class, 'crypto'])->name('payment.webhook.crypto');
    });
