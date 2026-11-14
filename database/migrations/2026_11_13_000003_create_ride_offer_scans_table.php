<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ride_offer_scans', function (Blueprint $table) {
            $table->id();
            $table->uuid('client_uuid');
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('ride_offer_id')->nullable()->constrained('ride_offers')->nullOnDelete();
            $table->string('image_path');
            $table->char('image_hash', 64);
            $table->string('platform')->nullable();
            $table->string('status')->default('pending');
            $table->string('provider')->nullable();
            $table->text('ocr_text')->nullable();
            $table->json('ocr_tokens')->nullable();
            $table->json('extracted')->nullable();
            $table->json('client_extraction')->nullable();
            $table->string('reconciliation')->nullable();
            $table->decimal('confidence', 5, 4)->nullable();
            $table->string('error_code')->nullable();
            $table->timestamp('received_at');
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'client_uuid']);
            $table->index(['user_id', 'image_hash', 'created_at']);
            $table->index(['user_id', 'status']);
        });

        Schema::table('ride_offers', function (Blueprint $table) {
            $table->string('service_type_raw', 64)->nullable()->after('service_type');
            $table->json('ocr_confidence_by_field')->nullable()->after('ocr_confidence');
            $table->foreignId('ride_offer_scan_id')->nullable()->after('ride_trip_id')
                ->constrained('ride_offer_scans')->nullOnDelete();
            $table->index(['platform', 'service_type']);
        });
    }

    public function down(): void
    {
        Schema::table('ride_offers', function (Blueprint $table) {
            $table->dropIndex(['platform', 'service_type']);
            $table->dropConstrainedForeignId('ride_offer_scan_id');
            $table->dropColumn(['service_type_raw', 'ocr_confidence_by_field']);
        });

        Schema::dropIfExists('ride_offer_scans');
    }
};
