<?php

use App\Http\Controllers\DonationController;
use App\Http\Controllers\StripeWebhookController;
use Illuminate\Support\Facades\Route;

Route::get('/', [DonationController::class, 'create'])->name('donations.create');
Route::post('/donate', [DonationController::class, 'store'])->name('donations.store');
Route::get('/donate/success', [DonationController::class, 'success'])->name('donations.success');

Route::post('/stripe/webhook', StripeWebhookController::class)->name('stripe.webhook');
