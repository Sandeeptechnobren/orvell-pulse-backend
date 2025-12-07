<?php
use Illuminate\Support\Facades\Route;
use Illuminate\Http\Request;
use App\Http\Controllers\OrderController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\StockManagementController;
use App\Http\Controllers\CustomerManagementController;

Route::post('signup', [AuthController::class, 'signup']);
Route::post('signin', [AuthController::class, 'signin']);

Route::middleware('auth:sanctum')->group(function () {
   Route::post('tokenCheck', [AuthController::class, 'tokenCheck']);
   Route::post('logout', [AuthController::class, 'logout']);
   Route::post('logoutall', [AuthController::class, 'logoutall']);
Route::prefix('item-category')->group(function () { 
   Route::get('/list', [StockManagementController::class, 'index']);
   Route::post('/add', [StockManagementController::class, 'store']);
   Route::get('/show/{uuid}', [StockManagementController::class, 'show']);
   Route::put('/update/{uuid}', [StockManagementController::class, 'update']);
   Route::delete('/destroy/{uuid}', [StockManagementController::class, 'destroy']);
   });
Route::prefix('customer')->group(function (){
   Route::get('/list', [CustomerManagementController::class, 'index']);
   Route::post('/add', [CustomerManagementController::class, 'store']);
   Route::get('/show/{uuid}', [CustomerManagementController::class, 'show']);
   Route::put('/update/{uuid}', [CustomerManagementController::class, 'update']);
   Route::delete('/destroy/{uuid}', [CustomerManagementController::class, 'destroy']);
   });

Route::prefix('orders')->group(function () {
   Route::get('/list', [OrderController::class, 'index']); 
   Route::get('show/{uuid}', [OrderController::class, 'show']);   
   });


});

// Route::post('/login', [AuthController::class, 'login'])->name('login');
// Route::middleware('auth:sanctum')->group(function () 
//     {

//     });
