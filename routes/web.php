<?php

use App\Http\Controllers\SwitchLocaleController;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/admin');

Route::get('/locale/{locale}', SwitchLocaleController::class)
    ->middleware('throttle:30,1')
    ->name('locale.switch');
