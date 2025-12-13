<?php

use App\Models\CostMaster;
use GuzzleHttp\Client;
use Symfony\Component\DomCrawler\Crawler;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use App\Models\Order;
use App\Models\Shipment;
if (!function_exists('getDimensionsBySku')) {
    function getDimensionsBySku(string $sku, int $qty = 1): array
    {
    	// Log::info("Processing SKU: {$sku}, Quantity: {$qty}");
        $record = CostMaster::where('sku', $sku)->first();
        if (!$record || empty($record->Values)) {
            return [];
        }

        $values = json_decode($record->Values, true);

        $actualWeight    = (float) ($values['wt_act'] ?? 0);
        $declaredWeight  = (float) ($values['wt_decl'] ?? 0);
        if ($qty === 1) {
            $weight = $declaredWeight ?: $actualWeight;
        } else {
            $weight = $actualWeight * $qty;
        }

        return [
            'weight' => $weight,
            'length' => $values['l'] ?? null,
            'width'  => $values['w'] ?? null,
            'height' => $values['h'] ?? null,
        ];
         // Log::info("Dimensions for SKU {$sku}: " . json_encode($dimensions));
    }
}
if (!function_exists('marketplaceOrdersQuery')) {
    /**
     * Return a query builder for orders from allowed marketplaces
     *
     * @return \Illuminate\Database\Eloquent\Builder
     */
    function marketplaceOrdersQuery()
    {
        return Order::query()
            ->whereIn('marketplace', [
                'ebay1',
                'ebay2',
                'ebay3',
                'shopify',
                'walmart',
                'reverb',
                'PLS',
                'Temu',
                'TikTok',
                'Best Buy USA',
                'Business 5core',
                'Wayfair',
                'Amazon'
            ]);
    }
}
if (!function_exists('fetchSendleLabelPdf')) {
    /**
     * Fetch a Sendle label PDF and save it locally.
     *
     * @param string $labelUrl
     * @param string $apiKey
     * @param string $apiSecret
     * @return string|null Public URL of saved PDF or null if failed
     */
    function fetchSendleLabelPdf(string $labelUrl, string $apiKey, string $apiSecret): ?string
    {
        $localUrl = null;

        try {
            $client = new Client([
                'auth' => [$apiKey, $apiSecret],
                'verify' => true,
            ]);

            $response = $client->get($labelUrl);
            $pdfContent = $response->getBody()->getContents();
            $contentType = $response->getHeaderLine('Content-Type');

            // Only save if content is a PDF
            if ($pdfContent && stripos($contentType, 'pdf') !== false) {
                $fileName = 'labels/sendle_' . time() . '.pdf';
                Storage::disk('public')->put($fileName, $pdfContent);

                $localUrl = asset('storage/' . $fileName);
                Log::info('Sendle PDF saved successfully', ['localUrl' => $localUrl]);
            } else {
                Log::error('Fetched content is not a valid PDF', ['url' => $labelUrl, 'contentType' => $contentType]);
            }
        } catch (\Exception $e) {
            Log::error('Error fetching Sendle PDF', ['url' => $labelUrl, 'message' => $e->getMessage()]);
        }

        return $localUrl;
    }
}
if (!function_exists('detectMarketplaceFromTags')) {
    function detectMarketplaceFromTags(?string $tags): string
    {
        if (empty($tags)) {
            return "Shopify"; // default
        }
        if ($tags=="ebay") {
            return "ebay-s";
        }
        if ($tags=="eBay2") {
            return "eBay2-s";
        }
        if ($tags=="Ebay3") {
            return "eBay3-s";
        }
        if ($tags=="reverb") {
            return "reverb-s";
        }
        if ($tags=="Amazon") {
            return "Amazon-s";
        }
        $tagsArray = array_map('trim', explode(',', $tags));

        foreach ($tagsArray as $tag) {
            if (stripos($tag, 'TikTokOrderID') !== false) {
                return "TikTok-s";
            } elseif (stripos($tag, 'Walmart-DPL') !== false) {
                return "Walmart-s";
            }
             elseif (stripos($tag, 'SHEIN') !== false) {
                return "SHEIN-s";
            }
            elseif (stripos($tag, 'Doba') !== false) {
                return "DOBA";
            }
            elseif (stripos($tag, 'Best Buy USA') !== false) {
                return "Best Buy USA-s";
            }
            elseif (stripos($tag, 'Reverb') !== false) {
                return "Reverb-s";
            }
            elseif (stripos($tag, "Macy's Inc") !== false) {
               return "Macy-s";
            }
            
        }

       \Log::info("detectMarketplaceFromTags → Fallback", ['tags' => $tags]);
        return trim($tags);
    }
}


if (!function_exists('getSendleLocalTrackingId')) {
    /**
     * @param string $trackingUrl
     * @return string|null
     */
    function getSendleLocalTrackingId(string $trackingUrl): ?string
    {
        try {
            $client = new Client([
                'headers' => [
                    'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64)',
                ],
            ]);

            $response = $client->get($trackingUrl);
            $html = (string) $response->getBody();

            $crawler = new Crawler($html);

            $trackingId = $crawler->filterXPath("//*[contains(text(), 'Local tracking ID')]")->each(function ($node) {
                $sibling = $node->nextAll()->first();
                return $sibling ? trim($sibling->text()) : null;
            });

            foreach ($trackingId as $id) {
                if (!empty($id)) return $id;
            }

            return null;
        } catch (\Exception $e) {
            \Log::error("Sendle Tracking ID error: " . $e->getMessage());
            return null;
        }
    }
}
if (!function_exists('detectCarrier')) {
    function detectCarrier(string $trackingNumber): string
    {
        $trackingNumber = strtoupper($trackingNumber);
        $carriers = [
            'UPS' => '/^(1Z|T|Z)/',
            'FedEx' => '/^\d{12,15}$/',
            'USPS' => '/^(94|92|93|95|96|94)/',
            'DHL' => '/^\d{10}$/',
            'Amazon Logistics' => '/^TBA\d+/'
        ];

        foreach ($carriers as $carrierName => $pattern) {
            if (preg_match($pattern, $trackingNumber)) {
                return $carrierName;
            }
        }

        return 'Standard';
    }
}

if (!function_exists('getTrackingUrl')) {
    function getTrackingUrl(string $trackingNumber, string $carrier): string
    {
        $trackingNumber = strtoupper(trim($trackingNumber));

        switch ($carrier) {
            case 'UPS':
                return "https://wwwapps.ups.com/WebTracking/track?track=yes&trackNums={$trackingNumber}";
            case 'FedEx':
                return "https://www.fedex.com/fedextrack/?tracknumbers={$trackingNumber}";
            case 'USPS':
                return "https://tools.usps.com/go/TrackConfirmAction?tLabels={$trackingNumber}";
            case 'DHL':
                return "https://www.dhl.com/en/express/tracking.html?AWB={$trackingNumber}";
            case 'Amazon Logistics':
                return "https://track.amazon.com/track/{$trackingNumber}";
            default:
                return 'USPS'; 
        }
    }
}

if (!function_exists('getCarrierNames')) {
    function getCarrierNames()
    {
        return Shipment::whereNotNull('carrier')
            ->where('carrier', '!=', '')
            ->distinct()
            ->pluck('carrier');
    }
}
if (!function_exists('getMarketplaces')) {
    function getMarketplaces()
    {
        return Order::query()
            ->whereIn('marketplace', [
                'ebay1', 'ebay2', 'ebay3', 'shopify',
                'reverb', 'PLS', 'Temu', 'TikTok',
                'Best Buy USA', 'Business 5core', 'Wayfair', "Macy's, Inc.",'Aliexpress','amazon'
            ])
            ->pluck('marketplace')
            ->unique()
            ->values();
    }
}