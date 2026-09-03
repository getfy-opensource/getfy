<?php

use Plugins\AiMember\Http\Controllers\ChatController;
use Illuminate\Support\Facades\Route;

Route::get('history', [ChatController::class, 'history']);
Route::get('conversations', [ChatController::class, 'conversations']);
Route::post('conversations/new', [ChatController::class, 'startNew']);
Route::post('chat', [ChatController::class, 'send']);
