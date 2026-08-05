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
        Schema::create('daily_shifts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->date('shift_date');

            // Automated metrics tracked by the mobile GPS and local OCR engine
            $table->decimal('total_km_gps', 8, 2)->default(0.00);
            $table->integer('total_minutes_connected')->default(0);
            $table->integer('total_trips_completed')->default(0);
            $table->integer('total_offers_scanned')->default(0);

            // Financial calculations computed by Laravel on shift closure
            $table->decimal('applied_fuel_cost', 8, 2)->default(0.00);
            $table->decimal('estimated_depreciation', 8, 2)->default(0.00);
            $table->decimal('real_net_profit', 8, 2)->default(0.00);

            $table->timestamps();

            // Ensure a user can only log one shift per calendar date
            $table->unique(['user_id', 'shift_date']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('daily_shifts');
    }
};
