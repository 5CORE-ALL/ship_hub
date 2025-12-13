<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class AliAuthExchange extends Command
{
    protected $signature = 'ali:exchange-token {code}';
    protected $description = 'Exchange AliExpress authorization code for access_token + refresh_token';

    public function handle()
    {
        // Clean the code from any extra quotes
        $code = trim($this->argument('code'), "'\"");

        $appKey = config('services.aliexpress.app_key', env('ALIEXPRESS_APP_KEY', ''));
        $appSecret = config('services.aliexpress.app_secret', env('ALIEXPRESS_APP_SECRET', ''));
        $redirectUri = config('services.aliexpress.redirect_uri', env('ALIEXPRESS_REDIRECT_URI', 'https://ship.5coremanagement.com/aliexpress/callback'));
        $signMethod = 'sha256';
        $uuid = uniqid();
        $timestamp = round(microtime(true) * 1000);

        $apiPath = '/auth/token/create';
        $fullUrl = 'https://api-sg.aliexpress.com/rest' . $apiPath;

        // Parameters for request
        $params = [
            'app_key' => $appKey,
            'code' => $code,
            'timestamp' => $timestamp,
            'sign_method' => $signMethod,
            'uuid' => $uuid,
            'grant_type' => 'authorization_code',
            'need_refresh_token' => 'true',
            'redirect_uri' => $redirectUri,
        ];

        // Generate correct system-interface signature
        $params['sign'] = $this->generateSign($params, $appSecret, $apiPath);

        // Log & send request
        $this->info("Sending request to: $fullUrl");
        $this->info("Params: " . json_encode($params));

        try {
            // Use GET request as AliExpress token endpoint accepts query params
            $response = Http::timeout(60)
                ->get($fullUrl, $params);

            $data = $response->json();

            if (isset($data['access_token'])) {
                $this->info('✅ Token exchange successful:');
                $this->line(json_encode($data, JSON_PRETTY_PRINT));
            } else {
                $this->error('❌ Token exchange failed: ' . json_encode($data));
            }

            Log::info('AliExpress token exchange', [
                'params' => $params,
                'response' => $data
            ]);

            return 0;
        } catch (\Exception $e) {
            Log::error('AliExpress token exchange exception', ['error' => $e->getMessage()]);
            $this->error('Exception: ' . $e->getMessage());
            return 1;
        }
    }

    /**
     * Generate system-interface signature for /auth/token/create
     */
    private function generateSign(array $params, string $appSecret, string $apiPath): string
    {
        unset($params['sign']);
        ksort($params);

        // Concatenate sorted key+value
        $stringToSign = '';
        foreach ($params as $key => $value) {
            $stringToSign .= $key . $value;
        }

        // Prepend API path
        $stringToSign = $apiPath . $stringToSign;

        // SHA256 + append appSecret + uppercase hex
        return strtoupper(hash('sha256', $stringToSign . $appSecret));
    }
}
