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
        Schema::table('orders', function (Blueprint $table) {
            $table->boolean('doba_label_required')->default(false)->after('label_source')->comment('Whether label is required for DOBA order');
            $table->boolean('doba_label_provided')->default(false)->after('doba_label_required')->comment('Whether customer provided label for DOBA order');
            $table->string('doba_label_file')->nullable()->after('doba_label_provided')->comment('Path to label file if provided by customer');
            $table->string('doba_label_sku')->nullable()->after('doba_label_file')->comment('SKU to edit in customer-provided label');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn([
                'doba_label_required',
                'doba_label_provided',
                'doba_label_file',
                'doba_label_sku'
            ]);
        });
    }
};
