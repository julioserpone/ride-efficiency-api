<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('daily_offer_metrics', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->date('metric_date');
            $table->unsignedInteger('offered')->default(0);
            $table->unsignedInteger('evaluated')->default(0);
            $table->unsignedInteger('accepted')->default(0);
            $table->unsignedInteger('rejected')->default(0);
            $table->unsignedInteger('expired')->default(0);
            $table->unsignedInteger('completed')->default(0);
            $table->unsignedInteger('cancelled')->default(0);
            $table->decimal('avg_offered_fare', 10, 2)->nullable();
            $table->decimal('avg_net_profit', 10, 2)->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'metric_date']);
            $table->index('metric_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('daily_offer_metrics');
    }
};