<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Fix the id column to have AUTO_INCREMENT
        // This ensures the id field has a default value (auto-increment)
        DB::statement('ALTER TABLE `order_items` MODIFY COLUMN `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Note: We can't really reverse this without potentially breaking things
        // The id column should always be AUTO_INCREMENT for this table
    }
};
