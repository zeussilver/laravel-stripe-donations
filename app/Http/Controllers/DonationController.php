<?php

namespace App\Http\Controllers;

use App\Models\Donation;
use App\Services\StripeGateway;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class DonationController extends Controller
{
    public function create(): View
    {
        return view('donations.create', ['presets' => [10, 25, 50, 100]]);
    }

    public function store(Request $request, StripeGateway $stripe): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'email' => ['required', 'email', 'max:255'],
            'amount' => ['required', 'numeric', 'min:2', 'max:10000', 'decimal:0,2'],
            'frequency' => ['required', Rule::in([Donation::ONE_TIME, Donation::MONTHLY])],
        ]);

        $donation = Donation::create([
            'name' => $data['name'],
            'email' => $data['email'],
            'amount' => (int) round($data['amount'] * 100),   // dollars -> cents
            'frequency' => $data['frequency'],
        ]);

        $session = $stripe->createCheckoutSession(
            $donation,
            route('donations.success'),
            route('donations.create'),
        );

        $donation->update(['stripe_checkout_session_id' => $session->id]);

        // Card details are entered on Stripe's hosted page, never on our server.
        return redirect()->away($session->url);
    }

    public function success(Request $request): View
    {
        // Show a thank-you page only. The webhook, not this redirect, decides whether the payment succeeded.
        $donation = Donation::where('stripe_checkout_session_id', $request->query('session_id'))->first();

        return view('donations.success', ['donation' => $donation]);
    }
}
