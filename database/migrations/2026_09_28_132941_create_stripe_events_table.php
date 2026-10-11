<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Stripe may deliver the same event more than once; we record each id so it is processed only once.
        Schema::create('stripe_events', function (Blueprint $table) {
            $table->string('id')->primary();   // evt_...
            $table->string('type');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stripe_events');
    }
};
