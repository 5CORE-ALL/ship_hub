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
        Schema::table('integrations', function (Blueprint $table) {
            $table->string('app_id')->nullable()->after('store_id');
            $table->string('app_secret')->nullable()->after('app_id');
        });
    }

    public function down(): void
    {
        Schema::table('integrations', function (Blueprint $table) {
            $table->dropColumn(['app_id', 'app_secret']);
        });
    }
};
