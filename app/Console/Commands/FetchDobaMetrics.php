<?php

namespace App\Console\Commands;

use App\Models\DobaMetric;
use Carbon\Carbon;
use Exception;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class FetchDobaMetrics extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:fetch-doba-metrics';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Command description';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info("Fetching Doba Metrics...");

        $page = 1;

        do {
            $timestamp = $this->getMillisecond();
            $getContent = $this->getContent($timestamp);
            $sign = $this->generateSignature($getContent);
            
            $response = Http::withHeaders([
                'appKey' => env('DOBA_APP_KEY'),
                'signType' => 'rsa2',
                'timestamp' => $timestamp,
                'sign' => $sign,
                'Content-Type' => 'application/json',
            ])->get('https://openapi.doba.com/api/goods/detail', [
                'pageNumber' => $page,
                'pageSize' => 100
            ]);
        
            if (!$response->ok()) {
                $this->error("API Failed: " . $response->body());
                return;
            }

            $data = $response['businessData']['data']['dsGoodsDetailResultVOS'];
            if (empty($data)) break;
            foreach ($data as $product) {
                foreach ($product['skus'] as $sku) {
                    $item = $sku['stocks'][0] ?? null;

                    if (!$item) continue;

                    DobaMetric::updateOrCreate(
                        ['sku' => $sku['skuCode']],
                        [
                            'item_id' => $item['itemNo'],
                            'anticipated_income' => $item['anticipatedIncome'],
                        ]
                    );
                }
            }
            $page++;
        } while (count($data) === 100);

        $this->getQuantity();
        $this->info("Done.");

    }

    private function getQuantity()
    {
       $this->info("Fetching Doba Orders Quantity..."); 
        $ranges = $this->getDateRanges(); // ['l30' => [...], 'l60' => [...]]
        
        foreach ($ranges as $key => $range) {
            $statuses = [1, 4, 5, 6, 7];

            foreach ($statuses as $status) {
                $page = 1;
                $skuTotals = [];

                do {
                    $timestamp = $this->getMillisecond();
                    $getContent = $this->getContent($timestamp);
                    $sign = $this->generateSignature($getContent);
                        info('$range', [$range['begin'], $range['end']]);
                    $response = Http::withHeaders([
                        'appKey' => env('DOBA_APP_KEY'),
                        'signType' => 'rsa2',
                        'timestamp' => $timestamp,
                        'sign' => $sign,
                        'Content-Type' => 'application/json',
                    ])->post('https://openapi.doba.com/api/seller/queryOrderDetail', [
                        'beginTime' => $range['begin'],
                        'endTime' => $range['end'],
                        'pageNo' => $page,
                        'pageSize' => 100,
                        'status' => $status
                    ]);

                    if (!$response->ok()) {
                        $this->error("API Failed for status $status: " . $response->body());
                        break;
                    }

                    $responseData = $response->json();
                    $data = $responseData['businessData'][0]['data'] ?? [];
                    info("status $status", [$data]);
                    info('data', [$data]);
                    if (empty($data)) break;

                    foreach ($data as $order) {
                        foreach ($order['orderItemList'] as $item) {
                            $sku = $item['goodsSkuCode'];
                            $qty = $item['quantity'];

                            if (!isset($skuTotals[$sku])) {
                                $skuTotals[$sku] = 0;
                            }
                            $skuTotals[$sku] += $qty;
                        }
                    }
                    $page++;
                } while (count($data) === 100);

                // Save to DB after all pages fetched for this range
                foreach ($skuTotals as $sku => $totalQty) {
                    info('$skuTotals', [$skuTotals]);
                    DobaMetric::updateOrCreate(
                        ['sku' => $sku],
                        ['quantity_' . $key => (int) $totalQty] // quantity_l30 or quantity_l60
                    );
                }
            }
        }
    }

    private function generateSignature($content)
    {
        $privateKeyFormatted = "-----BEGIN RSA PRIVATE KEY-----\n" .
            wordwrap( env('DOBA_PRIVATE_KEY'), 64, "\n", true) .
            "\n-----END RSA PRIVATE KEY-----";

        $private_key = openssl_pkey_get_private($privateKeyFormatted);
		if (!$private_key) {
			throw new Exception("Invalid private key.");
		}
        openssl_sign($content, $signature, $private_key, OPENSSL_ALGO_SHA256);
        
		$sign = base64_encode($signature); 
        return $sign;
    }

    private function getContent($timestamp)
    {
        $appKey = env('DOBA_APP_KEY');
		$contentForSign = "appKey={$appKey}&signType=rsa2&timestamp={$timestamp}";
		return $contentForSign;
    }

    private function getMillisecond()
    {
		list($s1, $s2) = explode(' ', microtime());
        return intval((float)sprintf('%.0f', (floatval($s1) + floatval($s2)) * 1000));
    }

    private function getDateRanges(): array
    {
        // Yesterday (e.g., if today is June 30 → yesterday is June 29)
        $yesterday = Carbon::yesterday();

        // L30: Last 30 days (from 30 days before yesterday → to yesterday)
        $l30_end = $yesterday->copy();                   // June 29
        $l30_start = $l30_end->copy()->subDays(29);      // May 31

        // L60: Month before L30 (30 days before L30 start → day before L30 start)
        $l60_end = $l30_start->copy()->subDay();         // May 30
        $l60_start = $l60_end->copy()->subDays(29);      // May 1

        return [
            'l30' => [
                'begin' => $l30_start->format('Y-m-d\TH:i:sP'), // e.g., 2025-05-31T00:00:00+05:30
                'end'   => $l30_end->format('Y-m-d\TH:i:sP'),   // e.g., 2025-06-29T00:00:00+05:30
            ],
            'l60' => [
                'begin' => $l60_start->format('Y-m-d\TH:i:sP'), // e.g., 2025-05-01T00:00:00+05:30
                'end'   => $l60_end->format('Y-m-d\TH:i:sP'),   // e.g., 2025-05-30T00:00:00+05:30
            ],
        ];
    }

}
