<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ride_offers', function (Blueprint $table) {
            $table->id();
            $table->uuid('client_uuid');
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('vehicle_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('daily_shift_id')->nullable()->constrained()->nullOnDelete();
            $table->string('platform');
            $table->string('external_offer_id')->nullable();
            $table->string('currency_code', 3);
            $table->decimal('offered_fare', 10, 2);
            $table->decimal('surge_amount', 10, 2)->default(0);
            $table->decimal('tip_amount', 10, 2)->default(0);
            $table->decimal('toll_amount', 10, 2)->default(0);
            $table->decimal('estimated_distance_km', 10, 3);
            $table->unsignedSmallInteger('estimated_duration_minutes')->nullable();
            $table->decimal('pickup_distance_km', 10, 3)->nullable();
            $table->decimal('actual_distance_km', 10, 3)->nullable();
            $table->decimal('actual_fare', 10, 2)->nullable();
            $table->unsignedSmallInteger('actual_duration_minutes')->nullable();
            $table->string('source')->default('manual');
            $table->decimal('ocr_confidence', 5, 4)->nullable();
            $table->json('raw_payload')->nullable();
            $table->string('status')->default('received');
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('received_at');
            $table->timestamp('decided_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->string('cancellation_reason')->nullable();
            $table->string('client_verdict')->nullable();
            $table->decimal('client_net_profit_estimate', 10, 2)->nullable();
            $table->decimal('client_break_even_fare', 10, 2)->nullable();
            $table->string('server_verdict')->nullable();
            $table->decimal('server_net_profit_estimate', 10, 2)->nullable();
            $table->decimal('server_break_even_fare', 10, 2)->nullable();
            $table->string('calculation_version')->nullable();
            $table->json('config_snapshot')->nullable();
            $table->foreignId('ride_trip_id')->nullable()->constrained('ride_trips')->nullOnDelete();
            $table->timestamps();

            $table->unique(['user_id', 'client_uuid']);
            $table->index(['user_id', 'received_at']);
            $table->index(['user_id', 'status']);
            $table->index('platform');
        });

        Schema::create('ride_offer_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ride_offer_id')->constrained('ride_offers')->cascadeOnDelete();
            $table->string('event_type');
            $table->timestamp('occurred_at');
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['ride_offer_id', 'occurred_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ride_offer_events');
        Schema::dropIfExists('ride_offers');
    }
};
