<?php

use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;

Route::get('/', function () {
    // Guests see marketing home, authed users go to app dashboard.
    if (Auth::check()) return redirect()->route('dashboard');
    return Inertia::render('Marketing/Home');
})->name('home');

Route::get('/pricing', fn () => Inertia::render('Marketing/Pricing'))->name('pricing');
Route::get('/features', fn () => Inertia::render('Marketing/Features'))->name('features');

Route::middleware([
    'auth:sanctum',
    config('jetstream.auth_session'),
])->group(function () {
    Route::get('/dashboard', fn () => Inertia::render('Dashboard'))->name('dashboard');
});

Route::get('/options-calculator', fn () => Inertia::render('Options/Calculator'))
    ->middleware(['auth:sanctum'])
    ->name('options.calculator');

Route::get('/scanner', fn () => Inertia::render('Scanner'))
    ->name('options.scanner');
