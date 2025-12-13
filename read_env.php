<?php
// Temporary script to read .env file
$envFile = __DIR__ . '/.env';
if (file_exists($envFile)) {
    $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    
    echo "Lines 92-97 from .env:\n";
    echo "=====================\n";
    for ($i = 91; $i < 97 && $i < count($lines); $i++) {
        $lineNum = $i + 1;
        // Mask sensitive values but show variable names
        $line = $lines[$i];
        if (preg_match('/^([^=]+)=(.*)$/', $line, $matches)) {
            $varName = $matches[1];
            $value = $matches[2];
            $maskedValue = strlen($value) > 10 ? substr($value, 0, 4) . '...' . substr($value, -4) : '***';
            echo "Line {$lineNum}: {$varName}={$maskedValue}\n";
        } else {
            echo "Line {$lineNum}: {$line}\n";
        }
    }
    
    // Extract TikTok values
    $tiktokVars = [];
    $ebayVars = [];
    foreach ($lines as $line) {
        if (preg_match('/^TIKTOK_([^=]+)=(.*)$/', $line, $matches)) {
            $tiktokVars[$matches[1]] = trim($matches[2], '"\'');
        }
        if (preg_match('/^EBAY.*?([^=]+)=(.*)$/', $line, $matches)) {
            $ebayVars[$matches[1]] = trim($matches[2], '"\'');
        }
    }
    
    echo "\nTikTok Environment Variables Found:\n";
    echo "===================================\n";
    foreach ($tiktokVars as $key => $value) {
        $masked = strlen($value) > 10 ? substr($value, 0, 4) . '...' . substr($value, -4) : '***';
        echo "TIKTOK_{$key} = {$masked}\n";
    }
    
    echo "\neBay Environment Variables Found:\n";
    echo "=================================\n";
    foreach ($ebayVars as $key => $value) {
        $masked = strlen($value) > 10 ? substr($value, 0, 4) . '...' . substr($value, -4) : '***';
        echo "EBAY{$key} = {$masked}\n";
    }
    
    // Now set up integrations with actual values
    echo "\n\nSetting up integrations in database...\n";
    echo "=====================================\n";
    
    require __DIR__ . '/vendor/autoload.php';
    $app = require_once __DIR__ . '/bootstrap/app.php';
    $app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();
    
    use Illuminate\Support\Facades\DB;
    
    // Setup TikTok (store_id 10)
    if (isset($tiktokVars['CLIENT_KEY']) && isset($tiktokVars['CLIENT_SECRET'])) {
        $tiktokData = [
            'app_id' => $tiktokVars['CLIENT_KEY'],
            'app_secret' => $tiktokVars['CLIENT_SECRET'],
            'access_token' => '',
            'updated_at' => now(),
        ];
        
        if (isset($tiktokVars['REFRESH_TOKEN'])) {
            $tiktokData['refresh_token'] = $tiktokVars['REFRESH_TOKEN'];
        } else {
            $tiktokData['refresh_token'] = '';
        }
        
        DB::table('integrations')->updateOrInsert(
            ['store_id' => 10],
            $tiktokData
        );
        
        $existing = DB::table('integrations')->where('store_id', 10)->whereNull('created_at')->first();
        if ($existing) {
            DB::table('integrations')->where('store_id', 10)->update(['created_at' => now()]);
        }
        
        echo "✅ TikTok integration updated (store_id: 10)\n";
        if (!isset($tiktokVars['REFRESH_TOKEN'])) {
            echo "⚠️  TikTok refresh_token not found in .env - you'll need to authenticate\n";
        }
    }
    
    // Setup eBay1 (store_id 3) - look for EBAY1 or EBAY variables
    $ebayAppId = $ebayVars['1_APP_ID'] ?? $ebayVars['_APP_ID'] ?? null;
    $ebayAppSecret = $ebayVars['1_APP_SECRET'] ?? $ebayVars['_APP_SECRET'] ?? null;
    $ebayRefreshToken = $ebayVars['1_REFRESH_TOKEN'] ?? $ebayVars['_REFRESH_TOKEN'] ?? null;
    
    // Also check for EBAY_CLIENT_ID pattern
    if (!$ebayAppId) {
        foreach ($lines as $line) {
            if (preg_match('/^EBAY1?_APP_ID=(.*)$/', $line, $m) || preg_match('/^EBAY1?_CLIENT_ID=(.*)$/', $line, $m)) {
                $ebayAppId = trim($m[1], '"\'');
            }
            if (preg_match('/^EBAY1?_APP_SECRET=(.*)$/', $line, $m) || preg_match('/^EBAY1?_CLIENT_SECRET=(.*)$/', $line, $m)) {
                $ebayAppSecret = trim($m[1], '"\'');
            }
            if (preg_match('/^EBAY1?_REFRESH_TOKEN=(.*)$/', $line, $m)) {
                $ebayRefreshToken = trim($m[1], '"\'');
            }
        }
    }
    
    if ($ebayAppId && $ebayAppSecret && $ebayRefreshToken) {
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
        
        $existing = DB::table('integrations')->where('store_id', 3)->whereNull('created_at')->first();
        if ($existing) {
            DB::table('integrations')->where('store_id', 3)->update(['created_at' => now()]);
        }
        
        echo "✅ eBay1 integration updated (store_id: 3)\n";
    } else {
        echo "❌ eBay1 credentials not found. Looking for:\n";
        echo "   - EBAY1_APP_ID or EBAY_APP_ID\n";
        echo "   - EBAY1_APP_SECRET or EBAY_APP_SECRET\n";
        echo "   - EBAY1_REFRESH_TOKEN or EBAY_REFRESH_TOKEN\n";
    }
} else {
    echo ".env file not found\n";
}

