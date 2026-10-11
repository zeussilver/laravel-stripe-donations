<?php

namespace App\Providers;

use App\Services\StripeGateway;
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
        //
    }
}
