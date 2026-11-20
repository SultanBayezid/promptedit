<?php

use App\Http\Controllers\Admin\AdminPanelController;
use App\Http\Controllers\Admin\FinancialReportController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\ProfileController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

// Guests land on /login via the auth middleware; users on their dashboard.
Route::redirect('/', '/dashboard', 302)->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');
});

Route::middleware(['auth', 'verified'])->group(function () {
    // Generate + estimate
    Route::get('/generate', [\App\Http\Controllers\GenerationController::class, 'create'])->name('generate');
    Route::post('/generate', [\App\Http\Controllers\GenerationController::class, 'store'])->name('generate.store');
    Route::post('/generate/estimate', [\App\Http\Controllers\GenerationController::class, 'estimate'])->name('generate.estimate');

    // History + detail
    Route::get('/generations', [\App\Http\Controllers\GenerationController::class, 'index'])->name('generations.index');
    Route::get('/generations/{uuid}', [\App\Http\Controllers\GenerationController::class, 'show'])
        ->whereUuid('uuid')
        ->name('generations.show');

    // Credits (bonus)
    Route::get('/credits', [\App\Http\Controllers\CreditController::class, 'index'])->name('credits');
    Route::get('/credits/purchase', [\App\Http\Controllers\CreditController::class, 'purchaseForm'])->name('credits.purchase');
    Route::post('/credits/purchase', [\App\Http\Controllers\CreditController::class, 'purchase'])->name('credits.purchase.store');
});

Route::middleware(['auth', 'verified', 'can:admin'])->prefix('admin')->group(function () {
    Route::get('/', [AdminPanelController::class, 'index'])->name('admin.panel');
    Route::get('/financial', [FinancialReportController::class, 'index'])->name('admin.financial');
    Route::post('/financial/reconcile', [FinancialReportController::class, 'reconcile'])->name('admin.financial.reconcile');
});

Route::middleware('auth')->group(function () {
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
});

require __DIR__.'/auth.php';
