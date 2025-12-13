<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up()
    {
        Schema::table('integrations', function (Blueprint $table) {
            $table->text('access_token')->change();
            $table->text('refresh_token')->change();
        });
    }

    public function down()
    {
        Schema::table('integrations', function (Blueprint $table) {
            $table->string('access_token', 255)->change();
            $table->string('refresh_token', 255)->change();
        });
    }
};
