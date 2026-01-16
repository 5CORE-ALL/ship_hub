<?php

namespace App\Repositories;

use App\Services\ShopifyFulfillmentService;
use App\Services\EbayOrderService;
use App\Services\WalmartFulfillmentService;
use App\Services\ReverbFulfillmentService;
use App\Services\BusinessFiveCoreShopifyFulfillmentService;
use App\Services\ProLightSoundsFulfillmentService;
use App\Services\MiraklFulfillmentService;
use App\Services\AliExpressAuthService;
use App\Services\TikTokAuthService;
// use App\Services\AmazonFulfillmentService; // DISABLED: No longer maintaining Amazon orders shipment 
use Illuminate\Support\Facades\Log;
use App\Models\Order;

class FulfillmentRepository
{
    protected array $services;

    public function __construct(
        ShopifyFulfillmentService $shopifyService,
        EbayOrderService $ebayService,
        WalmartFulfillmentService $walmartService,
        ReverbFulfillmentService $reverbService,
        BusinessFiveCoreShopifyFulfillmentService $business5coreService,
        ProLightSoundsFulfillmentService $plsService,
        MiraklFulfillmentService $miracleService,
        AliExpressAuthService $aliexpressService,
        TikTokAuthService $tiktokService,
        // AmazonFulfillmentService $amazonService, // DISABLED: No longer maintaining Amazon orders shipment
        // TemuFulfillmentService $temuService,
    ) {
        $this->services = [
            'shopify' => $shopifyService,
            'ebay'    => $ebayService,
            'walmart' => $walmartService,
            'reverb'  => $reverbService,
            'business_5core' => $business5coreService,
            'pls'           => $plsService,
            'miracle'       => $miracleService,
            'aliexpress'    => $aliexpressService,
            'tiktok'        => $tiktokService,
            // 'amazon'        => $amazonService, // DISABLED: No longer maintaining Amazon orders shipment

            // 'temu'    => $temuService,
        ];
    }

    /**
     * Create fulfillment for a marketplace order
     */
    public function createFulfillment(
        string $marketplace,
        ?int $storeId,
        string $orderNumber,
        ?string $trackingNumber = null,
        ?string $carrier = null,
        ?string $shippingServiceCode = null
    ): ?array {
          if (in_array(strtolower($marketplace), ['shopify'])) {
            Log::info("➡ {$marketplace} order routed to Shopify fulfillment");
            return $this->services['shopify']->fulfillOrder(
                $marketplace,
                $storeId,
                $orderNumber,
                $trackingNumber,
                $carrier,
                $shippingServiceCode
            );
        }
      
        if ($marketplace === 'pls' || $marketplace === 'prolightsounds') {
            Log::info("➡ {$marketplace} order routed to ProLightSounds fulfillment");
            return $this->services['pls']->fulfillOrder(
                $marketplace,
                $storeId,
                $orderNumber,
                $trackingNumber,
                $carrier,
                $shippingServiceCode
            );
        }
       $mp = strtolower(trim($marketplace));
    if ($mp === 'best buy usa' || $mp === "macy's, inc.") {
    
            Log::info("➡ {$marketplace} order routed to Mirakl fulfillment");

            $order = \App\Models\Order::with('items')->where('order_number', $orderNumber)->first();
            if (!$order) {
                Log::error("❌ Mirakl order not found: {$orderNumber}");
                return ['success' => false, 'error' => 'Order not found'];
            }

            $lines = [];
            foreach ($order->items as $item) {
                $lines[] = [
                    'lineNumber' => $item->line_number ?? $item->order_item_id,
                    'quantity'   => $item->quantity_ordered,
                ];
            }

            $shipDate = now()->toDateString();

            return $this->services['miracle']->fulfillOrder(
                $orderNumber,
                $lines,
                $trackingNumber ?? 'NA',
                $carrier ?? $order->shipping_carrier,
                $shipDate
            );
        }
        if (in_array(strtolower($marketplace), ['Business 5core'])) {
            Log::info("➡ {$marketplace} order routed to Shopify fulfillment");
            return $this->services['business_5core']->fulfillOrder(
                $marketplace,
                $storeId,
                $orderNumber,
                $trackingNumber,
                $carrier,
                $shippingServiceCode
            );
        }
        $normalizedMarketplace = strtolower(trim(str_replace(' ', '', $marketplace)));
        if (in_array($normalizedMarketplace, ['ebay1', 'ebay3'])) {
            Log::info("➡ {$marketplace} order routed to eBay fulfillment");
            $order = \App\Models\Order::with('items')->where('order_number', $orderNumber)->first();
            
            // Map marketplace name to store ID if storeId is missing or 0
            if (!$storeId || $storeId == 0) {
                $marketplaceStoreIdMap = [
                    'ebay1' => 3,
                    'ebay3' => 5,
                ];
                $storeId = $marketplaceStoreIdMap[$normalizedMarketplace] ?? null;
                if ($storeId) {
                    Log::info("Mapped {$marketplace} to store_id: {$storeId}");
                }
            }
            
            // Also try to get store_id from the order if still missing
            if ((!$storeId || $storeId == 0) && $order && $order->store_id) {
                $storeId = $order->store_id;
                Log::info("Using store_id from order: {$storeId}");
            }
            
            // Validate storeId before proceeding
            if (!$storeId || $storeId == 0) {
                Log::error("❌ Cannot sync tracking for {$marketplace}: No valid store_id found");
                return ['success' => false, 'error' => 'No valid store_id found for marketplace'];
            }
            
            return $this->services['ebay']->updateAfterLabelCreate(
                $storeId,
                $orderNumber,
                $trackingNumber,
                $carrier,
                $shippingServiceCode
            ) ? ['success' => true] : null;
        }

        // Walmart fulfillment
        if ($marketplace === 'walmart') {
            if (!isset($this->services['walmart'])) {
                Log::error("❌ Walmart fulfillment service not configured");
                return null;
            }

            $walmartService = $this->services['walmart'];

            $order = \App\Models\Order::with('items')->where('order_number', $orderNumber)->first();
            if (!$order) {
                Log::error("❌ Walmart order not found: {$orderNumber}");
                return null;
            }

            $lines = [];
            foreach ($order->items as $item) {
                $lines[] = [
                    'lineNumber' => $item->line_number ?? $item->order_item_id,
                    'quantity' => $item->quantity_ordered,
                    'acknowledgedQuantity' => $item->quantity_ordered,
                ];
            }

            $shipDate = now()->toDateString();

            return $walmartService->fulfillOrder(
                $orderNumber,
                $lines,
                'NA',
                $carrier ?? $order->shipping_carrier,
                $shipDate
            );
        }
        if ($marketplace === 'reverb') {
            return $this->services['reverb']->fulfillOrder(
                $orderNumber,
                $carrier,
                $trackingNumber,
                true
            );
        }
       if ($marketplace === 'aliexpress') {
            $order = Order::query()
                ->select('orders.*', 'shipments.tracking_url', 'shipments.tracking_number')
                ->leftJoin('shipments', 'shipments.order_id', '=', 'orders.id')
                ->where('orders.order_number', $orderNumber)
                ->first();

            if (!$order) {
                Log::error("❌ AliExpress order not found: {$orderNumber}");
                return ['success' => false, 'error' => 'Order not found'];
            }

            $shipmentTrackingNumber = $order->tracking_number;
            $carriernm = detectCarrier($shipmentTrackingNumber);
            $trackingUrls = getTrackingUrl($shipmentTrackingNumber, $carriernm);

            return $this->services['aliexpress']->fulfillOrder(
                $orderNumber,
                $shipmentTrackingNumber,
                'SELLER_SHIPPING_US_LOCAL', 
                $trackingUrls 
            );
        }
         if ($mp === 'tiktok' || $mp === 'tiktokshop') {
            Log::info("➡ {$marketplace} order routed to TikTok fulfillment");

         $order = Order::query()
                ->select('orders.*', 'shipments.tracking_url', 'shipments.tracking_number')
                ->leftJoin('shipments', 'shipments.order_id', '=', 'orders.id')
                ->where('orders.order_number', $orderNumber)
                ->first();
            if (!$order) {
                Log::error("❌ TikTok order not found: {$orderNumber}");
                return ['success' => false, 'error' => 'Order not found'];
            }

            $shippingProviderId = $order->shipping_provider_id ?? null;
            $shipmentTrackingNumber = $order->tracking_number;
            if (!$shippingProviderId) {
                Log::warning("⚠ No shipping provider ID found for TikTok order: {$orderNumber}");
            }
            $carrier = detectCarrier($trackingNumber);
            if (!$shippingProviderId) {
                $shippingProviderId = match (strtolower($carrier)) {
                    'ups'  => '7117859084333745966',
                    'usps' => '7117858858072016686',
                    default => null,
                };
            }

            try {
                return $this->services['tiktok']->fulfillOrder(
                    accessToken: $this->services['tiktok']->getAccessToken($storeId),
                    shopCipher:  $this->services['tiktok']->getAuthorizedShopCipher($storeId),
                    orderId:     $order->order_number,
                    trackingNumber: $shipmentTrackingNumber,
                    shippingProviderId: $shippingProviderId
                );
            } catch (\Throwable $e) {
                Log::error("❌ TikTok fulfillment failed: {$e->getMessage()}");
                return ['success' => false, 'error' => $e->getMessage()];
            }
        }

        // Amazon fulfillment - DISABLED: No longer maintaining Amazon orders shipment
        // $amazonMarketplace = strtolower(trim($marketplace));
        // if ($amazonMarketplace === 'amazon') {
        //     Log::info("➡ {$marketplace} order routed to Amazon fulfillment");
        //     
        //     if (!isset($this->services['amazon'])) {
        //         Log::error("❌ Amazon fulfillment service not configured");
        //         return null;
        //     }

        //     try {
        //         return $this->services['amazon']->fulfillOrder(
        //             $marketplace,
        //             $storeId,
        //             $orderNumber,
        //             $trackingNumber,
        //             $carrier,
        //             $shippingServiceCode
        //         );
        //     } catch (\Throwable $e) {
        //         Log::error("❌ Amazon fulfillment failed: {$e->getMessage()}");
        //         return ['success' => false, 'error' => $e->getMessage()];
        //     }
        // }
        if (!isset($this->services[$marketplace])) {
            Log::error("❌ No fulfillment service found for marketplace: {$marketplace}");
            return null;
        }

        return $this->services[$marketplace]->fulfillOrder(
            $marketplace,
            $storeId,
            $orderNumber,
            $trackingNumber,
            $carrier,
            $shippingServiceCode
        );
    }
}
