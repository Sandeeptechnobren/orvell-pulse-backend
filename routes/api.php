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
Route::post('signup', [AuthController::class, 'register']);
Route::post('signin', [AuthController::class, 'login']);
Route::post('/signup/verify-otp',[AuthController::class,'register']);
Route::post('tokenCheck', [AuthController::class, 'tokenCheck']);
//Route::post('password/change', [AuthController::class, 'changePassword']);
Route::post('/password-reset', [AuthController::class, 'passwordResetFlow']);
Route::get('Country',[CountryController::class,'countries']);

// WhatsApp inbound webhook (Chatterly → admin stock management / customer orders)
Route::post('whatsapp/webhook', [WhatsAppWebhookController::class, 'handle']);          // simple/test shape
Route::post('whatsapp/chatterly', [WhatsAppWebhookController::class, 'chatterly']);     // real Chatterly gateway payload

// Paystack payment webhook (charge.success → mark order paid + WhatsApp confirmation)
Route::post('webhooks/paystack', [\App\Http\Controllers\API\PaystackWebhookController::class, 'handle']);

Route::middleware('auth:sanctum')->group(function () {
  Route::post('logout', [AuthController::class, 'logout']);
  Route::post('logoutall', [AuthController::class, 'logoutall']);
   
Route::prefix('Stocks')->group(function () { 
   Route::get('/list', [StockManagementController::class, 'index']);
   Route::post('/add', [StockManagementController::class, 'store']);
   Route::get('/show/{uuid}', [StockManagementController::class, 'show']);
   Route::put('/update/{uuid}', [StockManagementController::class, 'update']);
   Route::delete('/delete/{uuid}', [StockManagementController::class, 'destroy']);
   });  
Route::prefix('item-category')->group(function () { 
   Route::get('/list', [Item_categoryController::class, 'index']);
   Route::post('/add', [Item_categoryController::class, 'store']);
   Route::get('/show/{uuid}', [Item_categoryController::class, 'show']);
   Route::put('/update/{uuid}', [Item_categoryController::class, 'update']);
   Route::delete('/delete/{uuid}', [Item_categoryController::class, 'destroy']);
   });
 
  Route::prefix('customer')->group(function (){
    Route::get('/list', [CustomerManagementController::class, 'index']);
    Route::post('/add', [CustomerManagementController::class, 'store']);
    Route::get('/show/{uuid}', [CustomerManagementController::class, 'show']);
    Route::put('/update/{uuid}', [CustomerManagementController::class, 'update']);
    Route::delete('/delete/{uuid}', [CustomerManagementController::class, 'destroy']);
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
    Route::post('/amendment-request', [\App\Http\Controllers\API\InvoiceAmendmentController::class, 'requestAmendment'])->name('invoices.amendment.request');
    Route::post('/amendment/{id}/approve', [\App\Http\Controllers\API\InvoiceAmendmentController::class, 'approveAmendment'])->name('invoices.amendment.approve');
    Route::post('/amendment/{id}/reject', [\App\Http\Controllers\API\InvoiceAmendmentController::class, 'rejectAmendment'])->name('invoices.amendment.reject');
    Route::get('/amendment/list', [\App\Http\Controllers\API\InvoiceAmendmentController::class, 'listAmendments'])->name('invoices.amendment.list');
  });
  Route::prefix('pickup')->group(function(){
    Route::post('/validate', [\App\Http\Controllers\API\PickupController::class, 'validateCode'])->name('pickup.validate');
    Route::post('/release', [\App\Http\Controllers\API\PickupController::class, 'releaseBales'])->name('pickup.release');
  });
  Route::prefix('expenses')->group(function(){
    Route::post('/create', [\App\Http\Controllers\API\ExpenseController::class, 'store'])->name('expenses.store');
    Route::get('/list', [\App\Http\Controllers\API\ExpenseController::class, 'index'])->name('expenses.index');
    Route::get('/summary', [\App\Http\Controllers\API\ExpenseController::class, 'summary'])->name('expenses.summary');
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

});

// Route::post('/login', [AuthController::class, 'login'])->name('login');
// Route::middleware('auth:sanctum')->group(function () 
//     {

//     });
