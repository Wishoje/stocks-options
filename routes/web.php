<?php

use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Auth;
use App\Http\Controllers\BillingController;
use Laravel\Cashier\Http\Controllers\WebhookController;
use App\Http\Controllers\ProfileController;
use Inertia\Inertia;

Route::post('/stripe/webhook', [WebhookController::class, 'handleWebhook'])
    ->name('cashier.webhook');

Route::get('/', function () {
    $user = Auth::user();
    $subName = config('plans.default_subscription_name');

    if ($user && ($user->subscribed($subName) || $user->onTrial($subName))) {
        return redirect()->route('dashboard');
    }

    return Inertia::render('Marketing/Home');
})->name('home');

Route::get('/pricing', fn () => Inertia::render('Marketing/Pricing'))->name('pricing');
Route::get('/features', fn () => Inertia::render('Marketing/Features'))->name('features');

Route::middleware(['auth:sanctum', config('jetstream.auth_session')])->group(function () {
    Route::get('/checkout', [BillingController::class, 'checkout'])->name('billing.checkout');
    Route::get('/billing/success', [BillingController::class, 'success'])->name('billing.success');
    Route::get('/billing/portal', [BillingController::class, 'portal'])->name('billing.portal');
    Route::post('/billing/cancel', [BillingController::class, 'cancel'])->name('billing.cancel');
    Route::post('/billing/resume', [BillingController::class, 'resume'])->name('billing.resume'); // optional
    Route::get('/user/profile', [ProfileController::class, 'show'])->name('profile.show');

});

Route::middleware(['auth:sanctum', config('jetstream.auth_session'), 'subscribed'])->group(function () {
    Route::get('/dashboard', fn () => Inertia::render('Dashboard'))->name('dashboard');

    Route::get('/options-calculator', fn () => Inertia::render('Options/Calculator'))
        ->name('options.calculator');

    Route::get('/scanner', fn () => Inertia::render('Scanner'))
        ->name('options.scanner');
});
