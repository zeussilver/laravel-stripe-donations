<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Donation extends Model
{
    public const ONE_TIME = 'one_time';
    public const MONTHLY = 'monthly';

    protected $fillable = [
        'name', 'email', 'amount', 'currency', 'frequency', 'status',
        'stripe_checkout_session_id', 'stripe_customer_id', 'stripe_subscription_id',
    ];

    public function payments(): HasMany
    {
        return $this->hasMany(DonationPayment::class);
    }

    public function isMonthly(): bool
    {
        return $this->frequency === self::MONTHLY;
    }
}
