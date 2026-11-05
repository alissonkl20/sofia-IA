<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->string('phone')->nullable()->change();
            $table->string('email')->nullable()->after('phone');
            $table->string('portfolio_session_id')->nullable()->unique()->after('whatsapp_id');
        });

        Schema::table('conversations', function (Blueprint $table) {
            $table->string('channel', 32)->default('whatsapp')->after('customer_id');
            $table->index(['channel', 'customer_id']);
        });
    }

    public function down(): void
    {
        Schema::table('conversations', function (Blueprint $table) {
            $table->dropIndex(['channel', 'customer_id']);
            $table->dropColumn('channel');
        });

        Schema::table('customers', function (Blueprint $table) {
            $table->dropUnique(['portfolio_session_id']);
            $table->dropColumn(['email', 'portfolio_session_id']);
            $table->string('phone')->nullable(false)->change();
        });
    }
};
