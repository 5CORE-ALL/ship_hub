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
        // Orders table me label_status add
        Schema::table('orders', function (Blueprint $table) {
            if (!Schema::hasColumn('orders', 'label_status')) {
                $table->enum('label_status', ['created', 'voided'])->default('created')->after('order_status');
            }
        });

        // Shipments table me label_status add
        Schema::table('shipments', function (Blueprint $table) {
            if (!Schema::hasColumn('shipments', 'label_status')) {
                $table->enum('label_status', ['created', 'voided'])->default('created')->after('tracking_number');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Orders table se label_status hatana
        Schema::table('orders', function (Blueprint $table) {
            if (Schema::hasColumn('orders', 'label_status')) {
                $table->dropColumn('label_status');
            }
        });

        // Shipments table se label_status hatana
        Schema::table('shipments', function (Blueprint $table) {
            if (Schema::hasColumn('shipments', 'label_status')) {
                $table->dropColumn('label_status');
            }
        });
    }
};
