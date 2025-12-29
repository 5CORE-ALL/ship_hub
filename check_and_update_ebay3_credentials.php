<?php
// Script to check and update eBay3 (store_id 5) credentials from environment variables
require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

echo "Check and Update eBay3 (store_id 5) Credentials from Environment\n";
echo "================================================================\n\n";

// Store ID for ebay3
$storeId = 5;

// Check if store exists
$store = DB::table('stores')->where('id', $storeId)->first();
if (!$store) {
    echo "❌ Store ID {$storeId} does not exist!\n";
    exit(1);
}

echo "Store: {$store->name} (ID: {$store->id})\n\n";

// Get environment variables - try multiple patterns
$envAppId = env('EBAY3_APP_ID') ?? env('EBAY_3_APP_ID') ?? env('EBAY3_CLIENT_ID') ?? env('EBAY_3_CLIENT_ID');
$envAppSecret = env('EBAY3_APP_SECRET') ?? env('EBAY_3_APP_SECRET') ?? env('EBAY3_CERT_ID') ?? env('EBAY_3_CERT_ID') ?? env('EBAY3_CLIENT_SECRET') ?? env('EBAY_3_CLIENT_SECRET');
$envRefreshToken = env('EBAY3_REFRESH_TOKEN') ?? env('EBAY_3_REFRESH_TOKEN');

// Display environment variables found
echo "Environment Variables:\n";
echo "  APP_ID: " . ($envAppId ? substr($envAppId, 0, 20) . "..." : "❌ NOT FOUND") . "\n";
echo "  APP_SECRET: " . ($envAppSecret ? substr($envAppSecret, 0, 20) . "..." : "❌ NOT FOUND") . "\n";
echo "  REFRESH_TOKEN: " . ($envRefreshToken ? substr($envRefreshToken, 0, 20) . "..." : "❌ NOT FOUND") . "\n\n";

if (!$envAppId || !$envAppSecret || !$envRefreshToken) {
    echo "❌ Missing required environment variables!\n";
    echo "   Required: EBAY3_APP_ID (or EBAY_3_APP_ID), EBAY3_APP_SECRET (or EBAY_3_APP_SECRET), EBAY3_REFRESH_TOKEN (or EBAY_3_REFRESH_TOKEN)\n";
    exit(1);
}

// Get current integration from database
$integration = DB::table('integrations')->where('store_id', $storeId)->first();

if (!$integration) {
    echo "⚠️  No integration found in database. Creating new one...\n\n";
    
    DB::table('integrations')->insert([
        'store_id' => $storeId,
        'app_id' => $envAppId,
        'app_secret' => $envAppSecret,
        'refresh_token' => $envRefreshToken,
        'access_token' => '', // Clear old access token
        'expires_at' => null,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    
    echo "✅ Integration created with environment values!\n";
    exit(0);
}

// Compare values
$needsUpdate = false;
$updates = [];

echo "Database Values:\n";
echo "  APP_ID: " . ($integration->app_id ? substr($integration->app_id, 0, 20) . "..." : "NULL") . "\n";
echo "  APP_SECRET: " . ($integration->app_secret ? substr($integration->app_secret, 0, 20) . "..." : "NULL") . "\n";
echo "  REFRESH_TOKEN: " . ($integration->refresh_token ? substr($integration->refresh_token, 0, 20) . "..." : "NULL") . "\n\n";

// Check app_id
if ($integration->app_id !== $envAppId) {
    echo "⚠️  APP_ID mismatch!\n";
    echo "   DB: " . substr($integration->app_id ?? '', 0, 20) . "...\n";
    echo "   ENV: " . substr($envAppId, 0, 20) . "...\n";
    $needsUpdate = true;
    $updates['app_id'] = $envAppId;
}

// Check app_secret
if ($integration->app_secret !== $envAppSecret) {
    echo "⚠️  APP_SECRET mismatch!\n";
    echo "   DB: " . substr($integration->app_secret ?? '', 0, 20) . "...\n";
    echo "   ENV: " . substr($envAppSecret, 0, 20) . "...\n";
    $needsUpdate = true;
    $updates['app_secret'] = $envAppSecret;
}

// Check refresh_token
if ($integration->refresh_token !== $envRefreshToken) {
    echo "⚠️  REFRESH_TOKEN mismatch!\n";
    echo "   DB: " . substr($integration->refresh_token ?? '', 0, 20) . "...\n";
    echo "   ENV: " . substr($envRefreshToken, 0, 20) . "...\n";
    $needsUpdate = true;
    $updates['refresh_token'] = $envRefreshToken;
}

if (!$needsUpdate) {
    echo "✅ All credentials match! No update needed.\n";
    exit(0);
}

echo "\n🔄 Updating database with environment values...\n";

// Prepare update data
$updateData = array_merge($updates, [
    'access_token' => '', // Clear old access token when credentials change
    'expires_at' => null,  // Clear expiration
    'updated_at' => now(),
]);

// Update the integration
DB::table('integrations')->where('store_id', $storeId)->update($updateData);

echo "✅ Database updated successfully!\n";
echo "\nUpdated fields:\n";
foreach (array_keys($updates) as $field) {
    echo "  - {$field}\n";
}

echo "\n✅ Done! You can now test the sync with:\n";
echo "   php artisan ebay:sync-orders --store=5\n";

