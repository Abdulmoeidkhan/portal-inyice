<?php

use App\Http\Controllers\Api\InvoiceController;
use App\Http\Controllers\Api\PaymentController;
use App\Http\Controllers\Api\AccountController;
use App\Http\Controllers\Api\ReportController;
use App\Http\Controllers\Api\StatementController;
use App\Http\Controllers\Api\OrderController;
use App\Http\Controllers\Api\MasterDataController;
use App\Http\Controllers\Api\CompanyUserController;
use App\Http\Controllers\Api\CompanyProfileController;
use App\Http\Controllers\Api\InternalPortalController;
use App\Http\Controllers\Api\ReferenceSearchController;
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
Route::get('/shared-invoices/{token}', [InvoiceController::class, 'shared'])
    ->middleware('throttle:public-api')
    ->name('sharedInvoices.show');
Route::get('/shared-vouchers/{token}', [OrderController::class, 'shared'])
    ->middleware('throttle:public-api')
    ->name('sharedVouchers.show');


Route::middleware(['auth:sanctum', 'active.user'])->group(function () {
    Route::post('/auth/logout', [AuthController::class, 'logout'])->middleware('throttle:sensitive-write');

    // Auth user info
    Route::get('/user', function (Request $request) {
        return $request->user();
    });

    Route::prefix('company-users')->controller(CompanyUserController::class)->group(function () {
        Route::get('/', 'index')->middleware('role:owner,admin')->name('companyUsers.list');
        Route::post('/', 'store')->middleware(['role:owner,admin', 'throttle:sensitive-write'])->name('companyUsers.create');
        Route::patch('/{uid}', 'update')->middleware(['role:owner,admin', 'throttle:sensitive-write'])->name('companyUsers.update');
        Route::delete('/{uid}', 'destroy')->middleware(['role:owner,admin', 'throttle:sensitive-write'])->name('companyUsers.delete');
    });

    Route::get('/company-profile', [CompanyProfileController::class, 'show'])->name('companyProfile.show');
    Route::post('/company-profile', [CompanyProfileController::class, 'update'])
        ->middleware(['role:owner', 'throttle:sensitive-write'])
        ->name('companyProfile.update');

    Route::prefix('internal')->controller(InternalPortalController::class)->middleware('system-role:super-admin,inyice-admin,support-executive')->group(function () {
        Route::get('/companies', 'companies')->name('internal.companies');
        Route::get('/companies/{uid}', 'company')->name('internal.companies.show');
        Route::patch('/companies/{uid}/limits', 'updateCompanyLimits')->middleware('throttle:sensitive-write')->name('internal.companies.limits');
        Route::patch('/companies/{uid}/status', 'updateCompanyStatus')->middleware(['system-role:super-admin', 'throttle:sensitive-write'])->name('internal.companies.status');
        Route::get('/orders/{uid}', 'order')->name('internal.orders.show');
        Route::get('/invoices/{uid}', 'invoice')->name('internal.invoices.show');
        Route::get('/users', 'internalUsers')->name('internal.users');
        Route::post('/users', 'createInternalUser')->middleware(['system-role:super-admin', 'throttle:sensitive-write'])->name('internal.users.create');
        Route::patch('/users/{uid}/status', 'updateUserStatus')->middleware(['system-role:super-admin', 'throttle:sensitive-write'])->name('internal.users.status');
        Route::post('/users/{uid}/password', 'resetUserPassword')->middleware(['system-role:super-admin', 'throttle:sensitive-write'])->name('internal.users.password');
        Route::post('/profile/password', 'updatePassword')->middleware('throttle:sensitive-write')->name('internal.profile.password');
    });

    // ========== INVOICES ==========
    Route::prefix('invoices')->controller(InvoiceController::class)->group(function () {
        Route::get('/', 'index')->name('invoices.list');
        Route::post('/create-from-order', 'createFromOrder')->middleware(['role:admin,sales,accounts', 'throttle:sensitive-write'])->name('invoices.createFromOrder');
        Route::post('/{uid}/share', 'share')->middleware(['role:admin,sales,accounts', 'throttle:sensitive-write'])->name('invoices.share');
        Route::delete('/{uid}/share', 'revokeShare')->middleware(['role:admin,sales,accounts', 'throttle:sensitive-write'])->name('invoices.share.revoke');
        Route::get('/{uid}', 'show')->name('invoices.show');
        Route::patch('/{uid}/mark-sent', 'markAsSent')->middleware(['role:admin,accounts', 'throttle:sensitive-write'])->name('invoices.markAsSent');
        Route::patch('/{uid}/discount', 'discount')->middleware(['role:admin,accounts', 'throttle:sensitive-write'])->name('invoices.discount');
        Route::patch('/{uid}/void', 'void')->middleware(['role:admin,accounts', 'throttle:sensitive-write'])->name('invoices.void');
        Route::patch('/{uid}/cancel', 'cancel')->middleware(['role:admin,accounts', 'throttle:sensitive-write'])->name('invoices.cancel');
        Route::get('/{uid}/aging-status', 'agingStatus')->name('invoices.agingStatus');
    });

    // ========== ORDERS ==========
    Route::prefix('orders')->controller(OrderController::class)->group(function () {
        Route::get('/', 'index')->name('orders.list');
        Route::post('/{uid}/share', 'share')->middleware(['role:admin,sales', 'throttle:sensitive-write'])->name('orders.share');
        Route::delete('/{uid}/share', 'revokeShare')->middleware(['role:admin,sales', 'throttle:sensitive-write'])->name('orders.share.revoke');
        Route::post('/{uid}/refund-request', 'createRefundRequest')->middleware(['role:admin,sales,accounts', 'throttle:sensitive-write'])->name('orders.refundRequest');
        Route::post('/{uid}/recreate', 'recreateCancelled')->middleware(['role:admin,sales,accounts', 'throttle:sensitive-write'])->name('orders.recreateCancelled');
        Route::get('/{uid}', 'show')->name('orders.show');
        Route::patch('/{uid}', 'update')->middleware(['role:admin,sales,accounts', 'throttle:sensitive-write'])->name('orders.update');
        Route::delete('/{uid}', 'destroy')->middleware(['role:admin', 'throttle:sensitive-write'])->name('orders.destroy');
        Route::post('/parse-gds', 'parseGds')->middleware(['role:admin,sales', 'throttle:sensitive-write'])->name('orders.parseGds');
        Route::post('/create-from-voucher', 'createFromVoucher')->middleware(['role:admin,sales', 'throttle:sensitive-write'])->name('orders.createFromVoucher');
    });

    // ========== MASTER DATA ==========
    Route::controller(MasterDataController::class)->group(function () {
        Route::get('/customers', 'customers')->name('customers.list');
        Route::post('/customers', 'storeCustomer')->middleware(['role:admin,sales,accounts', 'throttle:sensitive-write'])->name('customers.create');
        Route::patch('/customers/{uid}', 'updateCustomer')->middleware(['role:admin,sales,accounts', 'throttle:sensitive-write'])->name('customers.update');
        Route::delete('/customers/{uid}', 'deleteCustomer')->middleware(['role:admin,sales,accounts', 'throttle:sensitive-write'])->name('customers.delete');
        Route::get('/vendors', 'vendors')->name('vendors.list');
        Route::post('/vendors', 'storeVendor')->middleware(['role:admin,sales,accounts', 'throttle:sensitive-write'])->name('vendors.create');
        Route::patch('/vendors/{uid}', 'updateVendor')->middleware(['role:admin,sales,accounts', 'throttle:sensitive-write'])->name('vendors.update');
        Route::delete('/vendors/{uid}', 'deleteVendor')->middleware(['role:admin,sales,accounts', 'throttle:sensitive-write'])->name('vendors.delete');
        Route::get('/staff', 'staff')->name('staff.list');
    });

    Route::get('/reference-search', [ReferenceSearchController::class, 'index'])->name('referenceSearch.index');

    // ========== PAYMENTS ==========
    Route::prefix('payments')->controller(PaymentController::class)->group(function () {
        Route::get('/vendor', 'vendorPayments')->name('payments.vendor.list');
        Route::get('/vendor/payment/{uid}', 'showVendorPayment')->name('payments.vendor.show');
        Route::patch('/vendor/payment/{uid}', 'updateVendorPayment')->middleware(['role:admin,accounts', 'throttle:sensitive-write'])->name('payments.vendor.update');
        Route::delete('/vendor/payment/{uid}', 'deleteVendorPayment')->middleware(['role:admin,accounts', 'throttle:sensitive-write'])->name('payments.vendor.delete');
        Route::get('/vendor/{vendorId}/payables', 'vendorPayables')->name('payments.vendor.payables');
        Route::post('/vendor', 'recordVendorPayment')->middleware(['role:admin,accounts', 'throttle:sensitive-write'])->name('payments.vendor.record');
        Route::get('/customer', 'customerPayments')->name('payments.customer.list');
        Route::post('/customer', 'recordCustomerPayment')->middleware(['role:admin,accounts', 'throttle:sensitive-write'])->name('payments.customer.record');
        Route::delete('/customer/{uid}', 'deleteCustomerPayment')->middleware(['role:admin,accounts', 'throttle:sensitive-write'])->name('payments.customer.delete');
        Route::patch('/customer/{uid}', 'updateCustomerPayment')->middleware(['role:admin,accounts', 'throttle:sensitive-write'])->name('payments.customer.update');
        Route::post('/customer/refund', 'recordRefund')->middleware(['role:admin,accounts', 'throttle:sensitive-write'])->name('payments.customer.refund');
        Route::post('/apply-advance', 'applyAdvance')->middleware(['role:admin,accounts', 'throttle:sensitive-write'])->name('payments.applyAdvance');
        Route::get('/invoices/{invoiceUid}/settlements', 'settlements')->name('payments.settlements');
    });

    Route::prefix('receipts')->controller(PaymentController::class)->group(function () {
        Route::post('/customer/record', 'recordCustomerReceipt')->middleware(['role:admin,accounts', 'throttle:sensitive-write'])->name('receipts.customer.record');
        Route::post('/customer/record-bulk', 'recordBulkCustomerReceipt')->middleware(['role:admin,accounts', 'throttle:sensitive-write'])->name('receipts.customer.recordBulk');
        Route::post('/customer/advance', 'recordAdvance')->middleware(['role:admin,accounts', 'throttle:sensitive-write'])->name('receipts.customer.advance');
        Route::get('/customer', 'customerReceipts')->name('receipts.customer.list');
        Route::get('/customer/{uid}', 'showCustomerReceipt')->name('receipts.customer.show');
        Route::patch('/customer/{uid}', 'updateCustomerReceipt')->middleware(['role:admin,accounts', 'throttle:sensitive-write'])->name('receipts.customer.update');
        Route::delete('/customer/{uid}', 'deleteCustomerReceipt')->middleware(['role:admin,accounts', 'throttle:sensitive-write'])->name('receipts.customer.delete');
        Route::get('/vendor', 'vendorReceipts')->name('receipts.vendor.list');
        Route::post('/vendor', 'recordVendorReceipt')->middleware(['role:admin,accounts', 'throttle:sensitive-write'])->name('receipts.vendor.record');
        Route::delete('/vendor/{uid}', 'deleteVendorReceipt')->middleware(['role:admin,accounts', 'throttle:sensitive-write'])->name('receipts.vendor.delete');
        Route::patch('/vendor/{uid}', 'updateVendorReceipt')->middleware(['role:admin,accounts', 'throttle:sensitive-write'])->name('receipts.vendor.update');
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
        Route::get('/dashboard-upcoming', 'dashboardUpcoming')->name('reports.dashboardUpcoming');
        Route::get('/cancelled', 'cancelledReport')->name('reports.cancelled');
    });

    Route::prefix('reports')->controller(ReportController::class)->middleware('role:admin,accounts')->group(function () {
        Route::get('/aging', 'agingReport')->name('reports.aging');
        Route::get('/revenue', 'revenueReport')->name('reports.revenue');
        Route::get('/profit', 'profitReport')->name('reports.profit');
        Route::get('/payments', 'paymentReport')->name('reports.payments');
        Route::get('/receipts', 'receiptReport')->name('reports.receipts');
        Route::get('/customer-summary', 'customerSummaryReport')->name('reports.customerSummary');
    });

    // ========== STATEMENTS ==========
    Route::prefix('statements')->controller(StatementController::class)->middleware('role:admin,accounts')->group(function () {
        Route::get('/customers', 'allCustomerStatement')->name('statements.customers');
        Route::get('/customer/{customerId}', 'customerStatement')->name('statements.customer');
        Route::get('/vendor/{vendorId}', 'vendorStatement')->name('statements.vendor');
    });
});
