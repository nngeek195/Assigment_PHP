<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\API\AuthController;
use App\Http\Controllers\API\ServiceRequestController;

Route::post('/login', [AuthController::class, 'login']);

Route::middleware('auth:sanctum')->group(function () {

    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/me', [AuthController::class, 'me']);

    Route::get('/requests', [ServiceRequestController::class, 'index']);
    Route::post('/requests', [ServiceRequestController::class, 'store']);

    Route::get('/requests/{serviceRequest}', [ServiceRequestController::class, 'show']);

    Route::put('/requests/{serviceRequest}', [ServiceRequestController::class, 'update']);

    Route::post('/requests/{serviceRequest}/submit', [ServiceRequestController::class, 'submit']);

    Route::post('/requests/{serviceRequest}/decision', [ServiceRequestController::class, 'decide']);

    Route::post('/requests/{serviceRequest}/assign', [ServiceRequestController::class, 'assign']);

    Route::post('/requests/{serviceRequest}/status', [ServiceRequestController::class, 'changeStatus']);

    Route::post('/requests/{serviceRequest}/comments', [ServiceRequestController::class, 'comment']);
});