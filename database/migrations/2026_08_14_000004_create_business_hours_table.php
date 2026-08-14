<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('business_hours', function (Blueprint $table) {
            $table->id();
            $table->unsignedTinyInteger('day_of_week')->unique();
            $table->boolean('is_closed')->default(false);
            $table->time('opens_at')->nullable();
            $table->time('closes_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('business_hours');
    }
};
