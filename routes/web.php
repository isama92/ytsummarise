<?php

use App\Http\Controllers\Auth\AuthenticationController;
use App\Http\Controllers\Auth\FirstUserController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth')->group(function (): void {
    Route::inertia('/', 'welcome')->name('home');

    Route::post('logout', [AuthenticationController::class, 'destroy'])->name('logout');
});

Route::middleware('guest')->group(function (): void {
    Route::get('login', [AuthenticationController::class, 'create'])->name('login');

    Route::get('auth/redirect', [AuthenticationController::class, 'redirect'])->name('auth.redirect');
    Route::get('auth/callback', [AuthenticationController::class, 'callback'])->name('auth.callback');

    /*
     * Only reachable while AUTH_ENABLED is false and no user exists; the controller
     * answers 404 otherwise. Registered in both modes so the route table, and the
     * redirect AuthenticateAsFirstUser sends guests to, never depend on configuration.
     */
    Route::get('first-user', [FirstUserController::class, 'create'])->name('first-user.create');
    Route::post('first-user', [FirstUserController::class, 'store'])->name('first-user.store');
});
