<?php
// Script to read .env and set up integrations
require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use Illuminate\Support\Facades\DB;

$envFile = __DIR__ . '/.env';
if (!file_exists($envFile)) {
    echo ".env file not found\n";
    exit(1);
}

$lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

echo "Reading .env file and setting up integrations...\n";
echo "===============================================\n\n";

// Extract all TikTok and eBay variables
$tiktokVars = [];
$ebayVars = [];

foreach ($lines as $line) {
    $line = trim($line);
    if (empty($line) || strpos($line, '#') === 0) continue;
    
    if (preg_match('/^TIKTOK_([^=]+)=(.*)$/', $line, $matches)) {
        $tiktokVars[trim($matches[1])] = trim(trim($matches[2]), '"\'');
    }
    
    // Match EBAY variables - handle EBAY_, EBAY1_, EBAY3_ patterns
    if (preg_match('/^EBAY(1|3)?_([^=]+)=(.*)$/', $line, $matches)) {
        $storeNum = $matches[1] ?? '';
        $key = trim($matches[2]);
        $value = trim(trim($matches[3]), '"\'');
        
        // Store with store number prefix
        if ($storeNum) {
            $ebayVars[$storeNum . '_' . $key] = $value;
        } else {
            $ebayVars[$key] = $value;
        }
    }
}

// Display found variables
echo "TikTok Variables Found:\n";
foreach ($tiktokVars as $key => $value) {
    $masked = strlen($value) > 10 ? substr($value, 0, 4) . '...' . substr($value, -4) : '***';
    echo "  TIKTOK_{$key} = {$masked}\n";
}

echo "\neBay Variables Found:\n";
foreach ($ebayVars as $key => $value) {
    $masked = strlen($value) > 10 ? substr($value, 0, 4) . '...' . substr($value, -4) : '***';
    echo "  EBAY{$key} = {$masked}\n";
}

echo "\n\nSetting up integrations...\n";
echo "==========================\n\n";

// Setup TikTok (store_id 10)
if (isset($tiktokVars['CLIENT_KEY']) && isset($tiktokVars['CLIENT_SECRET'])) {
    $tiktokData = [
        'app_id' => $tiktokVars['CLIENT_KEY'],
        'app_secret' => $tiktokVars['CLIENT_SECRET'],
        'access_token' => '',
        'updated_at' => now(),
    ];
    
    if (isset($tiktokVars['REFRESH_TOKEN']) && !empty($tiktokVars['REFRESH_TOKEN'])) {
        $tiktokData['refresh_token'] = $tiktokVars['REFRESH_TOKEN'];
        echo "✅ TikTok refresh_token found\n";
    } else {
        $tiktokData['refresh_token'] = '';
        echo "⚠️  TikTok refresh_token not found - you'll need to authenticate\n";
    }
    
    $existing = DB::table('integrations')->where('store_id', 10)->first();
    DB::table('integrations')->updateOrInsert(
        ['store_id' => 10],
        $tiktokData
    );
    
    if (!$existing || !$existing->created_at) {
        DB::table('integrations')->where('store_id', 10)->update(['created_at' => now()]);
    }
    
    echo "✅ TikTok integration updated (store_id: 10)\n";
    echo "   App ID: " . substr($tiktokVars['CLIENT_KEY'], 0, 8) . "...\n";
    echo "   App Secret: " . substr($tiktokVars['CLIENT_SECRET'], 0, 8) . "...\n\n";
} else {
    echo "❌ TikTok CLIENT_KEY or CLIENT_SECRET not found\n\n";
}

// Setup eBay1 (store_id 3)
// Try EBAY3_ first (since store_id 3 might use EBAY3_), then EBAY1_, then EBAY_
$ebayAppId = $ebayVars['3_APP_ID'] ?? $ebayVars['1_APP_ID'] ?? $ebayVars['APP_ID'] ?? $ebayVars['3_CLIENT_ID'] ?? $ebayVars['1_CLIENT_ID'] ?? $ebayVars['CLIENT_ID'] ?? null;
// eBay might use CERT_ID as the secret, or APP_SECRET/CLIENT_SECRET
$ebayAppSecret = $ebayVars['3_APP_SECRET'] ?? $ebayVars['1_APP_SECRET'] ?? $ebayVars['APP_SECRET'] 
    ?? $ebayVars['3_CERT_ID'] ?? $ebayVars['1_CERT_ID'] ?? $ebayVars['CERT_ID']
    ?? $ebayVars['3_CLIENT_SECRET'] ?? $ebayVars['1_CLIENT_SECRET'] ?? $ebayVars['CLIENT_SECRET'] ?? null;
$ebayRefreshToken = $ebayVars['3_REFRESH_TOKEN'] ?? $ebayVars['1_REFRESH_TOKEN'] ?? $ebayVars['REFRESH_TOKEN'] ?? null;

if ($ebayAppId && $ebayAppSecret && $ebayRefreshToken) {
    $existing = DB::table('integrations')->where('store_id', 3)->first();
    DB::table('integrations')->updateOrInsert(
        ['store_id' => 3],
        [
            'app_id' => $ebayAppId,
            'app_secret' => $ebayAppSecret,
            'refresh_token' => $ebayRefreshToken,
            'access_token' => '',
            'updated_at' => now(),
        ]
    );
    
    if (!$existing || !$existing->created_at) {
        DB::table('integrations')->where('store_id', 3)->update(['created_at' => now()]);
    }
    
    echo "✅ eBay1 integration updated (store_id: 3)\n";
    echo "   App ID: " . substr($ebayAppId, 0, 8) . "...\n";
    echo "   App Secret: " . substr($ebayAppSecret, 0, 8) . "...\n";
    echo "   Refresh Token: " . substr($ebayRefreshToken, 0, 8) . "...\n\n";
} else {
    echo "❌ eBay1 credentials not found. Need:\n";
    echo "   - EBAY1_APP_ID or EBAY_APP_ID\n";
    echo "   - EBAY1_APP_SECRET or EBAY_APP_SECRET\n";
    echo "   - EBAY1_REFRESH_TOKEN or EBAY_REFRESH_TOKEN\n\n";
}

echo "Setup complete!\n";

