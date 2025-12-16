<?php

require __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Services\TikTokAuthService;
use Illuminate\Support\Facades\DB;

echo "\n🔄 Refreshing TikTok Access Token\n";
echo "==================================\n\n";

$storeId = 10;

$integration = DB::table('integrations')->where('store_id', $storeId)->first();

if (!$integration) {
    echo "❌ No integration found for store_id {$storeId}\n";
    exit(1);
}

if (!$integration->refresh_token) {
    echo "❌ No refresh_token found. Cannot refresh access token.\n";
    exit(1);
}

try {
    $service = new TikTokAuthService();
    
    echo "Clearing old access token...\n";
    
    // Force refresh by clearing the access token first
    DB::table('integrations')
        ->where('store_id', $storeId)
        ->update(['access_token' => null, 'expires_at' => null]);
    
    echo "Getting new access token...\n";
    
    // Get new token (will trigger refresh)
    $accessToken = $service->getAccessToken($storeId);
    
    if ($accessToken) {
        $updated = DB::table('integrations')->where('store_id', $storeId)->first();
        echo "\n✅ Access token refreshed successfully!\n";
        echo "   New token: " . substr($accessToken, 0, 30) . "...\n";
        echo "   Expires at: " . ($updated->expires_at ?? 'Not set') . "\n\n";
        echo "🎉 You can now run: php artisan tiktok:sync-order\n\n";
    } else {
        echo "\n❌ Failed to refresh access token\n";
        echo "💡 Check logs for details\n";
        exit(1);
    }
} catch (\Exception $e) {
    echo "\n❌ Error: " . $e->getMessage() . "\n";
    echo "\nStack trace:\n";
    echo $e->getTraceAsString() . "\n";
    exit(1);
}

