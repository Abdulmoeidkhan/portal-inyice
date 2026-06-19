<?php

use App\Http\Controllers\Api\InvoiceController;
use App\Http\Controllers\Api\PaymentController;
use App\Http\Controllers\Api\AccountController;
use App\Http\Controllers\Api\ReportController;
use App\Http\Controllers\Api\StatementController;
use App\Http\Controllers\Api\OrderController;
use App\Http\Controllers\Api\MasterDataController;
use App\Http\Controllers\AuthController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\RegistrationController;

// Public registration endpoints
Route::post('/registration/register', [RegistrationController::class, 'register'])->middleware('throttle:signup');
Route::get('/registration/currencies', [RegistrationController::class, 'getCurrencies'])->middleware('throttle:public-api');
Route::get('/registration/timezones', [RegistrationController::class, 'getTimezones'])->middleware('throttle:public-api');
Route::get('/registration/check-code', [RegistrationController::class, 'checkAgencyCode'])->middleware('throttle:public-api');
Route::post('/auth/login', [AuthController::class, 'login'])->middleware('throttle:signin');


Route::middleware(['auth:sanctum', 'active.user'])->group(function () {
    Route::post('/auth/logout', [AuthController::class, 'logout'])->middleware('throttle:sensitive-write');

    // Auth user info
    Route::get('/user', function (Request $request) {
        return $request->user();
    });

    // ========== INVOICES ==========
    Route::prefix('invoices')->controller(InvoiceController::class)->group(function () {
        Route::get('/', 'index')->name('invoices.list');
        Route::post('/create-from-order', 'createFromOrder')->middleware(['role:admin,sales,accounts', 'throttle:sensitive-write'])->name('invoices.createFromOrder');
        Route::get('/{uid}', 'show')->name('invoices.show');
        Route::patch('/{uid}/mark-sent', 'markAsSent')->middleware(['role:admin,accounts', 'throttle:sensitive-write'])->name('invoices.markAsSent');
        Route::patch('/{uid}/void', 'void')->middleware(['role:admin,accounts', 'throttle:sensitive-write'])->name('invoices.void');
        Route::get('/{uid}/aging-status', 'agingStatus')->name('invoices.agingStatus');
    });

    // ========== ORDERS ==========
    Route::prefix('orders')->controller(OrderController::class)->group(function () {
        Route::get('/', 'index')->name('orders.list');
        Route::get('/{uid}', 'show')->name('orders.show');
        Route::patch('/{uid}', 'update')->middleware(['role:admin,sales', 'throttle:sensitive-write'])->name('orders.update');
        Route::delete('/{uid}', 'destroy')->middleware(['role:admin,sales', 'throttle:sensitive-write'])->name('orders.destroy');
        Route::post('/parse-gds', 'parseGds')->middleware(['role:admin,sales', 'throttle:sensitive-write'])->name('orders.parseGds');
        Route::post('/create-from-voucher', 'createFromVoucher')->middleware(['role:admin,sales', 'throttle:sensitive-write'])->name('orders.createFromVoucher');
    });

    // ========== MASTER DATA ==========
    Route::controller(MasterDataController::class)->group(function () {
        Route::get('/customers', 'customers')->name('customers.list');
        Route::post('/customers', 'storeCustomer')->middleware(['role:admin,sales,accounts', 'throttle:sensitive-write'])->name('customers.create');
        Route::get('/vendors', 'vendors')->name('vendors.list');
        Route::post('/vendors', 'storeVendor')->middleware(['role:admin,sales,accounts', 'throttle:sensitive-write'])->name('vendors.create');
    });

    // ========== PAYMENTS ==========
    Route::prefix('payments')->controller(PaymentController::class)->group(function () {
        Route::post('/record', 'recordPayment')->middleware(['role:admin,accounts', 'throttle:sensitive-write'])->name('payments.record');
        Route::post('/record-bulk', 'recordBulkPayment')->middleware(['role:admin,accounts', 'throttle:sensitive-write'])->name('payments.recordBulk');
        Route::get('/vendor', 'vendorPayments')->name('payments.vendor.list');
        Route::get('/vendor/{vendorId}/payables', 'vendorPayables')->name('payments.vendor.payables');
        Route::post('/vendor', 'recordVendorPayment')->middleware(['role:admin,accounts', 'throttle:sensitive-write'])->name('payments.vendor.record');
        Route::get('/customer', 'customerReceipts')->name('payments.customer.list');
        Route::post('/refund', 'recordRefund')->middleware(['role:admin,accounts', 'throttle:sensitive-write'])->name('payments.refund');
        Route::post('/advance', 'recordAdvance')->middleware(['role:admin,accounts', 'throttle:sensitive-write'])->name('payments.advance');
        Route::post('/apply-advance', 'applyAdvance')->middleware(['role:admin,accounts', 'throttle:sensitive-write'])->name('payments.applyAdvance');
        Route::get('/invoices/{invoiceUid}/settlements', 'settlements')->name('payments.settlements');
    });

    // ========== ACCOUNTS ==========
    Route::prefix('accounts')->controller(AccountController::class)->group(function () {
        // Cash accounts
        Route::get('/cash', 'cashAccounts')->name('accounts.cash.list');
        Route::post('/cash', 'createCashAccount')->middleware(['role:admin,accounts', 'throttle:sensitive-write'])->name('accounts.cash.create');

        // Bank accounts
        Route::get('/bank', 'bankAccounts')->name('accounts.bank.list');
        Route::post('/bank', 'createBankAccount')->middleware(['role:admin,accounts', 'throttle:sensitive-write'])->name('accounts.bank.create');

        // Balance & ledger
        Route::get('/{accountType}/{accountId}/balance', 'balance')->name('accounts.balance');
        Route::get('/{accountType}/{accountId}/ledger-entries', 'ledgerEntries')->name('accounts.ledgerEntries');
    });

    // ========== REPORTS ==========
    Route::prefix('reports')->controller(ReportController::class)->group(function () {
        Route::get('/aging', 'agingReport')->name('reports.aging');
        Route::get('/revenue', 'revenueReport')->name('reports.revenue');
        Route::get('/customer-summary', 'customerSummaryReport')->name('reports.customerSummary');
    });

    // ========== STATEMENTS ==========
    Route::prefix('statements')->controller(StatementController::class)->group(function () {
        Route::get('/customer/{customerId}', 'customerStatement')->name('statements.customer');
        Route::get('/vendor/{vendorId}', 'vendorStatement')->name('statements.vendor');
    });
});
