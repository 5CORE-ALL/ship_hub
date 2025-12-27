<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Ensure the id column is properly configured as auto-increment
        // This fixes the issue where id field doesn't have a default value
        // Use raw SQL to modify the id column to ensure it's auto-increment
        DB::statement('ALTER TABLE `orders` MODIFY COLUMN `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // This migration fixes a structural issue, so there's nothing to rollback
        // The id column should remain as auto-increment
    }
};
