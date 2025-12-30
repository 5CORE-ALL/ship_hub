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
        Schema::table('order_items', function (Blueprint $table) {
            $table->decimal('original_weight', 10, 2)->nullable()->after('weight');
            $table->decimal('original_length', 8, 2)->nullable()->after('length');
            $table->decimal('original_width', 8, 2)->nullable()->after('width');
            $table->decimal('original_height', 8, 2)->nullable()->after('height');
        });

        // Populate original values with current values for existing records
        \DB::statement('
            UPDATE order_items 
            SET 
                original_weight = COALESCE(weight, 0),
                original_length = COALESCE(length, 0),
                original_width = COALESCE(width, 0),
                original_height = COALESCE(height, 0)
            WHERE original_weight IS NULL
        ');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('order_items', function (Blueprint $table) {
            $table->dropColumn(['original_weight', 'original_length', 'original_width', 'original_height']);
        });
    }
};
