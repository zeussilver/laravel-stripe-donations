<?php

namespace App\Services;

use App\Models\Donation;
use Stripe\Checkout\Session;
use Stripe\Event;
use Stripe\StripeClient;
use Stripe\Webhook;

/**
 * The only class that talks to Stripe. Controllers depend on this,
 * so tests can swap it for a fake and never hit the network.
 */
class StripeGateway
{
    public function __construct(
        private ?string $secretKey,
        private ?string $webhookSecret,
    ) {}

    public function createCheckoutSession(Donation $donation, string $successUrl, string $cancelUrl): Session
    {
        $client = new StripeClient($this->secretKey);

        return $client->checkout->sessions->create(
            $this->checkoutParams($donation, $successUrl, $cancelUrl)
        );
    }

    /** Pure function: builds the request body, so it can be unit-tested without Stripe. */
    public function checkoutParams(Donation $donation, string $successUrl, string $cancelUrl): array
    {
        $priceData = [
            'currency' => $donation->currency,
            'unit_amount' => $donation->amount,
            'product_data' => [
                'name' => $donation->isMonthly() ? 'Monthly donation' : 'One-off donation',
            ],
        ];

        if ($donation->isMonthly()) {
            $priceData['recurring'] = ['interval' => 'month'];
        }

        $params = [
            'mode' => $donation->isMonthly() ? 'subscription' : 'payment',
            'customer_email' => $donation->email,
            'client_reference_id' => (string) $donation->id,
            'line_items' => [['price_data' => $priceData, 'quantity' => 1]],
            'metadata' => ['donation_id' => (string) $donation->id],
            'success_url' => $successUrl.'?session_id={CHECKOUT_SESSION_ID}',
            'cancel_url' => $cancelUrl,
        ];

        if ($donation->isMonthly()) {
            // Copied onto the subscription, and from there onto every monthly invoice.
            $params['subscription_data'] = ['metadata' => ['donation_id' => (string) $donation->id]];
        }

        return $params;
    }

    /** Throws \Stripe\Exception\SignatureVerificationException if the request did not come from Stripe. */
    public function constructEvent(string $payload, ?string $signature): Event
    {
        return Webhook::constructEvent($payload, (string) $signature, (string) $this->webhookSecret);
    }
}
