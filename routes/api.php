<?php
use Illuminate\Support\Facades\Route;
use Illuminate\Http\Request;
use App\Http\Controllers\OrderController;
use App\Http\Controllers\PaymentController;
use App\Http\Controllers\API\AuthController;
use App\Http\Controllers\Item_categoryController;
use App\Http\Controllers\StockManagementController;
use App\Http\Controllers\CustomerManagementController;
use App\Http\Controllers\API\WhatsappMessageController;
use App\Http\Controllers\API\WhatsAppWebhookController;
use App\Http\Controllers\CountryController;
use App\Http\Controllers\BaleController;
use App\Http\Controllers\ContainerController;
use App\Http\Controllers\AgentInteractionController\GetMessageWebhookController;
use App\Http\Controllers\API\PickupController;
use App\Http\Controllers\API\InvoiceAmendmentController;
use App\Http\Controllers\SupplierManagement\SupplierController;
use App\Http\Controllers\API\ExpenseController;

Route::post('signup', [AuthController::class, 'register']);
Route::post('signin', [AuthController::class, 'login']);
Route::post('/signup/verify-otp',[AuthController::class,'register']);
Route::post('tokenCheck', [AuthController::class, 'tokenCheck']);
Route::post('/password-reset', [AuthController::class, 'passwordResetFlow']);
Route::get('Country',[CountryController::class,'countries']);

Route::post('/webhook/receive', [GetMessageWebhookController::class, 'receive']);

Route::post('whatsapp/customer', [WhatsAppWebhookController::class, 'customer']);
Route::post('whatsapp/admin',    [WhatsAppWebhookController::class, 'admin']);

Route::post('webhooks/paystack', [\App\Http\Controllers\API\PaystackWebhookController::class, 'handle']);

Route::middleware('auth:sanctum')->group(function () {
  Route::post('logout', [AuthController::class, 'logout']);
  Route::post('logoutall', [AuthController::class, 'logoutall']);
  Route::apiResource('containers', ContainerController::class);
  Route::get('bales/summary', [BaleController::class, 'summary']);
  Route::apiResource('bales', BaleController::class);
  Route::prefix('whatsapp')->group(function () {
    Route::get('/messages', [\App\Http\Controllers\API\WhatsAppLedgerController::class, 'index']);
    Route::get('/messages/{waId}', [\App\Http\Controllers\API\WhatsAppLedgerController::class, 'conversation'])->where('waId', '.*');
  });
  Route::prefix('Stocks')->group(function () { 
   Route::get('/list', [StockManagementController::class, 'index']);
   Route::post('/add', [StockManagementController::class, 'store']);
   Route::get('/show/{uuid}', [StockManagementController::class, 'show']);
   Route::put('/update/{uuid}', [StockManagementController::class, 'update']);
   Route::delete('/delete/{uuid}', [StockManagementController::class, 'destroy']);
   });  
  Route::prefix('category')->group(function () { 
   Route::get('/list', [Item_categoryController::class, 'index']);
   Route::post('/add', [Item_categoryController::class, 'store']);
   Route::get('/show/{uuid}', [Item_categoryController::class, 'show']);
   Route::put('/update/{uuid}', [Item_categoryController::class, 'update']);
   Route::delete('/delete/{uuid}', [Item_categoryController::class, 'destroy']);
   });
 
  Route::prefix('customers')->group(function (){
    Route::get('/', [CustomerManagementController::class, 'index']);
    Route::post('/', [CustomerManagementController::class, 'store']);
    Route::get('/{uuid}', [CustomerManagementController::class, 'show']);
    Route::put('/{uuid}', [CustomerManagementController::class, 'update']);
    Route::delete('/{uuid}', [CustomerManagementController::class, 'destroy']);
  });
  Route::prefix('orders')->group(function () {
    Route::get('/list', [OrderController::class, 'index']); 
    Route::post('/create', [OrderController::class, 'store']);
    Route::get('show/{uuid}', [OrderController::class, 'show']);   
  }); 
  Route::prefix('payment')->group(function(){
    Route::post('/create', [PaymentController::class, 'payment'])->name('payment');
    Route::post('/cash', [PaymentController::class, 'recordCash'])->name('payment.cash');
    Route::post('/verify', [PaymentController::class, 'verifyOnline'])->name('payment.verify');
    Route::get('/history', [PaymentController::class, 'paymentHistory'])->name('payment.history');
  }); 
  Route::prefix('invoices')->group(function(){
    Route::post('/amendment-request', [InvoiceAmendmentController::class, 'requestAmendment'])->name('invoices.amendment.request');
    Route::post('/amendment/{id}/approve', [InvoiceAmendmentController::class, 'approveAmendment'])->name('invoices.amendment.approve');
    Route::post('/amendment/{id}/reject', [InvoiceAmendmentController::class, 'rejectAmendment'])->name('invoices.amendment.reject');
    Route::get('/amendment/list', [InvoiceAmendmentController::class, 'listAmendments'])->name('invoices.amendment.list');
  });
  Route::prefix('pickup')->group(function(){
    Route::post('/validate', [PickupController::class, 'validateCode'])->name('pickup.validate');
    Route::post('/release', [PickupController::class, 'releaseBales'])->name('pickup.release');
  });
  Route::prefix('expenses')->group(function(){
    Route::post('/create', [ExpenseController::class, 'store'])->name('expenses.store');
    Route::get('/list', [ExpenseController::class, 'index'])->name('expenses.index');
    Route::get('/summary', [ExpenseController::class, 'summary'])->name('expenses.summary');
  });
  Route::prefix('bank-deposits')->group(function(){
    Route::post('/create', [\App\Http\Controllers\API\BankDepositController::class, 'store'])->name('bank_deposits.store');
    Route::get('/list', [\App\Http\Controllers\API\BankDepositController::class, 'index'])->name('bank_deposits.index');
    Route::get('/summary', [\App\Http\Controllers\API\BankDepositController::class, 'summary'])->name('bank_deposits.summary');
  });
  Route::prefix('reconciliation')->group(function(){
    Route::post('/eod/generate', [\App\Http\Controllers\API\EodReconciliationController::class, 'generate'])->name('reconciliation.eod.generate');
    Route::get('/eod/show', [\App\Http\Controllers\API\EodReconciliationController::class, 'show'])->name('reconciliation.eod.show');
  });
  Route::prefix('dashboard')->group(function(){
    Route::get('/overview', [\App\Http\Controllers\API\DashboardController::class, 'overview'])->name('dashboard.overview');
  });
  Route::prefix('agent')->group(function(){
    Route::post('/initialiseAgent',[WhatsappMessageController::class,'initialiseAgent']);
    Route::post('/storeAgentPrompt',[WhatsappMessageController::class,'storeAgentPrompt']);
    Route::get('/agentPrompt',[WhatsappMessageController::class,'getAgentPrompt']);
  });
  Route::prefix('supplier')->group(function () {
      Route::get('/list', [SupplierController::class, 'index']);
      Route::post('/add', [SupplierController::class, 'store']);
      Route::get('/show/{uuid}', [SupplierController::class, 'show']);
      Route::put('/update/{uuid}', [SupplierController::class, 'update']);
      Route::delete('/delete/{uuid}', [SupplierController::class, 'destroy']);
  });

});

// Route::post('/login', [AuthController::class, 'login'])->name('login');
// Route::middleware('auth:sanctum')->group(function () 
//     {

//     });
