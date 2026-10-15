<?php

use App\Http\Controllers\UserController;
use Illuminate\Support\Facades\Route;

Route::middleware(['api'])->group(function () {
    Route::get('users', [UserController::class, 'index'])->name('users.index');
    Route::get('users/{id}', [UserController::class, 'show'])
        ->middleware('auth:sanctum')
        ->name('users.show');
    Route::post('users', [UserController::class, 'store'])
        ->middleware('auth:sanctum')
        ->name('users.store');
});
