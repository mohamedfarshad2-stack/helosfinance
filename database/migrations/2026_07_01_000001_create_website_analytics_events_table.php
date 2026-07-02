<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('website_analytics_events', function (Blueprint $table): void {
            $table->id();
            $table->string('site_key', 80)->index();
            $table->string('visitor_key', 80)->index();
            $table->string('session_key', 80)->index();
            $table->string('event_type', 50)->index();
            $table->string('label')->nullable();
            $table->text('page_url')->nullable();
            $table->string('page_path')->nullable();
            $table->text('referrer_url')->nullable();
            $table->string('selector')->nullable();
            $table->string('device_type', 20)->nullable();
            $table->string('country_code', 8)->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('occurred_at')->useCurrent()->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('website_analytics_events');
    }
};
