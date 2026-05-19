<?php

use App\Http\Controllers\DashboardController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/', [DashboardController::class, 'index'])->name('dashboard');
Route::post('/dispatch', [DashboardController::class, 'dispatch'])->name('dispatch');

Route::get('/headers', fn (Request $request) => $request->headers->all())->name('headers');
