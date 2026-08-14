<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('conversations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->string('status')->default('ai')->index();
            $table->string('stage')->nullable();
            $table->string('intent')->nullable();
            $table->string('complexity')->nullable();
            $table->text('summary')->nullable();
            $table->json('collected_data')->nullable();
            $table->timestamp('transferred_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();

            $table->index(['customer_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('conversations');
    }
};
