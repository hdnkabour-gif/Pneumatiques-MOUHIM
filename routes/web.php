<?php

use App\Http\Controllers\PrintController;
use App\Http\Controllers\SaleDocumentController;
use App\Http\Controllers\SwitchLocaleController;
use App\Http\Middleware\SetLocale;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/admin');

Route::get('/locale/{locale}', SwitchLocaleController::class)
    ->middleware('throttle:30,1')
    ->name('locale.switch');

Route::middleware(['auth', SetLocale::class])
    ->prefix('admin/print')
    ->group(function () {
        Route::get('transfers/{transfer}', [PrintController::class, 'transfer'])->name('transfers.print');
        Route::get('used-tyres/{usedTyre}/label', [PrintController::class, 'usedTyreLabel'])->name('used-tyres.label');
        Route::get('sales/{sale}/pdf', [SaleDocumentController::class, 'show'])->name('sales.pdf');
    });

// Invoice link sent to the customer (WhatsApp): signed and time-limited, no account needed.
Route::get('/d/invoice/{sale}', [SaleDocumentController::class, 'public'])
    ->middleware(['signed', 'throttle:30,1'])
    ->name('sales.public-pdf');
