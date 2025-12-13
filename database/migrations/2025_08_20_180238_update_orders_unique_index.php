<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Drop old unique index safely (ignore errors if it doesn’t exist)
        try {
            Schema::table('orders', function (Blueprint $table) {
                $table->dropUnique('orders_order_number_unique');
            });
        } catch (\Exception $e) {
            // ignore if index doesn't exist
        }

        // Create composite unique index with prefix length
        DB::statement('ALTER TABLE orders ADD UNIQUE INDEX orders_marketplace_number_unique (marketplace(100), order_number(150))');
    }

    public function down(): void
    {
        // Drop new index
        DB::statement('ALTER TABLE orders DROP INDEX orders_marketplace_number_unique');

        // Restore old unique index
        Schema::table('orders', function (Blueprint $table) {
            $table->unique('order_number', 'orders_order_number_unique');
        });
    }
};
