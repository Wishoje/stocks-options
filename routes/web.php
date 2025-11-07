<?php

use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::get('/', function () {
    return redirect()->route('dashboard'); // guests -> /login, authed -> /dashboard
});

Route::middleware([
    'auth:sanctum',
    config('jetstream.auth_session'),
    // 'verified', // optional; keep/comment as you prefer
])->group(function () {
    Route::get('/dashboard', function () {
        return Inertia::render('Dashboard');
    })->name('dashboard');
});

Route::get('/options-calculator', function () {
    return Inertia::render('Options/Calculator');
})->middleware(['auth:sanctum'])->name('options.calculator');