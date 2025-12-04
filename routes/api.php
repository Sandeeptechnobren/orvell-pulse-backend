<?php
use Illuminate\Support\Facades\Route;
use Illuminate\Http\Request;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\StockManagementController;

Route::post('signup', [AuthController::class, 'signup']);
Route::post('signin', [AuthController::class, 'signin']);

Route::middleware('auth:sanctum')->group(function () {
   Route::prefix('item-category')->group(function () { 
    Route::get('/list', [StockManagementController::class, 'index']);
    Route::post('/add', [StockManagementController::class, 'store']);
    Route::get('/show/{id}', [StockManagementController::class, 'show']);
    Route::put('/update/{id}', [StockManagementController::class, 'update']);
    Route::delete('/destroy/{id}', [StockManagementController::class, 'destroy']);
    });

});

// Route::post('/login', [AuthController::class, 'login'])->name('login');
// Route::middleware('auth:sanctum')->group(function () 
//     {

//     });
