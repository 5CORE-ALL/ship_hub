<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->string('default_rate_id')->nullable()->after('id');
            $table->string('default_carrier')->nullable()->after('default_rate_id');
            $table->decimal('default_price', 10, 2)->nullable()->after('default_carrier');
            $table->string('default_currency', 5)->nullable()->after('default_price');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn(['default_rate_id', 'default_carrier', 'default_price', 'default_currency']);
        });
    }
};
