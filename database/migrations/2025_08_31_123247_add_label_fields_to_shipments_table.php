<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shipments', function (Blueprint $table) {
            if (!Schema::hasColumn('shipments', 'label_id')) {
                $table->string('label_id')->nullable()->after('id');
            }
            $table->string('tracking_url')->nullable()->after('tracking_number');
            $table->string('void_status')->nullable()->after('label_status')->comment('active, voided, cancelled');
        });
    }

    public function down(): void
    {
        Schema::table('shipments', function (Blueprint $table) {
            $table->dropColumn(['label_id', 'tracking_url', 'void_status']);
        });
    }
};
