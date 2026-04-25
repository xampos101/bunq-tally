<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\BunqController;

Route::get('/', function () {
    return view('welcome');
});



Route::get('/bunq', function () {
    $bunq = new BunqController(new App\Services\BunqService());
    return $bunq->createPaymentRequest(new Illuminate\Http\Request([
        'amount' => 10.00,
        'description' => 'Test payment',
    ]));


});