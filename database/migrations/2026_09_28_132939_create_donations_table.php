<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('donations', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email');
            $table->unsignedInteger('amount');             // in cents: $25.00 -> 2500
            $table->string('currency', 3)->default('aud');
            $table->string('frequency');                    // one_time | monthly
            $table->string('status')->default('pending');   // pending | paid | active | past_due | cancelled | expired
            $table->string('stripe_checkout_session_id')->nullable()->unique();
            $table->string('stripe_customer_id')->nullable();
            $table->string('stripe_subscription_id')->nullable()->unique();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('donations');
    }
};
