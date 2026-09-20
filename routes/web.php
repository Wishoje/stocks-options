<?php

use App\Http\Controllers\BillingController;
use App\Http\Controllers\ContactController;
use App\Http\Controllers\EodHealthController;
use App\Http\Controllers\FirstUsefulReadingController;
use App\Http\Controllers\LegalController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\RegistrationHandoffController;
use App\Support\ProductAccess;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;
use Laravel\Cashier\Http\Controllers\WebhookController;

Route::post('/stripe/webhook', [WebhookController::class, 'handleWebhook'])
    ->name('cashier.webhook');

Route::get('/', function () {
    $user = Auth::user();
    if (ProductAccess::for($user)['has_access']) {
        return redirect()->route('dashboard');
    }

    return Inertia::render('Marketing/Home');
})->name('home');

Route::get('/pricing', fn () => Inertia::render('Marketing/Pricing'))->name('pricing');
Route::get('/features', fn () => Inertia::render('Marketing/Features'))->name('features');
Route::get('/contact', fn () => Inertia::render('Marketing/Contact'))->name('contact');
Route::post('/contact', [ContactController::class, 'store'])
    ->middleware('throttle:10,1')
    ->name('contact.submit');
Route::get('/terms-of-service', [LegalController::class, 'terms'])->name('terms.show');
Route::get('/privacy-policy', [LegalController::class, 'policy'])->name('policy.show');

Route::prefix('admin/social')->name('social.')->middleware(['auth:sanctum', config('jetstream.auth_session'), \App\Http\Middleware\EnsureSocialAdmin::class])->group(function () {
    Route::get('/', [\App\Http\Controllers\SocialPostController::class, 'index'])->name('index');
    Route::post('/generate', [\App\Http\Controllers\SocialPostController::class, 'generate'])->middleware('throttle:6,1')->name('generate');
    Route::put('/settings', [\App\Http\Controllers\SocialPostController::class, 'settings'])->name('settings');
    Route::post('/verify', [\App\Http\Controllers\SocialPostController::class, 'verify'])->middleware('throttle:3,1')->name('verify');
    Route::put('/{post}', [\App\Http\Controllers\SocialPostController::class, 'update'])->name('update');
    Route::post('/{post}/approve', [\App\Http\Controllers\SocialPostController::class, 'approve'])->name('approve');
    Route::post('/{post}/publish', [\App\Http\Controllers\SocialPostController::class, 'publish'])->middleware('throttle:3,1')->name('publish');
    Route::get('/{post}/image', [\App\Http\Controllers\SocialPostController::class, 'image'])->name('image');
    Route::get('/{post}/snapshot', [\App\Http\Controllers\SocialPostController::class, 'snapshot'])->name('snapshot');
});

Route::middleware(['auth:sanctum', config('jetstream.auth_session')])->group(function () {
    Route::get('/checkout', [BillingController::class, 'checkout'])->name('billing.checkout');
    Route::get('/billing/success', [BillingController::class, 'success'])->name('billing.success');
    Route::get('/billing/status', [BillingController::class, 'status'])->name('billing.status');
    Route::get('/registration/complete', RegistrationHandoffController::class)->name('registration.handoff');
    Route::get('/billing/portal', [BillingController::class, 'portal'])->name('billing.portal');
    Route::post('/billing/cancel', [BillingController::class, 'cancel'])->name('billing.cancel');
    Route::post('/billing/resume', [BillingController::class, 'resume'])->name('billing.resume'); // optional
    Route::get('/user/profile', [ProfileController::class, 'show'])->name('profile.show');

});

Route::middleware(['auth:sanctum', config('jetstream.auth_session'), 'subscribed'])->group(function () {
    Route::get('/dashboard', fn () => Inertia::render('Dashboard'))->name('dashboard');

    Route::post('/product-events/first-useful-reading', FirstUsefulReadingController::class)
        ->middleware('throttle:10,1')
        ->name('product-events.first-useful-reading');

    Route::get('/options-calculator', fn () => Inertia::render('Options/Calculator'))
        ->name('options.calculator');

    Route::get('/scanner', fn () => Inertia::render('Scanner'))
        ->name('options.scanner');

    Route::get('/ai-export', fn () => Inertia::render('AiExport'))
        ->name('options.ai-export');

    Route::get('/eod-health', [EodHealthController::class, 'page'])
        ->middleware('eodhealth')
        ->name('eod.health');
});
