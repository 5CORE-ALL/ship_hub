<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('order_shipping_rates', function (Blueprint $table) {
            $table->string('rate_type', 1)->nullable()->after('order_id')->comment('D for D dimensions, O for regular dimensions');
        });
    }

    public function down(): void
    {
        Schema::table('order_shipping_rates', function (Blueprint $table) {
            $table->dropColumn('rate_type');
        });
    }
};
