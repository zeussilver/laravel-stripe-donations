<?php

namespace App\Providers;

use App\Services\StripeGateway;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(StripeGateway::class, fn () => new StripeGateway(
            config('services.stripe.secret'),
            config('services.stripe.webhook_secret'),
        ));
    }

    public function boot(): void
    {
        // CloudFront talks to the server over HTTP, so build https:// links (e.g. Stripe's success_url) from APP_URL.
        if (str_starts_with((string) config('app.url'), 'https://')) {
            URL::forceScheme('https');
        }
    }
}
