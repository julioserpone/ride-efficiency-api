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
        Schema::create('daily_earnings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('daily_shift_id')->constrained()->onDelete('cascade');
            $table->string('provider_name'); 
            $table->decimal('gross_amount', 8, 2)->default(0.00);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('daily_earnings');
    }
};
