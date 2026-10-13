<?php

declare(strict_types=1);

use App\Http\Controllers\HealthController;
use App\Modules\Cash\Http\Controllers\CashRegisterController;
use App\Modules\Cash\Http\Controllers\CashSessionController;
use App\Modules\Cash\Http\Controllers\PaymentLedgerController;
use App\Modules\Catalog\Http\Controllers\AttributeController;
use App\Modules\Catalog\Http\Controllers\BrandController;
use App\Modules\Catalog\Http\Controllers\CategoryController;
use App\Modules\Catalog\Http\Controllers\CustomerController;
use App\Modules\Catalog\Http\Controllers\ItemController;
use App\Modules\Catalog\Http\Controllers\ItemImportController;
use App\Modules\Catalog\Http\Controllers\PriceListController;
use App\Modules\Catalog\Http\Controllers\SupplierController;
use App\Modules\Catalog\Http\Controllers\VariantController;
use App\Modules\Fiscal\Http\Controllers\EfactWebhookController;
use App\Modules\Fiscal\Http\Controllers\FiscalDocumentController;
use App\Modules\Fiscal\Http\Controllers\FiscalSettingsController;
use App\Modules\Inventory\Http\Controllers\CountController;
use App\Modules\Inventory\Http\Controllers\InventoryController;
use App\Modules\Inventory\Http\Controllers\TransferController;
use App\Modules\Notifications\Http\Controllers\NotificationController;
use App\Modules\Platform\Http\Controllers\AuditLogController;
use App\Modules\Platform\Http\Controllers\AuthController;
use App\Modules\Platform\Http\Controllers\BranchController;
use App\Modules\Platform\Http\Controllers\CompanyController;
use App\Modules\Platform\Http\Controllers\PasswordResetController;
use App\Modules\Platform\Http\Controllers\PlanController;
use App\Modules\Platform\Http\Controllers\RoleController;
use App\Modules\Platform\Http\Controllers\UserController;
use App\Modules\Platform\Http\Controllers\WarehouseController;
use App\Modules\Purchasing\Http\Controllers\ExpenseController;
use App\Modules\Purchasing\Http\Controllers\PurchaseController;
use App\Modules\Reporting\Http\Controllers\DashboardController;
use App\Modules\Reporting\Http\Controllers\ReportController;
use App\Modules\Sales\Http\Controllers\OrderController;
use App\Modules\Sales\Http\Controllers\QuotationController;
use App\Modules\Sales\Http\Controllers\ReceivableController;
use App\Modules\Sales\Http\Controllers\SaleController;
use App\Modules\Sales\Http\Controllers\SaleReturnController;
use App\Modules\Shipping\Http\Controllers\CarrierController;
use App\Modules\Shipping\Http\Controllers\PublicTrackingController;
use App\Modules\Shipping\Http\Controllers\ShipmentController;
use App\Shared\Http\Idempotent;
use App\Shared\Http\ManagedRoutes;
use App\Shared\Tenancy\ResolveTenantContext;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Rutas de la API v1
|--------------------------------------------------------------------------
*/

Route::prefix('v1')->group(function () {
    Route::get('health', HealthController::class)->name('health');

    Route::post('auth/login', [AuthController::class, 'login'])
        ->middleware('throttle:10,1')
        ->name('auth.login');

    Route::post('auth/forgot-password', [PasswordResetController::class, 'forgot'])
        ->middleware('throttle:5,1')->name('auth.forgot');
    Route::post('auth/reset-password', [PasswordResetController::class, 'reset'])
        ->middleware('throttle:10,1')->name('auth.reset');

    Route::get('public/tracking/{token}', [PublicTrackingController::class, 'show'])
        ->middleware('throttle:60,1')->name('public.tracking');

    Route::post('webhooks/efact/{companyId}', EfactWebhookController::class)
        ->middleware('throttle:120,1')->name('webhooks.efact');

    Route::middleware([ResolveTenantContext::class, Idempotent::class])->group(function () {
        Route::get('auth/me', [AuthController::class, 'me'])->name('auth.me');
        Route::post('auth/logout', [AuthController::class, 'logout'])->name('auth.logout');

        Route::get('permissions', [RoleController::class, 'permissions'])
            ->middleware('permission:settings.role.view')->name('permissions.index');
        Route::get('roles', [RoleController::class, 'index'])
            ->middleware('permission:settings.role.view')->name('roles.index');
        Route::post('roles', [RoleController::class, 'store'])
            ->middleware('permission:settings.role.create')->name('roles.store');
        Route::put('roles/{id}', [RoleController::class, 'update'])
            ->middleware('permission:settings.role.update')->name('roles.update');
        Route::patch('roles/{id}/active', [RoleController::class, 'setActive'])
            ->middleware('permission:settings.role.update')->name('roles.active');
        Route::delete('roles/{id}', [RoleController::class, 'destroy'])
            ->middleware('permission:settings.role.delete')->name('roles.destroy');

        ManagedRoutes::register('companies', CompanyController::class, 'settings.company');
        ManagedRoutes::register('branches', BranchController::class, 'settings.branch');
        ManagedRoutes::register('warehouses', WarehouseController::class, 'settings.warehouse');

        ManagedRoutes::register('cash-registers', CashRegisterController::class, 'cash.register');
        Route::get('cash-sessions', [CashSessionController::class, 'index'])
            ->middleware('permission:cash.session.view')->name('cash-sessions.index');
        Route::get('cash-sessions/current', [CashSessionController::class, 'current'])
            ->middleware('permission:cash.session.view')->name('cash-sessions.current');
        Route::post('cash-sessions', [CashSessionController::class, 'open'])
            ->middleware('permission:cash.session.open')->name('cash-sessions.open');
        Route::get('cash-sessions/{id}', [CashSessionController::class, 'show'])
            ->middleware('permission:cash.session.view')->name('cash-sessions.show');
        Route::post('cash-sessions/{id}/movements', [CashSessionController::class, 'movement'])
            ->middleware('permission:cash.movement.create')->name('cash-sessions.movement');
        Route::post('cash-sessions/{id}/close', [CashSessionController::class, 'close'])
            ->middleware('permission:cash.session.close')->name('cash-sessions.close');

        ManagedRoutes::register('categories', CategoryController::class, 'catalog.category');
        ManagedRoutes::register('brands', BrandController::class, 'catalog.brand');
        ManagedRoutes::register('attributes', AttributeController::class, 'catalog.attribute');
        ManagedRoutes::register('customers', CustomerController::class, 'parties.customer');
        Route::get('items/lookup', [ItemController::class, 'lookup'])
            ->middleware('permission:catalog.item.view')->name('items.lookup');
        ManagedRoutes::register('suppliers', SupplierController::class, 'parties.supplier');

        Route::get('purchases', [PurchaseController::class, 'index'])
            ->middleware('permission:purchases.document.view')->name('purchases.index');
        Route::post('purchases', [PurchaseController::class, 'store'])
            ->middleware('permission:purchases.document.create')->name('purchases.store');
        Route::get('purchases/{id}', [PurchaseController::class, 'show'])
            ->middleware('permission:purchases.document.view')->name('purchases.show');
        Route::post('purchases/{id}/payments', [PurchaseController::class, 'pay'])
            ->middleware('permission:payments.payment.create')->name('purchases.pay');
        Route::post('purchases/{id}/returns', [PurchaseController::class, 'returnGoods'])
            ->middleware('permission:purchases.document.update')->name('purchases.returns');
        Route::post('purchases/{id}/void', [PurchaseController::class, 'void'])
            ->middleware('permission:purchases.document.delete')->name('purchases.void');
        Route::get('payables', [PurchaseController::class, 'payables'])
            ->middleware('permission:payments.payable.view')->name('payables.index');

        ManagedRoutes::register('carriers', CarrierController::class, 'shipping.carrier');
        Route::get('shipments', [ShipmentController::class, 'index'])
            ->middleware('permission:shipping.shipment.view')->name('shipments.index');
        Route::post('shipments', [ShipmentController::class, 'store'])
            ->middleware('permission:shipping.shipment.create')->name('shipments.store');
        Route::get('shipments/{id}', [ShipmentController::class, 'show'])
            ->middleware('permission:shipping.shipment.view')->name('shipments.show');
        Route::post('shipments/{id}/status', [ShipmentController::class, 'changeStatus'])
            ->middleware('permission:shipping.shipment.update')->name('shipments.status');

        ManagedRoutes::register('items', ItemController::class, 'catalog.item');
        ManagedRoutes::register('price-lists', PriceListController::class, 'catalog.price');
        Route::get('price-lists/{id}/items', [PriceListController::class, 'items'])
            ->middleware('permission:catalog.price.view')->name('price-lists.items');
        Route::put('price-lists/{id}/items', [PriceListController::class, 'saveItems'])
            ->middleware('permission:catalog.price.update')->name('price-lists.save-items');
        Route::post('prices/resolve', [PriceListController::class, 'resolve'])->name('prices.resolve');
        Route::post('items/{itemId}/variants', [VariantController::class, 'store'])
            ->middleware('permission:catalog.item.update')->name('variants.store');
        Route::post('items/{itemId}/variants/generate', [VariantController::class, 'generate'])
            ->middleware('permission:catalog.item.update')->name('variants.generate');
        Route::put('variants/{id}', [VariantController::class, 'update'])
            ->middleware('permission:catalog.item.update')->name('variants.update');
        Route::patch('variants/{id}/active', [VariantController::class, 'setActive'])
            ->middleware('permission:catalog.item.update')->name('variants.active');

        Route::get('sales', [SaleController::class, 'index'])
            ->middleware('permission:sales.document.view')->name('sales.index');
        Route::post('sales', [SaleController::class, 'store'])
            ->middleware('permission:sales.document.create')->name('sales.store');
        Route::get('sales/{id}', [SaleController::class, 'show'])
            ->middleware('permission:sales.document.view')->name('sales.show');
        Route::post('sales/{id}/void', [SaleController::class, 'void'])
            ->middleware('permission:sales.document.void')->name('sales.void');

        Route::post('import/items', [ItemImportController::class, 'store'])
            ->middleware('permission:import.run')->name('import.items');

        Route::get('expenses', [ExpenseController::class, 'index'])
            ->middleware('permission:purchases.expense.view')->name('expenses.index');
        Route::post('expenses', [ExpenseController::class, 'store'])
            ->middleware('permission:purchases.expense.create')->name('expenses.store');
        Route::post('expenses/{id}/void', [ExpenseController::class, 'void'])
            ->middleware('permission:purchases.expense.delete')->name('expenses.void');

        Route::get('sales/{id}/returns', [SaleReturnController::class, 'index'])
            ->middleware('permission:sales.document.view')->name('sales.returns.index');
        Route::post('sales/{id}/returns', [SaleReturnController::class, 'store'])
            ->middleware('permission:sales.document.void')->name('sales.returns.store');
        Route::get('quotations', [QuotationController::class, 'index'])
            ->middleware('permission:orders.quotation.view')->name('quotations.index');
        Route::post('quotations', [QuotationController::class, 'store'])
            ->middleware('permission:orders.quotation.create')->name('quotations.store');
        Route::get('quotations/{id}', [QuotationController::class, 'show'])
            ->middleware('permission:orders.quotation.view')->name('quotations.show');
        Route::post('quotations/{id}/reject', [QuotationController::class, 'reject'])
            ->middleware('permission:orders.quotation.update')->name('quotations.reject');
        Route::post('quotations/{id}/convert', [QuotationController::class, 'convert'])
            ->middleware('permission:orders.order.create')->name('quotations.convert');
        Route::get('orders', [OrderController::class, 'index'])
            ->middleware('permission:orders.order.view')->name('orders.index');
        Route::post('orders', [OrderController::class, 'store'])
            ->middleware('permission:orders.order.create')->name('orders.store');
        Route::get('orders/{id}', [OrderController::class, 'show'])
            ->middleware('permission:orders.order.view')->name('orders.show');
        Route::post('orders/{id}/advance', [OrderController::class, 'advance'])
            ->middleware('permission:orders.order.update')->name('orders.advance');
        Route::post('orders/{id}/deliver', [OrderController::class, 'deliver'])
            ->middleware('permission:orders.order.confirm')->name('orders.deliver');
        Route::post('orders/{id}/cancel', [OrderController::class, 'cancel'])
            ->middleware('permission:orders.order.cancel')->name('orders.cancel');

        Route::get('fiscal/settings/{companyId}', [FiscalSettingsController::class, 'show'])
            ->middleware('permission:settings.company.view')->name('fiscal.settings.show');
        Route::put('fiscal/settings/{companyId}', [FiscalSettingsController::class, 'update'])
            ->middleware('permission:settings.company.update')->name('fiscal.settings.update');
        Route::get('fiscal/documents', [FiscalDocumentController::class, 'index'])
            ->middleware('permission:fiscal.document.view')->name('fiscal.documents.index');
        Route::post('sales/{id}/emit', [FiscalDocumentController::class, 'emit'])
            ->middleware('permission:fiscal.document.emit')->name('fiscal.documents.emit');
        Route::post('fiscal/documents/{id}/retry', [FiscalDocumentController::class, 'retry'])
            ->middleware('permission:fiscal.queue.retry')->name('fiscal.documents.retry');

        Route::get('plan', [PlanController::class, 'show'])->name('plan.show');
        Route::get('collections', [PaymentLedgerController::class, 'collections'])
            ->middleware('permission:payments.collection.view')->name('collections.index');
        Route::get('payments', [PaymentLedgerController::class, 'payments'])
            ->middleware('permission:payments.payment.view')->name('payments.index');
        Route::get('users', [UserController::class, 'index'])
            ->middleware('permission:settings.user.view')->name('users.index');
        Route::post('users', [UserController::class, 'store'])
            ->middleware('permission:settings.user.create')->name('users.store');
        Route::put('users/{id}', [UserController::class, 'update'])
            ->middleware('permission:settings.user.update')->name('users.update');
        Route::patch('users/{id}/active', [UserController::class, 'setActive'])
            ->middleware('permission:settings.user.update')->name('users.active');

        Route::get('dashboard', DashboardController::class)->name('dashboard');
        Route::get('notifications/settings', [NotificationController::class, 'show'])
            ->middleware('permission:settings.notification.view')->name('notifications.settings');
        Route::put('notifications/settings', [NotificationController::class, 'update'])
            ->middleware('permission:settings.notification.update')->name('notifications.update');
        Route::get('notifications', [NotificationController::class, 'index'])
            ->middleware('permission:settings.notification.view')->name('notifications.index');
        Route::post('notifications/test', [NotificationController::class, 'test'])
            ->middleware('permission:settings.notification.update')->name('notifications.test');
        Route::post('sales/{id}/notify', [NotificationController::class, 'notifySale'])
            ->middleware('permission:sales.document.view')->name('sales.notify');
        Route::post('receivables/{id}/notify', [NotificationController::class, 'notifyDebt'])
            ->middleware('permission:payments.receivable.view')->name('receivables.notify');
        Route::post('orders/{id}/notify', [NotificationController::class, 'notifyOrder'])
            ->middleware('permission:orders.order.view')->name('orders.notify');
        Route::get('audit-log', [AuditLogController::class, 'index'])
            ->middleware('permission:audit.log.view')->name('audit-log.index');
        Route::get('reports/sales', [ReportController::class, 'sales'])
            ->middleware('permission:reports.view')->name('reports.sales');
        Route::get('reports/products', [ReportController::class, 'products'])
            ->middleware('permission:reports.view')->name('reports.products');

        Route::post('receivables/collect-party', [ReceivableController::class, 'collectParty'])
            ->middleware('permission:payments.collection.create')->name('receivables.collect-party');
        Route::get('receivables', [ReceivableController::class, 'index'])
            ->middleware('permission:payments.receivable.view')->name('receivables.index');
        Route::post('sales/{id}/payments', [ReceivableController::class, 'collect'])
            ->middleware('permission:payments.collection.create')->name('sales.collect');

        Route::get('inventory/stock', [InventoryController::class, 'stock'])
            ->middleware('permission:inventory.stock.view')->name('inventory.stock');
        Route::get('inventory/movements', [InventoryController::class, 'movements'])
            ->middleware('permission:inventory.movement.view')->name('inventory.movements');
        Route::post('inventory/counts', [CountController::class, 'store'])
            ->middleware('permission:inventory.count.create')->name('inventory.counts.store');
        Route::get('inventory/transfers', [TransferController::class, 'index'])
            ->middleware('permission:inventory.transfer.view')->name('inventory.transfers.index');
        Route::post('inventory/transfers', [TransferController::class, 'store'])
            ->middleware('permission:inventory.transfer.create')->name('inventory.transfers.store');
        Route::post('inventory/adjustments', [InventoryController::class, 'adjust'])
            ->middleware('permission:inventory.adjustment.create')->name('inventory.adjust');
    });
});
