<?php

use App\Http\Controllers\DashboardController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/', [DashboardController::class, 'index'])->name('dashboard');
Route::post('/dispatch', [DashboardController::class, 'dispatch'])->name('dispatch');
Route::post('/workers/reset', [DashboardController::class, 'resetWorkers'])->name('workers.reset');
Route::post('/reset', [DashboardController::class, 'reset'])->name('reset');

Route::get('/headers', fn (Request $request) => $request->headers->all())->name('headers');

Route::get('/sleep', function () {
    sleep(10);

    return ['slept' => 10];
})->name('sleep');
