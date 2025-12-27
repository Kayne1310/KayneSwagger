<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\UserController;

// Đăng ký middleware dto để tự động validate
Route::middleware(['dto'])->group(function () {

    Route::post('/api/users', [UserController::class, 'store']);
    Route::get('/api/users/{id}', [UserController::class, 'show']);
    Route::get('/api/users', [UserController::class, 'index']);

});
