<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // One row per successful charge: one for a one-off gift, one per month for a monthly gift.
        Schema::create('donation_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('donation_id')->constrained()->cascadeOnDelete();
            $table->string('stripe_reference')->unique();   // payment_intent id (one-off) or invoice id (monthly)
            $table->unsignedInteger('amount');
            $table->timestamp('paid_at');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('donation_payments');
    }
};
