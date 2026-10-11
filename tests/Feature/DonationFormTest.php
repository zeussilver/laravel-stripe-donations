<?php

namespace Tests\Feature;

use App\Models\Donation;
use App\Services\StripeGateway;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery\MockInterface;
use Stripe\Checkout\Session;
use Tests\TestCase;

class DonationFormTest extends TestCase
{
    use RefreshDatabase;

    public function test_donation_page_shows_the_form(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertSee('Give once')
            ->assertSee('Monthly');
    }

    public function test_valid_donation_is_saved_as_pending_and_redirected_to_stripe(): void
    {
        $this->mock(StripeGateway::class, function (MockInterface $mock) {
            $mock->shouldReceive('createCheckoutSession')
                ->once()
                ->withArgs(fn (Donation $d) => $d->amount === 2550 && $d->isMonthly() && $d->currency === 'aud')
                ->andReturn(Session::constructFrom([
                    'id' => 'cs_test_123',
                    'url' => 'https://checkout.stripe.com/c/pay/cs_test_123',
                ]));
        });

        $this->post('/donate', [
            'name' => 'Ada Lovelace',
            'email' => 'ada@example.com',
            'amount' => '25.50',
            'frequency' => 'monthly',
        ])->assertRedirect('https://checkout.stripe.com/c/pay/cs_test_123');

        $this->assertDatabaseHas('donations', [
            'email' => 'ada@example.com',
            'amount' => 2550,                       // stored in cents
            'frequency' => 'monthly',
            'status' => 'pending',
            'stripe_checkout_session_id' => 'cs_test_123',
        ]);
    }

    public function test_invalid_input_is_rejected_without_calling_stripe(): void
    {
        $this->mock(StripeGateway::class)->shouldNotReceive('createCheckoutSession');

        $this->post('/donate', [
            'name' => '',
            'email' => 'not-an-email',
            'amount' => '1',
            'frequency' => 'weekly',
        ])->assertSessionHasErrors(['name', 'email', 'amount', 'frequency']);

        $this->assertDatabaseCount('donations', 0);
    }

    public function test_one_off_checkout_uses_payment_mode(): void
    {
        $params = app(StripeGateway::class)->checkoutParams(
            Donation::make(['amount' => 1000, 'currency' => 'aud', 'frequency' => 'one_time', 'email' => 'a@b.com']),
            'https://app.test/success', 'https://app.test/',
        );

        $this->assertSame('payment', $params['mode']);
        $this->assertArrayNotHasKey('recurring', $params['line_items'][0]['price_data']);
        $this->assertSame(1000, $params['line_items'][0]['price_data']['unit_amount']);
    }

    public function test_monthly_checkout_uses_subscription_mode_with_a_monthly_price(): void
    {
        $donation = Donation::make(['amount' => 2500, 'currency' => 'aud', 'frequency' => 'monthly', 'email' => 'a@b.com']);
        $donation->id = 7;

        $params = app(StripeGateway::class)->checkoutParams($donation, 'https://app.test/success', 'https://app.test/');

        $this->assertSame('subscription', $params['mode']);
        $this->assertSame(['interval' => 'month'], $params['line_items'][0]['price_data']['recurring']);
        $this->assertSame('7', $params['subscription_data']['metadata']['donation_id']);
    }
}
