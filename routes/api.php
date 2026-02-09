<?php
use Illuminate\Support\Facades\Route;
use Illuminate\Http\Request;
use App\Http\Controllers\OrderController;
use App\Http\Controllers\PaymentController;
use App\Http\Controllers\API\AuthController;
use App\Http\Controllers\Item_categoryController;
use App\Http\Controllers\StockManagementController;
use App\Http\Controllers\CustomerManagementController;
use App\Http\Controllers\WhatsappMessageController;
use App\Http\Controllers\CountryController;

Route::post('signup', [AuthController::class, 'register']);
Route::post('signin', [AuthController::class, 'login']);
Route::post('/signup/verify-otp',[AuthController::class,'register']);
Route::post('tokenCheck', [AuthController::class, 'tokenCheck']);
//Route::post('password/change', [AuthController::class, 'changePassword']);
Route::post('/password-reset', [AuthController::class, 'passwordResetFlow']);
Route::get('Country',[CountryController::class,'countries']);

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
    Route::get('show/{uuid}', [OrderController::class, 'show']);   
  }); 
  Route::prefix('payment')->group(function(){
    Route::post('/create', [PaymentController::class, 'payment'])->name('payment');
    Route::get('/history', [PaymentController::class, 'paymentHistory'])->name('payment.history');
  }); 
  Route::prefix('agent')->group(function(){
    Route::post('/initialiseAgent',[WhatsappMessageController::class,'initialiseAgent']);
    Route::post('/storeAgentPrompt',[WhatsappMessageController::class,'storeAgentPrompt']);
  });

});

// Route::post('/login', [AuthController::class, 'login'])->name('login');
// Route::middleware('auth:sanctum')->group(function () 
//     {

//     });
