<?php

namespace App\Services;

use Illuminate\Support\Str;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class SheinApiService
{
    protected $appId;
    protected $appSecret;
    protected $baseUrl = 'https://openapi.sheincorp.com'; // or sandbox: openapi-test01.sheincorp.cn

    public function __construct()
    {
        $this->appId     = env('SHEIN_APP_ID');
        $this->appSecret = env('SHEIN_APP_SECRET');
    }

    public function listAllOrders()
    {
        $endpoint    = "/open-api/order/order-list";
        $allOrders   = [];
        $maxDaysBack = 10; // fetch 60 days history
        $windowHours = 48; // API limit

        $start = new \DateTime();
        $start->modify("-{$maxDaysBack} days");
        $end   = new \DateTime();

        // Loop through each 48-hour window
        while ($start < $end) {
            $windowStart = clone $start;
            $windowEnd   = (clone $start)->modify("+{$windowHours} hours");

            // Don’t go beyond current time
            if ($windowEnd > $end) {
                $windowEnd = clone $end;
            }

            // Loop through pages in this window
            for ($pageNum = 1; $pageNum <= 1000; $pageNum++) {

                $timestamp = round(microtime(true) * 1000);
                $random    = Str::random(5);
                $signature = $this->generateSheinSignature($endpoint, $timestamp, $random);

                $url = $this->baseUrl . $endpoint;

                $payload = [
                    "page"        => $pageNum,
                    "pageSize"    => 30,
                    "queryType"   => 1,
                    // "orderStatus" => 5,
                    "startTime"   => $windowStart->format('Y-m-d h:i:s'),
                    "endTime"     => $windowEnd->format('Y-m-d h:i:s'),
                ];

                $response = Http::withHeaders([
                    "Language"       => "en",
                    "x-lt-openKeyId" => env('SHEIN_OPEN_KEY_ID'),
                    "x-lt-timestamp" => $timestamp,
                    "x-lt-signature" => $signature,
                    "Content-Type"   => "application/json",
                ])->post($url, $payload);

                if (!$response->successful()) {
                    throw new \Exception("Shein API Error: " . $response->body());
                }

                $data = $response->json();
                Log::info("Shein API Response", [
    "status"   => $response->status(),
    "body"     => $response->body(),
    "json"     => $response->json(),
]);
                $orders = $data["info"]["orderList"] ?? [];

                if (empty($orders)) {
                    break; // No more data in this window
                }

                $allOrders = array_merge($allOrders, $orders);
                       Log::info("Shein API Response", [
    "allOrders"   => $allOrders
]);
            }

            // Move to next window
            $start = $windowEnd;
        }

        return $allOrders;
    }

    public function getOrderItems($oid)
    {
        $endpoint    = "/open-api/order/order-detail";
        $allOrders   = [];


        $timestamp = round(microtime(true) * 1000);
        $random    = Str::random(5);
        $signature = $this->generateSheinSignature($endpoint, $timestamp, $random);

        $url = $this->baseUrl . $endpoint;

        $payload = [
            "orderNoList" => [$oid],
        ];

        $response = Http::withHeaders([
            "Language"       => "en",
            "x-lt-openKeyId" => env('SHEIN_OPEN_KEY_ID'),
            "x-lt-timestamp" => $timestamp,
            "x-lt-signature" => $signature,
            "Content-Type"   => "application/json",
        ])->post($url, $payload);

        if (!$response->successful()) {
            throw new \Exception("Shein API Error: " . $response->body());
        }

        $data = $response->json();

        return $data;
        
    }


    /**
     * Generate tempToken
     */
    public function generateTempToken()
    {
        $endpoint  = "/open-api/auth/get-temp-token";
        $timestamp = Time();
        $random = Str::random(5);
        $signature = $this->generateSheinSignature($endpoint, $timestamp, $random);

        $url = $this->baseUrl . $endpoint;

        $response = Http::withHeaders([
            "Language"       => "en-us",  // use en-us
            "x-lt-appid"     => '136983C8B6803905B779EAE6C1D97',
            "x-lt-signature" => '7zs3eMDM1MGRjNzZmZDU5ZDUwMmY0YjFkMDEzZWViYWUxNTcyOGU2NGQ3MWI4Y2JiY2I1YzZmMzlhNzZlY2JhNTlhOA==',
            "x-lt-timestamp" => '1756584395118',
            "Content-Type"   => "application/json",
        ])->post($url, [
            "appId"     => '136983C8B6803905B779EAE6C1D97',
            "appSecret" => 'DF4F499EA28B44C489E69B31ACDEA4BA',
        ]);

        if ($response->successful()) {
            $data = $response->json();
            if (isset($data['data']['tempToken'])) {
                return $data['data']['tempToken'];
            }
            throw new \Exception("Invalid Shein TempToken Response: " . json_encode($data));
        }

        throw new \Exception("Shein API Error: " . $response->body());
    }

    /**
     * Generate signature with timestamp
     */
    function generateSheinSignature($path, $timestamp, $randomKey)
    {
        $openKeyId = env('SHEIN_OPEN_KEY_ID');
        $secretKey = env('SHEIN_SECRET_KEY');

        $value = $openKeyId . "&" . $timestamp . "&" . $path;

        $key = $secretKey . $randomKey;

        $hmacResult = hash_hmac('sha256', $value, $key, false); // false means return hexadecimal

        $base64Signature = base64_encode($hmacResult);

        $finalSignature = $randomKey . $base64Signature;

        return $finalSignature;
    }


    /**
     * Use tempToken to get access
     */
    public function listAllProducts()
    {
        $endpoint  = "/open-api/openapi-business-backend/product/query";
        $pageSize  = 400;
        $allProducts = [];

        // Loop max 1000 pages (safe upper bound)
        for ($pageNum = 1; $pageNum <= 1000; $pageNum++) {

            $timestamp = round(microtime(true) * 1000);
            $random    = Str::random(5);
            $signature = $this->generateSheinSignature($endpoint, $timestamp, $random);

            $url = $this->baseUrl . $endpoint;

            $payload = [
                "pageNum"         => $pageNum,
                "pageSize"        => $pageSize,
                "insertTimeEnd"   => "",
                "insertTimeStart" => "",
                "updateTimeEnd"   => "",
                "updateTimeStart" => "",
            ];

            $response = Http::withHeaders([
                "Language"       => "en-us",
                "x-lt-openKeyId" => env('SHEIN_OPEN_KEY_ID'),
                "x-lt-timestamp" => $timestamp,
                "x-lt-signature" => $signature,
                "Content-Type"   => "application/json",
            ])->post($url, $payload);

            if (!$response->successful()) {
                throw new \Exception("Shein API Error: " . $response->body());
            }

            $data = $response->json();
            $products = $data["info"]["data"] ?? [];

            // If no products returned → stop looping
            if (empty($products)) {
                break;
            }

            $allProducts = array_merge($allProducts, $products);
        }

        return $allProducts;
    }

    public function fetchBySpu($spu)
    {
        $endpoint  = "/open-api/goods/spu-info";
        $pageSize  = 400;
        $allProducts = [];

        $timestamp = round(microtime(true) * 1000);
        $random    = Str::random(5);
        $signature = $this->generateSheinSignature($endpoint, $timestamp, $random);

        $url = $this->baseUrl . $endpoint;

        $payload = [
            "languageList"         => ["en"],
            "spuName"        => $spu,
        ];

        $response = Http::withHeaders([
            "Language"       => "en-us",
            "x-lt-openKeyId" => env('SHEIN_OPEN_KEY_ID'),
            "x-lt-timestamp" => $timestamp,
            "x-lt-signature" => $signature,
            "Content-Type"   => "application/json",
        ])->post($url, $payload);

        if (!$response->successful()) {
            throw new \Exception("Shein API Error: " . $response->body());
        }

        $data = $response->json();

        if (array_key_exists("info", $data)) {
            return $data["info"];
        }
    }
}
