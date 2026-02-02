<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Nejcc\PaymentGateway\Http\Controllers\Admin\CustomerController;
use Nejcc\PaymentGateway\Http\Controllers\Admin\DashboardController;
use Nejcc\PaymentGateway\Http\Controllers\Admin\InvoiceController;
use Nejcc\PaymentGateway\Http\Controllers\Admin\PlanController;
use Nejcc\PaymentGateway\Http\Controllers\Admin\SubscriptionController;
use Nejcc\PaymentGateway\Http\Controllers\Admin\TransactionController;

/*
|--------------------------------------------------------------------------
| Payment Gateway Admin Routes
|--------------------------------------------------------------------------
|
| These routes provide admin functionality for managing payments,
| subscriptions, plans, and customers.
|
*/

Route::prefix(config('payment-gateway.admin.prefix', 'admin/payments'))
    ->middleware(config('payment-gateway.admin.middleware', ['web', 'auth', 'role:super-admin,admin']))
    ->name('admin.payments.')
    ->group(function (): void {
        // Dashboard
        Route::get('/', [DashboardController::class, 'index'])->name('dashboard');
        Route::get('/stats', [DashboardController::class, 'stats'])->name('stats');

        // Transactions
        Route::get('/transactions', [TransactionController::class, 'index'])->name('transactions.index');
        Route::get('/transactions/{transaction}', [TransactionController::class, 'show'])->name('transactions.show');
        Route::post('/transactions/{transaction}/refund', [TransactionController::class, 'refund'])->name('transactions.refund');

        // Subscriptions
        Route::get('/subscriptions', [SubscriptionController::class, 'index'])->name('subscriptions.index');
        Route::get('/subscriptions/{subscription}', [SubscriptionController::class, 'show'])->name('subscriptions.show');
        Route::post('/subscriptions/{subscription}/cancel', [SubscriptionController::class, 'cancel'])->name('subscriptions.cancel');
        Route::post('/subscriptions/{subscription}/resume', [SubscriptionController::class, 'resume'])->name('subscriptions.resume');

        // Customers
        Route::get('/customers', [CustomerController::class, 'index'])->name('customers.index');
        Route::get('/customers/{customer}', [CustomerController::class, 'show'])->name('customers.show');

        // Plans
        Route::get('/plans', [PlanController::class, 'index'])->name('plans.index');
        Route::get('/plans/create', [PlanController::class, 'create'])->name('plans.create');
        Route::post('/plans', [PlanController::class, 'store'])->name('plans.store');
        Route::get('/plans/{plan}/edit', [PlanController::class, 'edit'])->name('plans.edit');
        Route::put('/plans/{plan}', [PlanController::class, 'update'])->name('plans.update');
        Route::delete('/plans/{plan}', [PlanController::class, 'destroy'])->name('plans.destroy');
        Route::post('/plans/{plan}/sync', [PlanController::class, 'sync'])->name('plans.sync');

        // Invoices
        Route::get('/invoices', [InvoiceController::class, 'index'])->name('invoices.index');
        Route::get('/invoices/{invoice}', [InvoiceController::class, 'show'])->name('invoices.show');
        Route::get('/invoices/{invoice}/download', [InvoiceController::class, 'download'])->name('invoices.download');
        Route::post('/invoices/{invoice}/regenerate-pdf', [InvoiceController::class, 'regeneratePdf'])->name('invoices.regenerate-pdf');
    });
