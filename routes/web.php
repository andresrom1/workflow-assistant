<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::get('/customers', [App\Http\Controllers\CustomerController::class, 'index'])->name('customers.index');
Route::get('/customers/{customer}', [App\Http\Controllers\CustomerController::class, 'show'])->name('customers.show');