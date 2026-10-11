<?php

use App\Http\Controllers\DonationController;
use App\Http\Controllers\StripeWebhookController;
use Illuminate\Support\Facades\Route;

Route::get('/', [DonationController::class, 'create'])->name('donations.create');
// Each submission creates a Stripe Checkout Session, so a public demo limits how often one visitor can submit.
Route::post('/donate', [DonationController::class, 'store'])->middleware('throttle:10,1')->name('donations.store');
Route::get('/donate/success', [DonationController::class, 'success'])->name('donations.success');

Route::post('/stripe/webhook', StripeWebhookController::class)->name('stripe.webhook');
