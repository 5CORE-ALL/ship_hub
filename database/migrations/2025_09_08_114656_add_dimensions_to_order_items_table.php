<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
        public function up()
        {
            Schema::table('order_items', function (Blueprint $table) {
                $table->decimal('length', 8, 2)->nullable()->after('weight');
                $table->decimal('width', 8, 2)->nullable()->after('length');
                $table->decimal('height', 8, 2)->nullable()->after('width');
            });
        }

        public function down()
        {
            Schema::table('order_items', function (Blueprint $table) {
                $table->dropColumn(['length', 'width', 'height']);
            });
        }
};
