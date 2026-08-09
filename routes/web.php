<?php

use App\Http\Controllers\Auth\AuthenticationController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth')->group(function (): void {
    Route::inertia('/', 'welcome')->name('home');

    Route::post('logout', [AuthenticationController::class, 'destroy'])->name('logout');
});

Route::middleware('guest')->group(function (): void {
    Route::get('login', [AuthenticationController::class, 'create'])->name('login');

    Route::get('auth/redirect', [AuthenticationController::class, 'redirect'])->name('auth.redirect');
    Route::get('auth/callback', [AuthenticationController::class, 'callback'])->name('auth.callback');
});
