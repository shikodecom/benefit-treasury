<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\BenefitAccountController;
use App\Http\Controllers\BenefitDashboardController;
use App\Http\Controllers\BenefitLedgerController;
use App\Http\Controllers\BenefitLotController;
use App\Http\Controllers\BenefitListingController;
use App\Http\Controllers\BenefitProgramController;
use App\Http\Controllers\BenefitTransactionController;
use App\Http\Controllers\HouseholdMemberController;
use Illuminate\Support\Facades\Route;

Route::get('/', fn () => redirect()->route('dashboard.index'));

Route::middleware('guest')->group(function (): void {
    Route::get('/login', [AuthController::class, 'showLogin'])->name('login');
    Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:5,1')->name('login.submit');
});

Route::middleware('auth')->group(function (): void {
    Route::post('/logout', [AuthController::class, 'logout'])->name('logout');
    Route::get('/dashboard', [BenefitDashboardController::class, 'index'])->name('dashboard.index');
    Route::post('/dashboard/lots/{lot}/policy', [BenefitDashboardController::class, 'updatePolicy'])->name('dashboard.lots.policy');
    Route::get('/benefits', [BenefitLedgerController::class, 'index'])->name('ledger.home');
    Route::get('/transactions', [BenefitTransactionController::class, 'index'])->name('ledger.transactions.index');
    Route::get('/transactions/create', [BenefitTransactionController::class, 'create'])->name('ledger.transactions.create');
    Route::post('/transactions', [BenefitTransactionController::class, 'store'])->name('ledger.transactions.store');
    Route::get('/transactions/{transaction}', [BenefitTransactionController::class, 'show'])->name('ledger.transactions.show');
    Route::post('/transactions/{transaction}/reverse', [BenefitTransactionController::class, 'reverse'])->name('ledger.transactions.reverse');
    Route::get('/benefit-accounts/{account}/transactions', [BenefitTransactionController::class, 'account'])->name('ledger.accounts.transactions');
    Route::get('/benefit-accounts/{account}/use', [BenefitTransactionController::class, 'useForm'])->name('ledger.accounts.use');
    Route::post('/benefit-accounts/{account}/use', [BenefitTransactionController::class, 'allocateUse'])->name('ledger.accounts.use.store');
    Route::get('/lots', [BenefitLotController::class, 'index'])->name('ledger.lots.index');
    Route::get('/lots/create', [BenefitLotController::class, 'create'])->name('ledger.lots.create');
    Route::post('/lots', [BenefitLotController::class, 'store'])->name('ledger.lots.store');
    Route::get('/lots/{lot}', [BenefitLotController::class, 'show'])->name('ledger.lots.show');
    Route::get('/lots/{lot}/edit', [BenefitLotController::class, 'edit'])->name('ledger.lots.edit');
    Route::put('/lots/{lot}', [BenefitLotController::class, 'update'])->name('ledger.lots.update');
    Route::post('/lots/{lot}/use', [BenefitLotController::class, 'use'])->name('ledger.lots.use');
    Route::post('/lots/{lot}/expire', [BenefitLotController::class, 'expire'])->name('ledger.lots.expire');
    Route::post('/lots/{lot}/cancel', [BenefitLotController::class, 'cancel'])->name('ledger.lots.cancel');
    Route::get('/listings', [BenefitListingController::class, 'index'])->name('listings.index');
    Route::get('/listings/create', [BenefitListingController::class, 'create'])->name('listings.create');
    Route::post('/listings', [BenefitListingController::class, 'store'])->name('listings.store');
    Route::get('/listings/{listing}', [BenefitListingController::class, 'show'])->name('listings.show');
    Route::get('/listings/{listing}/edit', [BenefitListingController::class, 'edit'])->name('listings.edit');
    Route::put('/listings/{listing}', [BenefitListingController::class, 'update'])->name('listings.update');
    Route::post('/listings/{listing}/publish', [BenefitListingController::class, 'publish'])->name('listings.publish');
    Route::post('/listings/{listing}/price', [BenefitListingController::class, 'changePrice'])->name('listings.price');
    Route::post('/listings/{listing}/cancel', [BenefitListingController::class, 'cancel'])->name('listings.cancel');
    Route::post('/listings/{listing}/end-unsold', [BenefitListingController::class, 'endUnsold'])->name('listings.end-unsold');
    Route::post('/listings/{listing}/relist', [BenefitListingController::class, 'relist'])->name('listings.relist');
    Route::get('/listings/{listing}/sell', [BenefitListingController::class, 'sellForm'])->name('listings.sell.form');
    Route::post('/listings/{listing}/sell', [BenefitListingController::class, 'sell'])->name('listings.sell');
    Route::post('/listings/{listing}/reverse-sale', [BenefitListingController::class, 'reverseSale'])->name('listings.reverse-sale');
    Route::view('/settings/benefits', 'settings.home')->name('settings.home');

    Route::get('/settings/household-members', [HouseholdMemberController::class, 'index'])->name('settings.members.index');
    Route::get('/settings/household-members/create', [HouseholdMemberController::class, 'create'])->name('settings.members.create');
    Route::post('/settings/household-members', [HouseholdMemberController::class, 'store'])->name('settings.members.store');
    Route::get('/settings/household-members/{member}/edit', [HouseholdMemberController::class, 'edit'])->name('settings.members.edit');
    Route::put('/settings/household-members/{member}', [HouseholdMemberController::class, 'update'])->name('settings.members.update');
    Route::post('/settings/household-members/{member}/toggle', [HouseholdMemberController::class, 'toggle'])->name('settings.members.toggle');

    Route::get('/settings/benefit-programs', [BenefitProgramController::class, 'index'])->name('settings.programs.index');
    Route::get('/settings/benefit-programs/create', [BenefitProgramController::class, 'create'])->name('settings.programs.create');
    Route::post('/settings/benefit-programs', [BenefitProgramController::class, 'store'])->name('settings.programs.store');
    Route::get('/settings/benefit-programs/{program}', [BenefitProgramController::class, 'show'])->name('settings.programs.show');
    Route::get('/settings/benefit-programs/{program}/edit', [BenefitProgramController::class, 'edit'])->name('settings.programs.edit');
    Route::put('/settings/benefit-programs/{program}', [BenefitProgramController::class, 'update'])->name('settings.programs.update');
    Route::post('/settings/benefit-programs/{program}/toggle', [BenefitProgramController::class, 'toggle'])->name('settings.programs.toggle');
    Route::post('/settings/benefit-programs/{program}/aliases', [BenefitProgramController::class, 'addAlias'])->name('settings.programs.aliases.store');
    Route::delete('/settings/benefit-programs/{program}/aliases/{alias}', [BenefitProgramController::class, 'removeAlias'])->name('settings.programs.aliases.destroy');

    Route::get('/settings/benefit-accounts', [BenefitAccountController::class, 'index'])->name('settings.accounts.index');
    Route::get('/settings/benefit-accounts/create', [BenefitAccountController::class, 'create'])->name('settings.accounts.create');
    Route::post('/settings/benefit-accounts', [BenefitAccountController::class, 'store'])->name('settings.accounts.store');
    Route::get('/settings/benefit-accounts/{account}', [BenefitAccountController::class, 'show'])->name('settings.accounts.show');
    Route::get('/settings/benefit-accounts/{account}/edit', [BenefitAccountController::class, 'edit'])->name('settings.accounts.edit');
    Route::put('/settings/benefit-accounts/{account}', [BenefitAccountController::class, 'update'])->name('settings.accounts.update');
    Route::post('/settings/benefit-accounts/{account}/toggle', [BenefitAccountController::class, 'toggle'])->name('settings.accounts.toggle');
});
