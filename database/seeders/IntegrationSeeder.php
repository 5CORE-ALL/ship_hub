<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Carbon;

class IntegrationSeeder extends Seeder
{
    public function run()
    {
        DB::table('integrations')->insert([
            [
                'store_id' => 1, 
                'access_token' => 'Atzr|IQEBLzAtzr...dummy_access_token_1...',
                'refresh_token' => 'Atzr|IQEBLzAtzr...dummy_refresh_token_1...',
                'expires_at' => Carbon::now()->addHours(1),
                'created_at' => Carbon::now(),
                'updated_at' => Carbon::now(),
            ],
            [
                'store_id' => 2, 
                'access_token' => 'Atzr|IQEBLzAtzr...dummy_access_token_2...',
                'refresh_token' => 'Atzr|IQEBLzAtzr...dummy_refresh_token_2...',
                'expires_at' => Carbon::now()->addHours(2), 
                'created_at' => Carbon::now(),
                'updated_at' => Carbon::now(),
            ],
        ]);
    }
}