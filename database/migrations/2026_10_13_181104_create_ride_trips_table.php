<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('ride_trips', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('vehicle_id')->constrained()->restrictOnDelete();
            $table->foreignId('daily_shift_id')->nullable()->constrained()->nullOnDelete();
            $table->string('platform_name');
            $table->string('currency_code', 3);
            $table->decimal('gross_fare', 10, 2);
            $table->decimal('distance_km', 10, 3);
            $table->decimal('deadhead_distance_km', 10, 3)->default(0);
            $table->unsignedSmallInteger('duration_minutes')->nullable();
            $table->decimal('commission_rate', 5, 2);
            $table->decimal('energy_price_per_unit', 10, 4);
            $table->decimal('energy_cost', 10, 2);
            $table->decimal('commission_cost', 10, 2);
            $table->decimal('depreciation_cost', 10, 2);
            $table->decimal('other_costs', 10, 2)->default(0);
            $table->decimal('net_profit', 10, 2);
            $table->decimal('break_even_fare', 10, 2);
            $table->string('verdict', 16);
            $table->timestamps();

            $table->index(['user_id', 'created_at']);
            $table->index(['vehicle_id', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ride_trips');
    }
};
