<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\AliExpressOrderController;
use App\Http\Controllers\Api\AmazonApiController;
/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider and all of them will
| be assigned to the "api" middleware group. Make something great!
|
*/

Route::middleware('auth:sanctum')->get('/user', function (Request $request) {
    return $request->user();
});

Route::post('/aliexpress/orders', [AliExpressOrderController::class, 'getAllOrders']);
Route::post('/aliexpress/product-list', [AliExpressOrderController::class, 'getProductList']);
Route::post('/aliexpress/product-detail', [AliExpressOrderController::class, 'getProductDetail']);
Route::post('/campaign-reports', [AmazonApiController::class, 'getCampaignReports']);
Route::post('/campaign-time-series', [AmazonApiController::class, 'getCampaignTimeSeries']);
