<?php
// Script to update eBay3 (store_id 5) refresh token
require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use Illuminate\Support\Facades\DB;

echo "Update eBay3 (store_id 5) Refresh Token\n";
echo "========================================\n\n";

// Check if store exists
$store = DB::table('stores')->where('id', 5)->first();
if (!$store) {
    echo "❌ Store ID 5 does not exist!\n";
    exit(1);
}

echo "Store: {$store->name} (ID: {$store->id})\n\n";

// Get current integration
$integration = DB::table('integrations')->where('store_id', 5)->first();
if (!$integration) {
    echo "❌ No integration found. Creating new one...\n\n";
    echo "You need to provide:\n";
    echo "  - app_id (eBay App ID)\n";
    echo "  - app_secret (eBay App Secret)\n";
    echo "  - refresh_token (eBay Refresh Token)\n\n";
    
    $appId = readline("Enter app_id: ");
    $appSecret = readline("Enter app_secret: ");
    $refreshToken = readline("Enter refresh_token: ");
    
    if (empty($appId) || empty($appSecret) || empty($refreshToken)) {
        echo "❌ All fields are required!\n";
        exit(1);
    }
    
    DB::table('integrations')->insert([
        'store_id' => 5,
        'app_id' => $appId,
        'app_secret' => $appSecret,
        'refresh_token' => $refreshToken,
        'access_token' => '',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    
    echo "✅ Integration created!\n";
} else {
    echo "Current integration:\n";
    echo "  app_id: " . substr($integration->app_id, 0, 20) . "...\n";
    echo "  app_secret: " . substr($integration->app_secret, 0, 20) . "...\n\n";
    
    echo "Options:\n";
    echo "  1. Update refresh_token only\n";
    echo "  2. Update app_id, app_secret, and refresh_token\n";
    $choice = readline("Enter choice (1 or 2): ");
    
    if ($choice == '1') {
        $refreshToken = readline("Enter new refresh_token: ");
        if (empty($refreshToken)) {
            echo "❌ Refresh token is required!\n";
            exit(1);
        }
        
        DB::table('integrations')->where('store_id', 5)->update([
            'refresh_token' => $refreshToken,
            'access_token' => '', // Clear old access token
            'expires_at' => null,  // Clear expiration
            'updated_at' => now(),
        ]);
        
        echo "✅ Refresh token updated!\n";
    } elseif ($choice == '2') {
        $appId = readline("Enter app_id (or press Enter to keep current): ");
        $appSecret = readline("Enter app_secret (or press Enter to keep current): ");
        $refreshToken = readline("Enter refresh_token: ");
        
        if (empty($refreshToken)) {
            echo "❌ Refresh token is required!\n";
            exit(1);
        }
        
        $updateData = [
            'refresh_token' => $refreshToken,
            'access_token' => '',
            'expires_at' => null,
            'updated_at' => now(),
        ];
        
        if (!empty($appId)) {
            $updateData['app_id'] = $appId;
        }
        if (!empty($appSecret)) {
            $updateData['app_secret'] = $appSecret;
        }
        
        DB::table('integrations')->where('store_id', 5)->update($updateData);
        
        echo "✅ Integration updated!\n";
    } else {
        echo "❌ Invalid choice!\n";
        exit(1);
    }
}

echo "\n✅ Done! You can now test the sync with:\n";
echo "   php artisan ebay:sync-orders --store=5\n";



