<?php

namespace App\Http\Controllers;

use App\Mail\DonationReceipt;
use App\Models\Donation;
use App\Models\DonationPayment;
use App\Services\StripeGateway;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Stripe\Event;
use Stripe\Exception\SignatureVerificationException;
use Stripe\StripeObject;

class StripeWebhookController extends Controller
{
    public function __invoke(Request $request, StripeGateway $stripe): Response
    {
        try {
            $event = $stripe->constructEvent($request->getContent(), $request->header('Stripe-Signature'));
        } catch (SignatureVerificationException|\UnexpectedValueException) {
            return response('Invalid signature', 400);
        }

        DB::transaction(function () use ($event) {
            // Idempotency: if we've seen this event id before, do nothing.
            $isNew = DB::table('stripe_events')->insertOrIgnore([
                'id' => $event->id, 'type' => $event->type, 'created_at' => now(), 'updated_at' => now(),
            ]);

            if ($isNew) {
                $this->handle($event);
            }
        });

        // Any 2xx tells Stripe to stop retrying.
        return response('OK', 200);
    }

    private function handle(Event $event): void
    {
        $object = $event->data->object;

        match ($event->type) {
            'checkout.session.completed',
            'checkout.session.async_payment_succeeded' => $this->checkoutCompleted($object),
            'checkout.session.expired' => $this->checkoutExpired($object),
            'invoice.paid' => $this->invoicePaid($object),
            'invoice.payment_failed' => $this->setSubscriptionStatus($object, 'past_due'),
            'customer.subscription.deleted' => $this->setSubscriptionStatus($object, 'cancelled'),
            default => null,   // ignore events we don't use
        };
    }

    private function checkoutCompleted(StripeObject $session): void
    {
        $donation = Donation::find($session->metadata->donation_id ?? $session->client_reference_id);
        if (! $donation) {
            Log::warning('Checkout session for unknown donation', ['session' => $session->id]);
            return;
        }

        if ($session->mode === 'subscription') {
            // Monthly: remember the subscription. The receipt comes from invoice.paid, once per month.
            $donation->update([
                'stripe_subscription_id' => $session->subscription,
                'stripe_customer_id' => $session->customer,
                'status' => $donation->status === 'pending' ? 'active' : $donation->status,
            ]);
            return;
        }

        // One-off: some payment methods settle later, so only record it once Stripe says "paid".
        if ($session->payment_status !== 'paid') {
            return;
        }

        $donation->update(['status' => 'paid']);
        $this->recordPayment($donation, $session->payment_intent, $session->amount_total);
    }

    private function checkoutExpired(StripeObject $session): void
    {
        Donation::where('stripe_checkout_session_id', $session->id)
            ->where('status', 'pending')
            ->update(['status' => 'expired']);
    }

    private function invoicePaid(StripeObject $invoice): void
    {
        $donation = $this->donationForInvoice($invoice);
        if (! $donation) {
            Log::warning('Paid invoice for unknown donation', ['invoice' => $invoice->id]);
            return;
        }

        // invoice.paid can arrive before checkout.session.completed, so fill in the subscription here too.
        $donation->update([
            'stripe_subscription_id' => $donation->stripe_subscription_id ?? $this->subscriptionIdFor($invoice),
            'stripe_customer_id' => $donation->stripe_customer_id ?? $invoice->customer,
            'status' => 'active',
        ]);

        $this->recordPayment($donation, $invoice->id, $invoice->amount_paid);
    }

    /** Used for invoice.payment_failed (an invoice) and customer.subscription.deleted (a subscription). */
    private function setSubscriptionStatus(StripeObject $object, string $status): void
    {
        $donation = $object->object === 'subscription'
            ? Donation::find($object->metadata->donation_id ?? null)
                ?? Donation::where('stripe_subscription_id', $object->id)->first()
            : $this->donationForInvoice($object);

        $donation?->update(['status' => $status]);
    }

    private function donationForInvoice(StripeObject $invoice): ?Donation
    {
        // Newer Stripe API versions nest subscription info under invoice.parent; older ones put it at the top.
        $donationId = $invoice->parent->subscription_details->metadata->donation_id
            ?? $invoice->subscription_details->metadata->donation_id
            ?? null;

        return Donation::find($donationId)
            ?? Donation::where('stripe_subscription_id', $this->subscriptionIdFor($invoice))->first();
    }

    private function subscriptionIdFor(StripeObject $invoice): ?string
    {
        return $invoice->parent->subscription_details->subscription ?? $invoice->subscription ?? null;
    }

    private function recordPayment(Donation $donation, ?string $reference, int $amount): void
    {
        $payment = DonationPayment::firstOrCreate(
            ['stripe_reference' => $reference],
            ['donation_id' => $donation->id, 'amount' => $amount, 'paid_at' => now()],
        );

        // Only a brand-new payment gets a receipt, so a replayed webhook never emails twice.
        if ($payment->wasRecentlyCreated) {
            Mail::to($donation->email)->send(new DonationReceipt($payment));
        }
    }
}
