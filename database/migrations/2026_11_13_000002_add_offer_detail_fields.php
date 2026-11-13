<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ride_offers', function (Blueprint $table) {
            $table->string('service_type')->nullable()->after('platform');
            $table->string('availability_type')->nullable()->after('service_type');
            $table->decimal('rider_rating', 3, 2)->nullable()->after('availability_type');
            $table->unsignedInteger('rider_trip_count')->nullable()->after('rider_rating');
            $table->boolean('rider_verified')->nullable()->after('rider_trip_count');
            $table->string('pickup_address', 255)->nullable()->after('pickup_distance_km');
            $table->unsignedSmallInteger('estimated_pickup_duration_minutes')->nullable()->after('pickup_address');
            $table->string('destination_address', 255)->nullable()->after('estimated_distance_km');
            $table->string('payment_method', 32)->nullable()->after('toll_amount');
        });
    }

    public function down(): void
    {
        Schema::table('ride_offers', function (Blueprint $table) {
            $table->dropColumn([
                'service_type', 'availability_type', 'rider_rating', 'rider_trip_count',
                'rider_verified', 'pickup_address', 'estimated_pickup_duration_minutes',
                'destination_address', 'payment_method',
            ]);
        });
    }
};
