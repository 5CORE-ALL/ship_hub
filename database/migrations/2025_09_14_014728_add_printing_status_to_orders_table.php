<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
     public function up(): void
     {
        Schema::table('orders', function (Blueprint $table) {
            $table->tinyInteger('printing_status')
                  ->default(0)
                  ->comment('0 = default, 1 = awaiting printing, 2 = printed');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn('printing_status');
        });
    }

};
