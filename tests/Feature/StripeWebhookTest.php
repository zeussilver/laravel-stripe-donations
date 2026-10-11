<?php

namespace Tests\Feature;

use App\Mail\DonationReceipt;
use App\Models\Donation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class StripeWebhookTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'whsec_test_secret';

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.stripe.webhook_secret' => self::SECRET]);
        Mail::fake();
    }

    /** Sends an event signed exactly the way Stripe signs it. */
    private function sendEvent(string $type, array $object, string $id = 'evt_1'): TestResponse
    {
        $payload = json_encode(['id' => $id, 'object' => 'event', 'type' => $type, 'data' => ['object' => $object]]);
        $timestamp = time();
        $signature = hash_hmac('sha256', "{$timestamp}.{$payload}", self::SECRET);

        return $this->call('POST', '/stripe/webhook', [], [], [], [
            'HTTP_STRIPE_SIGNATURE' => "t={$timestamp},v1={$signature}",
            'CONTENT_TYPE' => 'application/json',
        ], $payload);
    }

    private function donation(array $overrides = []): Donation
    {
        return Donation::create(array_merge([
            'name' => 'Ada', 'email' => 'ada@example.com', 'amount' => 2500,
            'frequency' => 'one_time', 'stripe_checkout_session_id' => 'cs_test_1',
        ], $overrides));
    }

    private function paidOneOffSession(Donation $donation): array
    {
        return [
            'id' => 'cs_test_1', 'object' => 'checkout.session', 'mode' => 'payment',
            'payment_status' => 'paid', 'payment_intent' => 'pi_1', 'amount_total' => 2500,
            'client_reference_id' => (string) $donation->id,
            'metadata' => ['donation_id' => (string) $donation->id],
        ];
    }

    public function test_request_with_a_bad_signature_is_rejected(): void
    {
        $this->call('POST', '/stripe/webhook', [], [], [], ['HTTP_STRIPE_SIGNATURE' => 't=1,v1=forged'], '{}')
            ->assertStatus(400);
    }

    public function test_completed_one_off_checkout_marks_donation_paid_and_queues_a_receipt(): void
    {
        $donation = $this->donation();

        $this->sendEvent('checkout.session.completed', $this->paidOneOffSession($donation))->assertOk();

        $this->assertSame('paid', $donation->fresh()->status);
        $this->assertDatabaseHas('donation_payments', ['stripe_reference' => 'pi_1', 'amount' => 2500]);
        Mail::assertQueued(DonationReceipt::class, fn ($mail) => $mail->hasTo('ada@example.com'));
    }

    public function test_the_same_event_delivered_twice_is_processed_once(): void
    {
        $donation = $this->donation();
        $session = $this->paidOneOffSession($donation);

        $this->sendEvent('checkout.session.completed', $session, 'evt_same')->assertOk();
        $this->sendEvent('checkout.session.completed', $session, 'evt_same')->assertOk();

        $this->assertDatabaseCount('donation_payments', 1);
        Mail::assertQueued(DonationReceipt::class, 1);
    }

    public function test_monthly_donation_gets_a_receipt_for_each_paid_invoice(): void
    {
        $donation = $this->donation(['frequency' => 'monthly']);

        $this->sendEvent('checkout.session.completed', [
            'id' => 'cs_test_1', 'object' => 'checkout.session', 'mode' => 'subscription',
            'subscription' => 'sub_1', 'customer' => 'cus_1', 'payment_status' => 'paid',
            'metadata' => ['donation_id' => (string) $donation->id],
        ], 'evt_checkout')->assertOk();

        Mail::assertNothingQueued();   // the invoice, not the checkout, triggers the receipt
        $this->assertSame('active', $donation->fresh()->status);

        foreach (['in_jan', 'in_feb'] as $i => $invoiceId) {
            $this->sendEvent('invoice.paid', [
                'id' => $invoiceId, 'object' => 'invoice', 'customer' => 'cus_1', 'amount_paid' => 2500,
                'parent' => ['subscription_details' => [
                    'subscription' => 'sub_1',
                    'metadata' => ['donation_id' => (string) $donation->id],
                ]],
            ], "evt_invoice_{$i}")->assertOk();
        }

        $this->assertSame(2, $donation->payments()->count());
        Mail::assertQueued(DonationReceipt::class, 2);
    }

    public function test_cancelled_subscription_marks_donation_cancelled(): void
    {
        $donation = $this->donation(['frequency' => 'monthly', 'status' => 'active', 'stripe_subscription_id' => 'sub_1']);

        $this->sendEvent('customer.subscription.deleted', [
            'id' => 'sub_1', 'object' => 'subscription', 'metadata' => [],
        ])->assertOk();

        $this->assertSame('cancelled', $donation->fresh()->status);
    }

    public function test_expired_checkout_marks_pending_donation_expired(): void
    {
        $donation = $this->donation();

        $this->sendEvent('checkout.session.expired', ['id' => 'cs_test_1', 'object' => 'checkout.session'])->assertOk();

        $this->assertSame('expired', $donation->fresh()->status);
        Mail::assertNothingQueued();
    }
}
