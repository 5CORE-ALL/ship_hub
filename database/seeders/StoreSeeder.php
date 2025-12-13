<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Carbon;

class StoreSeeder extends Seeder
{
    public function run()
    {
        // Insert dummy data into stores table
        DB::table('stores')->insert([
            [
                'name' => 'MyAmazonStore_US',
                'sales_channel_id' => 1, 
                'marketplace_id' => 1,
                'status' => 'active',
                'settings' => json_encode(['product_identifier' => 'SKU', 'notifications' => true]),
                'created_at' => Carbon::now(),
                'updated_at' => Carbon::now(),
            ],
            [
                'name' => 'MyAmazonStore_IN',
                'sales_channel_id' => 1, 
                'marketplace_id' => 2, 
                'status' => 'pending',
                'settings' => json_encode(['product_identifier' => 'ASIN', 'notifications' => false]),
                'created_at' => Carbon::now(),
                'updated_at' => Carbon::now(),
            ],
        ]);
    }
}