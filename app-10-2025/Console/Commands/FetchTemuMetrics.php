<?php

namespace App\Console\Commands;


use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class FetchTemuMetrics extends Command
{
    protected $signature = 'app:fetch-temu-metrics';
    protected $description = 'Fetch Temu SKUs, Goods, Quantities, and Product Analytics with logs';

    public function handle()
    {       
        $this->info("Starting Temu Metrics Fetch...");
        $this->fetchSkus();
        $this->fetchGoodsId();
        $this->fetchQuantity();
        $this->fetchProductAnalyticsData();
        $this->info("All Temu Metrics Fetch Completed.");
    }

    private function fetchSkus()
    {
        $pageToken = null;
        do {
            $requestBody = [
                "type" => "temu.local.sku.list.retrieve",
                "skuSearchType" => "ACTIVE",
                "pageSize" => 100,
            ];

            if ($pageToken) $requestBody["pageToken"] = $pageToken;

            $signedRequest = $this->generateSignValue($requestBody);

            $response = Http::withHeaders(['Content-Type' => 'application/json'])
                ->post('https://openapi-b-us.temu.com/openapi/router', $signedRequest);

            if ($response->failed()) {
                $this->error("Request failed: " . $response->body());
                break;
            }

            $data = $response->json();

            if (!($data['success'] ?? false)) {
                $this->error("Temu Error: " . ($data['errorMsg'] ?? 'Unknown'));
                break;
            }

            $skus = $data['result']['skuList'] ?? [];
            foreach ($skus as $sku) {
               
            }

            $pageToken = $data['result']['pagination']['nextToken'] ?? null;
        } while ($pageToken);

        $this->info("SKUs Synced Successfully.");
    }

    public function fetchGoodsId()
    {
        $pageToken = null;
        do {
            $requestBody = [
                "type" => "temu.local.goods.list.retrieve",
                "goodsSearchType" => "ALL",
                "pageSize" => 100,
            ];

            if ($pageToken) $requestBody["pageToken"] = $pageToken;

            $signedRequest = $this->generateSignValue($requestBody);

            $response = Http::withHeaders(['Content-Type' => 'application/json'])
                ->post('https://openapi-b-us.temu.com/openapi/router', $signedRequest);

            if ($response->failed()) {
                $this->error("Request failed: " . $response->body());
                break;
            }

            $data = $response->json();

            if (!($data['success'] ?? false)) {
                $this->error("Temu Error: " . ($data['errorMsg'] ?? 'Unknown'));
                break;
            }

            $goodsList = $data['result']['goodsList'] ?? [];
            foreach ($goodsList as $good) {
                $goodsId = $good['goodsId'] ?? null;
                foreach ($good['skuInfoList'] ?? [] as $sku) {
                    $skuSn = $sku['skuSn'] ?? null;
                    if ($skuSn && $goodsId) {
                    
                    }
                }
            }

            $pageToken = $data['result']['pagination']['nextToken'] ?? null;
        } while ($pageToken);

        $this->info("Goods ID Updated Successfully.");
    }

    private function fetchQuantity()
    {
        $today = Carbon::today();
        $ranges = [
            'L30' => [$today->copy()->subDays(30), $today->copy()->subDay()],
            'L60' => [$today->copy()->subDays(60), $today->copy()->subDays(31)],
        ];

        $finalSkuQuantities = [];

        foreach ($ranges as $label => [$from, $to]) {
            $pageNumber = 1;
            do {
                $requestBody = [
                    "type" => "bg.order.list.v2.get",
                    "pageSize" => 100,
                    "pageNumber" => $pageNumber,
                    "createAfter" => $from->timestamp,
                    "createBefore" => $to->copy()->endOfDay()->timestamp,
                ];

                $signedRequest = $this->generateSignValue($requestBody);

                $response = Http::withHeaders(['Content-Type' => 'application/json'])
                    ->post('https://openapi-b-us.temu.com/openapi/router', $signedRequest);

                if ($response->failed()) {
                    $this->error("Request failed: " . $response->body());
                    break;
                }

                $data = $response->json();
                if (!($data['success'] ?? false)) {
                    $this->error("Temu Error: " . ($data['errorMsg'] ?? 'Unknown'));
                    break;
                }

                $orders = $data['result']['pageItems'] ?? [];
                if (empty($orders)) break;

                foreach ($orders as $order) {
                    Log::info("Order Details: " . json_encode($order, JSON_PRETTY_PRINT));
                    $this->info("Order fetched: " . ($order['orderSn'] ?? 'N/A'));

                    foreach ($order['orderList'] ?? [] as $item) {
                        $skuId = $item['skuId'];
                        $qty = $item['quantity'];

                        if (!isset($finalSkuQuantities[$skuId])) {
                            $finalSkuQuantities[$skuId] = ['quantity_purchased_l30' => 0, 'quantity_purchased_l60' => 0];
                        }

                        if ($label === 'L30') $finalSkuQuantities[$skuId]['quantity_purchased_l30'] += $qty;
                        if ($label === 'L60') $finalSkuQuantities[$skuId]['quantity_purchased_l60'] += $qty;

                        $this->info("SKU: {$skuId} | Qty: {$qty} | Label: {$label}");
                    }
                }

                $pageNumber++;
            } while (true);
        }

        foreach ($finalSkuQuantities as $skuId => $data) {
          
        }

        $this->info("Quantity Purchased Updated Successfully.");
    }

    private function fetchProductAnalyticsData()
    {
        $goodsIds = 1;
        $ranges = [
            'L30' => [
                'startTs' => Carbon::now()->subDays(30)->startOfDay()->timestamp * 1000,
                'endTs' => Carbon::yesterday()->endOfDay()->timestamp * 1000,
            ],
            'L60' => [
                'startTs' => Carbon::now()->subDays(60)->startOfDay()->timestamp * 1000,
                'endTs' => Carbon::now()->subDays(31)->endOfDay()->timestamp * 1000,
            ],
        ];

        foreach ($goodsIds as $goodId) {
            $metrics = [
                'product_impressions_l30' => 0,
                'product_clicks_l30' => 0,
                'product_impressions_l60' => 0,
                'product_clicks_l60' => 0,
            ];

            foreach ($ranges as $label => $range) {
                $requestBody = [
                    'type' => 'temu.searchrec.ad.reports.goods.query',
                    'goodsId' => $goodId,
                    'startTs' => $range['startTs'],
                    'endTs' => $range['endTs'],
                ];

                $signedRequest = $this->generateSignValue($requestBody);

                $response = Http::withHeaders(['Content-Type' => 'application/json'])
                    ->post('https://openapi-b-us.temu.com/openapi/router', $signedRequest);

                if ($response->failed()) {
                    $this->error("Request failed for Goods ID: {$goodId} | " . $response->body());
                    continue;
                }

                $data = $response->json();
                if (!($data['success'] ?? false)) {
                    $this->error("Temu API error for Goods ID: {$goodId} | " . ($data['errorMsg'] ?? 'Unknown'));
                    continue;
                }

                $summary = $data['result']['reportInfo']['reportsSummary'] ?? null;

                if ($summary) {
                    if ($label === 'L30') {
                        $metrics['product_impressions_l30'] = $summary['imprCntAll']['val'] ?? 0;
                        $metrics['product_clicks_l30'] = $summary['clkCntAll']['val'] ?? 0;
                    } elseif ($label === 'L60') {
                        $metrics['product_impressions_l60'] = $summary['imprCntAll']['val'] ?? 0;
                        $metrics['product_clicks_l60'] = $summary['clkCntAll']['val'] ?? 0;
                    }
                }
            }

           
            $this->info("Analytics updated for Goods ID: {$goodId}");
        }

        $this->info("All Product Analytics Data Updated Successfully.");
    }

    private function generateSignValue($requestBody)
    {
        $appKey = env('TEMU_APP_KEY');
        $appSecret = env('TEMU_SECRET_KEY');
        $accessToken = env('TEMU_ACCESS_TOKEN');
        $timestamp = time();

        $params = [
            'access_token' => $accessToken,
            'app_key' => $appKey,
            'timestamp' => $timestamp,
            'data_type' => 'JSON',
        ];

        $signParams = array_merge($params, $requestBody);
        ksort($signParams);

        $temp = '';
        foreach ($signParams as $key => $value) {
            if (is_array($value) || is_object($value)) {
                $value = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            }
            $temp .= $key . $value;
        }

        $signStr = $appSecret . $temp . $appSecret;
        $sign = strtoupper(md5($signStr));

        $params['sign'] = $sign;
        $this->info("Generated Sign: $sign");

        return array_merge($params, $requestBody);
    }
}
