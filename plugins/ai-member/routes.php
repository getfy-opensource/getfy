<?php

use Plugins\AiMember\Http\Controllers\AgentController;
use Plugins\AiMember\Http\Controllers\ConnectionController;
use Plugins\AiMember\Http\Controllers\SettingsController;
use Illuminate\Support\Facades\Route;

Route::get('/', [SettingsController::class, 'index']);

Route::get('connection', [ConnectionController::class, 'show']);
Route::put('connection', [ConnectionController::class, 'update']);
Route::post('connection/test', [ConnectionController::class, 'test']);

Route::get('agents/{product}', [AgentController::class, 'show']);
Route::put('agents/{product}', [AgentController::class, 'update']);
Route::post('agents/{product}/reindex', [AgentController::class, 'reindex']);
Route::post('agents/{product}/widget-icon', [AgentController::class, 'uploadWidgetIcon']);
Route::delete('agents/{product}/widget-icon', [AgentController::class, 'removeWidgetIcon']);
Route::post('agents/{product}/documents', [AgentController::class, 'storeDocument']);
Route::delete('agents/{product}/documents/{documentId}', [AgentController::class, 'destroyDocument']);
