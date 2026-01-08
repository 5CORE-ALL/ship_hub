<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Shipper;
use App\Models\OrderShippingRate;
use App\Models\Shipment;
use App\Models\BulkShippingHistory;
use setasign\Fpdi\Fpdi;
use Illuminate\Support\Facades\Storage;
use App\Repositories\FulfillmentRepository;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use App\Services\BulkShippingSummaryService;
use Illuminate\Support\Facades\Auth;
use Carbon\Carbon;


class ShippingLabelService
{
    protected ShipStationService $shipStation;
    protected SendleService $sendle;
    protected ShippoService $shippo;
    protected FulfillmentRepository $fulfillmentRepo;
    public function __construct(
        ShipStationService $shipStation,
        SendleService $sendle,
        ShippoService $shippo,
        FulfillmentRepository $fulfillmentRepo
    ) {
        $this->shipStation = $shipStation;
        $this->sendle = $sendle;
        $this->shippo = $shippo;
        $this->fulfillmentRepo = $fulfillmentRepo;
    }

    /**
     * Create shipping labels for multiple orders
     * @param array $orderIds
     * @param mixed $request Optional, used for rate_id
     * @return array
     */
    /**
     * Create shipping labels for multiple orders
     * @param array $orderIds
     * @param mixed $request Optional, used for rate_id
     * @return array
     */
    public function createLabels(array $orderIds,$userId, $rateInfoMap = null): array
    {
        ini_set("max_execution_time", 12000);
        $labels = [];
        $successCount = 0;
        $failedCount = 0;

        foreach ($orderIds as $orderId) {
            try {
                // CRITICAL: Check if order is locked by another process
                $order = Order::with(["items", "cheapestRate"])->find($orderId);

                if (!$order) {
                    Log::warning("Order not found during bulk shipping", ['order_id' => $orderId]);
                    $labels[] = [
                        "order_id" => $orderId,
                        "success" => false,
                        "message" => "Order not found",
                        "provider" => "unknown",
                        "source" => "unknown"
                    ];
                    $failedCount++;
                    continue;
                }
                
                // CRITICAL: Check if order is locked by another process (conflict detection)
                // Convert queue_started_at to Carbon if it's a string
                $queueStartedAt = $order->queue_started_at;
                if ($queueStartedAt && !($queueStartedAt instanceof Carbon)) {
                    try {
                        $queueStartedAt = Carbon::parse($queueStartedAt);
                    } catch (\Exception $e) {
                        Log::warning("Failed to parse queue_started_at for order {$orderId}", [
                            'queue_started_at' => $order->queue_started_at,
                            'error' => $e->getMessage()
                        ]);
                        $queueStartedAt = null;
                    }
                }
                
                if ($order->queue == 1 && $queueStartedAt && $queueStartedAt->lt(now()->subMinutes(10))) {
                    // Order has been locked for more than 10 minutes - likely stuck
                    Log::warning("Order {$orderId} appears to be stuck in queue, unlocking and skipping", [
                        'queue_started_at' => $queueStartedAt,
                        'minutes_locked' => $queueStartedAt->diffInMinutes(now())
                    ]);
                    $order->update(['queue' => 0]);
                } elseif ($order->queue == 1 && $queueStartedAt && $queueStartedAt->gt(now()->subMinutes(1))) {
                    // Order was recently locked (within last minute) - likely being processed by another request
                    Log::warning("Order {$orderId} is currently being processed by another request, skipping", [
                        'queue_started_at' => $queueStartedAt
                    ]);
                    $labels[] = [
                        "order_id" => $orderId,
                        "success" => false,
                        "message" => "Order is currently being processed by another request. Please try again in a moment.",
                        "provider" => "unknown",
                        "source" => "unknown"
                    ];
                    $failedCount++;
                    continue;
                }
                
                // CRITICAL: Check for active shipment to prevent duplicate label creation
                $activeShipmentExists = Shipment::where("order_id", $orderId)
                    ->where("label_status", "active")
                    ->exists();

                if ($activeShipmentExists) {
                    Log::info("Order {$orderId} already has active shipment, skipping label creation");
                    $labels[] = [
                        "order_id" => $orderId,
                        "success" => true,
                        "message" =>
                            "Active label already exists, skipped creation",
                        "provider" => "unknown",
                        "source" => "unknown",
                    ];
                    $successCount++; // Count as success since label already exists
                    continue;
                }

                // Check if rate type is specified (O for Best Rate O, D for Best Rate D)
                $rateInfo = is_array($rateInfoMap) && isset($rateInfoMap[$orderId]) ? $rateInfoMap[$orderId] : ['rate_type' => 'D'];
                $rateType = $rateInfo['rate_type'] ?? 'D';
                
                $selectedRate = null;
                $rateId = null;
                $source = null;
                $carrier = null;

                // For rate type O, use the rate_info passed from frontend
                if ($rateType === 'O' && isset($rateInfo['rate_info']) && $rateInfo['rate_info']) {
                    Log::info("Using Best Rate (O) for order {$orderId}", [
                        'order_id' => $orderId,
                        'rate_type' => $rateType,
                        'rate_info' => $rateInfo['rate_info']
                    ]);
                    
                    // Try to find the rate by rate_id first (if provided)
                    if (!empty($rateInfo['rate_id'])) {
                        $selectedRate = OrderShippingRate::where('order_id', $orderId)
                            ->where('rate_id', $rateInfo['rate_id'])
                            ->first();
                    }
                    
                    // If not found by rate_id, try to find by carrier and service
                    if (!$selectedRate && !empty($rateInfo['rate_info']['carrier']) && !empty($rateInfo['rate_info']['service'])) {
                        $selectedRate = OrderShippingRate::where('order_id', $orderId)
                            ->where('carrier', $rateInfo['rate_info']['carrier'])
                            ->where('service', $rateInfo['rate_info']['service'])
                            ->first();
                    }
                    
                    if ($selectedRate) {
                        $rateId = $selectedRate->rate_id;
                        $source = $selectedRate->source;
                        $carrier = $selectedRate->carrier;
                        Log::info("Found Best Rate (O) for order {$orderId}", [
                            'rate_id' => $rateId,
                            'carrier' => $carrier,
                            'service' => $selectedRate->service,
                            'price' => $selectedRate->price
                        ]);
                    } else {
                        Log::warning("Best Rate (O) not found in database for order {$orderId}, falling back to cheapest rate", [
                            'rate_info' => $rateInfo['rate_info']
                        ]);
                        // Fallback to cheapest rate if Best Rate (O) not found
                        $selectedRate = $order->cheapestRate;
                    }
                } else {
                    // Default to cheapest rate for rate type D
                    $selectedRate = $order->cheapestRate;
                }

                if (!$selectedRate) {
                    $labels[] = [
                        "order_id" => $orderId,
                        "success" => false,
                        "message" => $rateType === 'O' ? "Best Rate (O) not found for this order" : "No cheapest shipping rate found",
                    ];
                    $failedCount++;
                    continue;
                }

                // Use selected rate values if not already set (for rate type D)
                if (!$rateId) {
                    $rateId = $selectedRate->rate_id;
                    $source = $selectedRate->source;
                    $carrier = $selectedRate->carrier;
                }

                $shipper = Shipper::first();
                
                // Calculate weight and dimensions from order items using original values
                $orderWeightLb = $order->items->sum(function($item) {
                    return $item->original_weight ?? $item->weight ?? 0;
                }) ?: 1.0;
                $weightInKg = round($orderWeightLb * 0.453592, 2);
                $length = $order->items->sum(function($item) {
                    return $item->original_length ?? $item->length ?? 0;
                }) ?: 20;
                $width = $order->items->sum(function($item) {
                    return $item->original_width ?? $item->width ?? 0;
                }) ?: 15;
                $height = $order->items->sum(function($item) {
                    return $item->original_height ?? $item->height ?? 0;
                }) ?: 10;

                $provider = strtolower($source);
                Log::info("Provider value: " . $provider);
                $itemsPart = $order->items
                    ->map(function ($item) {
                        return $item->sku . "x" . $item->quantity_ordered;
                    })
                    ->implode(",");

                $orderPart = "|ORD:" . $order->order_number;
                $customerReference = $itemsPart . $orderPart;
                $customerReference = substr($customerReference, 0, 35);

                if ($provider === "sendle") {
                    $items = OrderItem::where("order_id", $order->id)->get();
                    $skuQuantities = $items
                        ->groupBy("sku")
                        ->map(function ($group) {
                            return $group->sum("quantity_ordered");
                        });
                    if ($skuQuantities->count() > 1) {
                        $skuQtyString = "multi";
                    } elseif ($skuQuantities->count() == 1) {
                        $sku = $skuQuantities->keys()->first();
                        $qty = $skuQuantities->first();
                        // $skuQtyString = "{$sku}: {$qty}pcs";
                        $skuQtyString = "{$sku}: " . ($qty > 1 ? "{$qty}pcs" : $qty);
                    } else {
                        $skuQtyString = "";
                    }
                    $pickupDate = now()->addDay();
                    while ($pickupDate->isWeekend()) {
                        $pickupDate->addDay();
                    }
                    $pickupDate->setTime(10, 0, 0);
                    $payload = [
                        "sender" => [
                            "contact" => [
                                "name" => "Sam Sam",
                                "email" => $shipper->email ?? "-",
                                "phone" =>
                                    $shipper->shipper_number ?? "1111111111",
                                "company" => $shipper->name ?? "-",
                            ],
                            "address" => [
                                "country" =>
                                    $shipper->country ??
                                    ($order->shipper_country ?? "US"),
                                "address_line1" => $shipper->address ?? "-",
                                "suburb" => $shipper->city ?? "-",
                                "postcode" => $shipper->postal_code ?? "11331",
                                "state_name" => $shipper->state ?? "CA",
                            ],
                        ],
                        "receiver" => [
                            "contact" => array_filter([
                                "name" => $order->recipient_name,
                                "company" => $order->items->isNotEmpty()
                                    ? "($skuQtyString)"
                                    : null,
                            ], fn($value) => $value !== null),
                            "address" => [
                                "country" => !empty($order->ship_country)
                                    ? $order->ship_country
                                    : "US",
                                "address_line1" => !empty($order->ship_address1)
                                    ? $order->ship_address1
                                    : "N/A",
                                "address_line2" => !empty($order->ship_address2)
                                    ? $order->ship_address2
                                    : "",
                                "suburb" => !empty($order->ship_city)
                                    ? $order->ship_city
                                    : "N/A",
                                "postcode" => !empty($order->ship_postal_code)
                                    ? $order->ship_postal_code
                                    : "11331",
                                "state_name" => !empty($order->ship_state)
                                    ? $order->ship_state
                                    : "CA",
                            ],
                            "instructions" => "N/A",
                        ],
                        "weight" => [
                            "units" => "kg",
                            "value" => $weightInKg,
                        ],
                        "dimensions" => [
                            "units" => "cm",
                            "length" => $length,
                            "width" => $width,
                            "height" => $height,
                        ],
                        "description" => $order->description ?? "Goods",
                        "customer_reference" => substr(
                            $order->order_number ?? "",
                            0,
                            30
                        ),
                        "product_code" => $rateId,
                        // "pickup_date"        => $pickupDate->format('Y-m-d\TH:i:s'),
                        "packaging_type" => "box",
                        "hide_pickup_address" => true,
                        "labels" => [
                            [
                                "format" => "pdf",
                                "size" => "cropped",
                            ],
                        ],
                    ];

                    Log::info("Sendle payload for order {$orderId}", $payload);
                    // $label = $this->sendle->createOrder($payload);
                    try {
                        $label = $this->sendle->createOrder($payload);
                    } catch (\Exception $e) {
                        Log::error(
                            "Sendle API call failed for order {$orderId}",
                            [
                                "payload" => $payload,
                                "error" => $e->getMessage(),
                                "trace" => $e->getTraceAsString(),
                            ]
                        );
                        $failedCount++;
                        // CRITICAL FIX: Add to labels array before continuing
                        $labels[] = [
                            "order_id" => $orderId,
                            "success" => false,
                            "provider" => $provider,
                            "source" => $source,
                            "error" => "Sendle API exception: " . $e->getMessage(),
                            "message" => "Sendle API call failed: " . $e->getMessage(),
                        ];
                        continue; // go to next order
                    }

                    $isFailed =
                        !isset($label["success"]) || $label["success"] !== true;
                    if ($isFailed) {
                        $failedCount++;
                        // Preserve original order_status to keep order visible in awaiting shipment
                        // Only update label_status and printing_status, don't change order_status
                        $order->update([
                            "label_status" => "failed",
                            "printing_status" => 0,
                            // Keep original order_status - don't change it
                            // "order_status" => "unshipped", // REMOVED - preserve original status
                            // "fulfillment_status" => "pending", // REMOVED - preserve original status
                        ]);

                        Log::error(
                            "Sendle shipment failed for order {$order->id}",
                            [
                                "error" =>
                                    $label["error"] ?? "Unknown Sendle error",
                                "raw" => $label,
                            ]
                        );

                        $result = [
                            "success" => false,
                            "error" =>
                                $label["error"] ?? "Unknown Sendle error",
                        ];
                        \DB::table("transaction_logs")->insert([
                            "reference_id" => $orderId,
                            "marketplace" => $order->marketplace,
                            "transaction_type" => "shipment",
                            "payload" => json_encode($label),
                            "status" => "failed",
                            "error_message" => is_array(
                                $label["raw"]["messages"] ?? null
                            )
                                ? json_encode($label["raw"]["messages"])
                                : $label["error"] ?? "Unknown Shippo error",
                            "created_at" => now(),
                            "updated_at" => now(),
                        ]);
                    } else {
                        $trackingNumber = isset($label["raw"]["tracking_url"])
                            ? getSendleLocalTrackingId(
                                $label["raw"]["tracking_url"]
                            )
                            : null;

                        // CRITICAL FIX: Wrap in transaction with verification
                        try {
                            DB::transaction(function () use ($order, $label, $trackingNumber, $userId, &$shipment) {
                                // Update order first
                                $order->update([
                                    "label_id" => $label["label_id"] ?? null,
                                    "tracking_number" => $trackingNumber ?? null,
                                    "label_url" => $label["labelUrl"] ?? null,
                                    "shipping_carrier" => $label["raw"]["carrier_code"] ?? "sendle",
                                    "shipping_service" => $label["raw"]["service_code"] ?? null,
                                    "shipping_cost" => $label["raw"]["shipment_cost"]["amount"] ?? 0,
                                    "ship_date" => $label["shipDate"] ?? now(),
                                    "label_status" => "purchased",
                                    "label_source" => "api",
                                    "fulfillment_status" => "shipped",
                                    "order_status" => "Shipped",
                                    "printing_status" => 1,
                                ]);

                                // Create shipment - if this fails, transaction will rollback order update
                                $shipment = Shipment::create([
                                    "order_id" => $order->id,
                                    "tracking_number" => $trackingNumber,
                                    "carrier" => detectCarrier($trackingNumber) ?: 'Standard',
                                    "label_id" => $label["label_id"] ?? null,
                                    "service_type" => $label["raw"]["service_code"] ?? null,
                                    "package_weight" => $label["raw"]["packages"][0]["weight"]["value"] ?? null,
                                    "package_dimensions" => json_encode($label["raw"]["packages"][0]["dimensions"] ?? []),
                                    "label_url" => $label["labelUrl"] ?? null,
                                    "shipment_status" => "created",
                                    "label_data" => json_encode($label),
                                    "ship_date" => now(),
                                    "cost" => $label["raw"]["shipment_cost"]["amount"] ?? 0,
                                    "currency" => $label["raw"]["shipment_cost"]["currency"] ?? "USD",
                                    "tracking_url" => $label["raw"]["tracking_url"] ?? null,
                                    "label_status" => "active",
                                    "void_status" => "active",
                                    "created_by" => $userId
                                ]);

                                // Verify shipment was created successfully
                                if (!$shipment || !$shipment->id) {
                                    throw new \Exception("Shipment creation failed - no ID returned");
                                }
                            });

                            // Verify shipment exists after transaction
                            $shipment = Shipment::where('order_id', $order->id)
                                ->where('label_status', 'active')
                                ->latest()
                                ->first();

                            if (!$shipment) {
                                throw new \Exception("Shipment verification failed - no active shipment found after creation");
                            }

                            $successCount++;
                            Log::info("✅ Order {$order->id} and shipment created successfully (Sendle)");

                            // Sync tracking number to platform (outside transaction - non-critical)
                            if ($trackingNumber && $order->marketplace && $order->is_manual == 0) {
                                try {
                                    $this->fulfillmentRepo->createFulfillment(
                                        $order->marketplace,
                                        $order->store_id ?? 0,
                                        $order->order_number,
                                        $trackingNumber,
                                        detectCarrier($trackingNumber),
                                        $label["raw"]["service_code"] ?? null
                                    );
                                } catch (\Exception $e) {
                                    Log::warning("⚠️ Failed to sync tracking to {$order->marketplace} for order {$order->order_number}: " . $e->getMessage());
                                }
                            }
                            
                            // Set success result after successful transaction
                            $result = [
                                "success" => true,
                                "label_url" => $label["labelUrl"] ?? null,
                            ];
                        } catch (\Exception $e) {
                            // Transaction failed or shipment creation failed - rollback happened automatically
                            Log::error("CRITICAL: Sendle transaction failed for order {$orderId}", [
                                "error" => $e->getMessage(),
                                "trace" => $e->getTraceAsString(),
                            ]);
                            $failedCount++;
                            // Preserve original order_status to keep order visible in awaiting shipment
                            $order->update([
                                "label_status" => "failed",
                                "printing_status" => 0,
                                // Keep original order_status - don't change it
                                // "order_status" => "unshipped", // REMOVED - preserve original status
                                // "fulfillment_status" => "pending", // REMOVED - preserve original status
                            ]);
                            $result = [
                                "success" => false,
                                "error" => "Transaction failed: " . $e->getMessage(),
                                "message" => "Failed to create shipment: " . $e->getMessage(),
                            ];
                        }
                    }
                } elseif ($provider === "shippo") {
                    try {
                        $label = $this->shippo->createLabelByRateId($rateId);
                    } catch (\Exception $e) {
                        Log::error(
                            "Shippo API call failed for order {$orderId}",
                            [
                                "rate_id" => $rateId,
                                "error" => $e->getMessage(),
                                "trace" => $e->getTraceAsString(),
                            ]
                        );
                        $failedCount++;
                        // CRITICAL FIX: Add to labels array before continuing
                        $labels[] = [
                            "order_id" => $orderId,
                            "success" => false,
                            "provider" => $provider,
                            "source" => $source,
                            "error" => "Shippo API exception: " . $e->getMessage(),
                            "message" => "Shippo API call failed: " . $e->getMessage(),
                        ];
                        continue;
                    }
                    
                    $orderRate = OrderShippingRate::where(
                        "rate_id",
                        $rateId
                    )->first();
                    $shippingCost = $orderRate->price ?? 0;
                    $carrier = $orderRate->carrier ?? "shippo";
                    $service = $orderRate->service ?? null;
                    $isFailed =
                        (isset($label["raw"]["status"]) &&
                            $label["raw"]["status"] === "ERROR") ||
                        (isset($label["success"]) &&
                            $label["success"] === false);

                    if ($isFailed) {
                        $failedCount++;
                        // Preserve original order_status to keep order visible in awaiting shipment
                        $order->update([
                            "label_status" => "failed",
                            "printing_status" => 0,
                            // Keep original order_status - don't change it
                            // "order_status" => "unshipped", // REMOVED - preserve original status
                        ]);

                        Log::error(
                            "Shippo shipment failed for order {$order->id}",
                            [
                                "messages" =>
                                    $label["raw"]["messages"] ??
                                    ($label["error"] ?? []),
                                "raw" => $label["raw"] ?? $label,
                            ]
                        );

                        $result = [
                            "success" => false,
                            "error" =>
                                $label["raw"]["messages"] ??
                                ($label["error"] ?? []),
                        ];
                    } else {
                        // CRITICAL FIX: Wrap in transaction to ensure atomicity
                        // Either both order update AND shipment creation succeed, or neither happens
                        try {
                            DB::transaction(function () use ($order, $label, $carrier, $service, $shippingCost, $userId, &$shipment) {
                                // Update order first
                                $order->update([
                                    "label_id" => $label["label_id"] ?? null,
                                    "tracking_number" =>
                                        $label["trackingNumber"] ?? null,
                                    "label_url" => $label["labelUrl"] ?? null,
                                    "shipping_carrier" => $carrier,
                                    "shipping_service" => $service,
                                    "shipping_cost" => $shippingCost,
                                    "ship_date" =>
                                        $label["raw"]["object_created"] ?? now(),
                                    "label_status" => "purchased",
                                    "label_source" => "api",
                                    "fulfillment_status" => "shipped",
                                    "order_status" => "Shipped",
                                    "printing_status" => 1,
                                ]);

                                // Create shipment - if this fails, transaction will rollback order update
                                $shipment = Shipment::create([
                                    "order_id" => $order->id,
                                    "tracking_number" =>
                                        $label["trackingNumber"] ?? null,
                                    "carrier" => detectCarrier($label["trackingNumber"]) ?: 'Standard',
                                    "label_id" => $label["label_id"] ?? null,
                                    "service_type" => $label["raw"]["rate"] ?? null,
                                    "package_weight" =>
                                        $label["raw"]["parcel"]["weight"] ?? null,
                                    "package_dimensions" => json_encode(
                                        $label["raw"]["parcel"]["dimensions"] ?? []
                                    ),
                                    "label_url" => $label["labelUrl"] ?? null,
                                    "shipment_status" => "created",
                                    "label_data" => json_encode($label),
                                    "ship_date" =>
                                        $label["raw"]["object_created"] ?? now(),
                                    "cost" => $shippingCost,
                                    "currency" =>
                                        $label["raw"]["shipment_cost"]["currency"] ??
                                        "USD",
                                    "tracking_url" =>
                                        $label["raw"]["tracking_url_provider"] ?? null,
                                    "label_status" => "active",
                                    "void_status" => "active",
                                    "created_by"=>$userId
                                ]);

                                // Verify shipment was created successfully
                                if (!$shipment || !$shipment->id) {
                                    throw new \Exception("Shipment creation failed - no ID returned");
                                }
                            });

                            // Verify shipment exists after transaction
                            $shipment = Shipment::where('order_id', $order->id)
                                ->where('label_status', 'active')
                                ->latest()
                                ->first();

                            if (!$shipment) {
                                throw new \Exception("Shipment verification failed - no active shipment found after creation");
                            }

                            $successCount++;
                            Log::info("✅ Order {$order->id} and shipment created successfully (Shippo)");

                            // Sync tracking number to platform (outside transaction - non-critical)
                            $trackingNumber = $label["trackingNumber"] ?? null;
                            if ($trackingNumber && $order->marketplace && $order->is_manual == 0) {
                                try {
                                    $this->fulfillmentRepo->createFulfillment(
                                        $order->marketplace,
                                        $order->store_id ?? 0,
                                        $order->order_number,
                                        $trackingNumber,
                                        $carrier,
                                        $service
                                    );
                                    Log::info("✅ Tracking number synced to {$order->marketplace} for order {$order->order_number}");
                                } catch (\Exception $e) {
                                    Log::warning("⚠️ Failed to sync tracking to {$order->marketplace} for order {$order->order_number}: " . $e->getMessage());
                                }
                            }

                            $result = [
                                "success" => true,
                                "label_url" => $label["labelUrl"] ?? null,
                            ];
                        } catch (\Exception $e) {
                            // Transaction failed or shipment creation failed - rollback happened automatically
                            Log::error("CRITICAL: Shippo transaction failed for order {$orderId}", [
                                "error" => $e->getMessage(),
                                "trace" => $e->getTraceAsString(),
                            ]);
                            $failedCount++;
                            // Preserve original order_status to keep order visible in awaiting shipment
                            $order->update([
                                "label_status" => "failed",
                                "printing_status" => 0,
                                // Keep original order_status - don't change it
                                // "order_status" => "unshipped", // REMOVED - preserve original status
                            ]);
                            $result = [
                                "success" => false,
                                "error" => "Transaction failed: " . $e->getMessage(),
                                "message" => "Failed to create shipment: " . $e->getMessage(),
                            ];
                        }
                    }
                }
                else {
                    try {
                        $label = $this->shipStation->createLabelByRateId($rateId);
                    } catch (\Exception $e) {
                        Log::error(
                            "ShipStation API call failed for order {$orderId}",
                            [
                                "rate_id" => $rateId,
                                "error" => $e->getMessage(),
                                "trace" => $e->getTraceAsString(),
                            ]
                        );
                        $failedCount++;
                        // CRITICAL FIX: Add to labels array before continuing
                        $labels[] = [
                            "order_id" => $orderId,
                            "success" => false,
                            "provider" => $provider,
                            "source" => $source,
                            "error" => "ShipStation API exception: " . $e->getMessage(),
                            "message" => "ShipStation API call failed: " . $e->getMessage(),
                        ];
                        continue;
                    }
                    
                    $isFailed =
                        isset($label["success"]) && $label["success"] === false;
                    $isSuccess =
                        isset($label["success"]) && $label["success"] === true;

                    if ($isFailed) {
                        $failedCount++;

                        $errorMessage =
                            $label["error"]["errors"][0]["message"] ??
                            ($label["errors"][0]["message"] ??
                                "Unknown ShipStation error");

                        // Preserve original order_status to keep order visible in awaiting shipment
                        $order->update([
                            "label_status" => "failed",
                            "printing_status" => 0,
                            // Keep original order_status - don't change it
                            // "order_status" => "unshipped", // REMOVED - preserve original status
                            // "fulfillment_status" => "pending", // REMOVED - preserve original status
                        ]);

                        Log::warning(
                            "⚠️ ShipStation label creation failed for order {$orderId}",
                            [
                                "error" => $errorMessage,
                                "response" => $label,
                            ]
                        );

                        $result = [
                            "success" => false,
                            "error" => $errorMessage,
                        ];
                    } elseif (
                        $isSuccess &&
                        !empty($label["label_id"])
                    ) {
                        // CRITICAL FIX: Wrap in transaction to ensure atomicity
                        // Either both order update AND shipment creation succeed, or neither happens
                        try {
                            // Extract shipping cost from multiple possible locations
                            $shippingCost = $label["raw"]["shipment_cost"]["amount"] ?? 
                                           $label["raw"]["shipment_cost"] ?? 
                                           $label["raw"]["cost"] ?? 
                                           $label["raw"]["shipping_cost"] ?? 
                                           $label["cost"] ?? 
                                           0;
                            
                            // Extract currency from multiple possible locations
                            $currency = $label["raw"]["shipment_cost"]["currency"] ?? 
                                       $label["raw"]["currency"] ?? 
                                       $label["currency"] ?? 
                                       "USD";

                            DB::transaction(function () use ($order, $orderId, $label, $shippingCost, $currency, $userId, &$shipment) {
                                // Update order first
                                $order->update([
                                    "label_id" => $label["label_id"],
                                    "tracking_number" =>
                                        $label["trackingNumber"] ?? null,
                                    "label_url" => $label["labelUrl"] ?? null,
                                    "shipping_carrier" =>
                                        $label["raw"]["carrier_code"] ?? null,
                                    "shipping_service" =>
                                        $label["raw"]["service_code"] ?? null,
                                    "shipping_cost" => $shippingCost,
                                    "ship_date" => $label["shipDate"] ?? $label["raw"]["ship_date"] ?? now(),
                                    "label_status" => "purchased",
                                    "label_source" => "api",
                                    "fulfillment_status" => "shipped",
                                    "order_status" => "Shipped",
                                    "printing_status" => 1,
                                ]);

                                // Create shipment - if this fails, transaction will rollback order update
                                $shipment = Shipment::create([
                                    "order_id" => $orderId,
                                    "tracking_number" => $label["trackingNumber"],
                                    "carrier" =>  detectCarrier($label["trackingNumber"]) ?: 'Standard',
                                    "label_id" => $label["label_id"],
                                    "service_type" => $label["raw"]["service_code"] ?? null,
                                    "package_weight" =>
                                        $label["raw"]["packages"][0]["weight"][
                                            "value"
                                        ] ?? $label["raw"]["packages"][0]["weight"] ?? null,
                                    "package_dimensions" => json_encode(
                                        $label["raw"]["packages"][0]["dimensions"] ?? []
                                    ),
                                    "label_url" => $label["labelUrl"],
                                    "shipment_status" => "created",
                                    "label_data" => json_encode($label),
                                    "ship_date" => now(),
                                    "cost" => $shippingCost,
                                    "currency" => $currency,
                                    "tracking_url" =>
                                        $label["raw"]["tracking_url"] ?? null,
                                    "label_status" => "active",
                                    "void_status" => "active",
                                    "created_by"=>$userId
                                ]);

                                // Verify shipment was created successfully
                                if (!$shipment || !$shipment->id) {
                                    throw new \Exception("Shipment creation failed - no ID returned");
                                }
                            });

                            // Verify shipment exists after transaction
                            $shipment = Shipment::where('order_id', $orderId)
                                ->where('label_status', 'active')
                                ->latest()
                                ->first();

                            if (!$shipment) {
                                throw new \Exception("Shipment verification failed - no active shipment found after creation");
                            }

                            $successCount++;
                            Log::info("✅ Order {$orderId} and shipment created successfully (ShipStation)");

                            // Sync tracking number to platform (outside transaction - non-critical)
                            $trackingNumber = $label["trackingNumber"] ?? null;
                            if ($trackingNumber && $order->marketplace && $order->is_manual == 0) {
                                try {
                                    $this->fulfillmentRepo->createFulfillment(
                                        $order->marketplace,
                                        $order->store_id ?? 0,
                                        $order->order_number,
                                        $trackingNumber,
                                        $label["raw"]["carrier_code"] ?? null,
                                        $label["raw"]["service_code"] ?? null
                                    );
                                    Log::info("✅ Tracking number synced to {$order->marketplace} for order {$order->order_number}");
                                } catch (\Exception $e) {
                                    Log::warning("⚠️ Failed to sync tracking to {$order->marketplace} for order {$order->order_number}: " . $e->getMessage());
                                }
                            }

                            $result = [
                                "success" => true,
                                "label_url" => $label["labelUrl"],
                            ];
                        } catch (\Exception $e) {
                            // Transaction failed or shipment creation failed - rollback happened automatically
                            Log::error("CRITICAL: ShipStation transaction failed for order {$orderId}", [
                                "error" => $e->getMessage(),
                                "trace" => $e->getTraceAsString(),
                            ]);
                            $failedCount++;
                            // Preserve original order_status to keep order visible in awaiting shipment
                            $order->update([
                                "label_status" => "failed",
                                "printing_status" => 0,
                                // Keep original order_status - don't change it
                                // "order_status" => "unshipped", // REMOVED - preserve original status
                                // "fulfillment_status" => "pending", // REMOVED - preserve original status
                            ]);
                            $result = [
                                "success" => false,
                                "error" => "Transaction failed: " . $e->getMessage(),
                                "message" => "Failed to create shipment: " . $e->getMessage(),
                            ];
                        }
                    } else {
                        $failedCount++;

                        // Preserve original order_status to keep order visible in awaiting shipment
                        $order->update([
                            "label_status" => "failed",
                            // Keep original order_status - don't change it
                            // "order_status" => "unshipped", // REMOVED - preserve original status
                            "printing_status" => 0,
                            // "fulfillment_status" => "pending", // REMOVED - preserve original status
                        ]);

                        \DB::table("transaction_logs")->insert([
                            "reference_id" => $orderId,
                            "marketplace" => $order->marketplace,
                            "transaction_type" => "shipment",
                            "payload" => json_encode([
                                "rate_id" => $rateId,
                                "request" => $label["request"] ?? null,
                                "response" => $label,
                            ]),
                            "status" => "failed",
                            "error_message" =>
                                $label["errors"][0]["message"] ??
                                "Unknown ShipStation error",
                            "created_at" => now(),
                            "updated_at" => now(),
                        ]);

                        Log::warning(
                            "⚠️ Unexpected ShipStation response for order {$orderId}",
                            [
                                "response" => $label,
                            ]
                        );

                        $result = [
                            "success" => false,
                            "error" => $label,
                        ];
                    }
                }
                // CRITICAL FIX: Ensure result is always set before adding to labels array
                if (!isset($result)) {
                    Log::warning("Result not set for order {$orderId}, creating default result", [
                        "provider" => $provider,
                        "source" => $source,
                    ]);
                    $result = [
                        "success" => false,
                        "error" => "Label processing completed but result was not set",
                        "message" => "Unexpected state: result variable was not initialized",
                    ];
                    $failedCount++;
                }
                
                $labels[] = array_merge(
                    [
                        "order_id" => $orderId,
                        "provider" => $provider,
                        "source" => $source,
                    ],
                    $result
                );
                if (
                    !empty($result["success"]) &&
                    $result["success"] === true &&
                    $order &&
                    $order->items &&
                    $order->items->count() > 1
                ) {
                    $uniqueSkus = $order->items->pluck('sku')->unique();
                    if ($uniqueSkus->count() > 1) {
                    try {
                        Mail::send(
                            "emails.multiple_skus",
                            [
                                "order" => $order,
                                "items" => $order->items,
                            ],
                            function ($message) use ($order, $result) {
                                $message
                                    ->to([
                                        "priyeshsurana5@gmail.com",
                                        "mgr-operations@5core.com",
                                        "shipping@5core.com",
                                        "labelshreya2023@gmail.com",
                                    ])
                                    ->from("software10@5core.com", "ShipHub")
                                    ->subject(
                                        "Order #{$order->order_number} - Multiple SKUs"
                                    );

                                if (!empty($result["label_url"])) {
                                    $message->attach(
                                        storage_path(
                                            "app/public/labels/" .
                                                basename($result["label_url"])
                                        )
                                    );
                                }
                            }
                        );
                    } catch (\Exception $e) {
                        Log::warning(
                            "Multiple SKU mail failed for order #{$order->order_number}: " .
                                $e->getMessage()
                        );
                    }
                    } else {
                      Log::info("Order #{$order->order_number} has same SKU items, skipping multiple SKU mail.");
                    }
                }
            } catch (\Exception $e) {
                Log::error(
                    "ShippingLabelService: createLabel error for order {$orderId}",
                    [
                        "message" => $e->getMessage(),
                        "trace" => $e->getTraceAsString(),
                        "order_id" => $orderId
                    ]
                );
                
                // CRITICAL: Ensure order is unlocked even on exception
                try {
                    $unlocked = Order::where('id', $orderId)->update(['queue' => 0]);
                    if ($unlocked) {
                        Log::info("Order {$orderId} unlocked after exception");
                    }
                } catch (\Exception $unlockException) {
                    Log::error("CRITICAL: Failed to unlock order {$orderId} after exception", [
                        "unlock_error" => $unlockException->getMessage(),
                        "trace" => $unlockException->getTraceAsString()
                    ]);
                }
                
                // Preserve original order_status - only mark label as failed
                // This ensures the order remains visible in awaiting shipment
                try {
                    $order = Order::find($orderId);
                    if ($order) {
                        $order->update([
                            "label_status" => "failed",
                            "printing_status" => 0,
                            // Keep original order_status - don't change it
                            // This is critical so orders don't disappear
                        ]);
                        Log::info("Order {$orderId} marked as failed but preserved order_status");
                    } else {
                        Log::warning("Order {$orderId} not found when trying to update status after exception");
                    }
                } catch (\Exception $updateException) {
                    Log::error("CRITICAL: Failed to update order {$orderId} status after exception", [
                        "update_error" => $updateException->getMessage(),
                        "trace" => $updateException->getTraceAsString()
                    ]);
                }

                // CRITICAL: Always add to labels array so order is tracked
                $labels[] = [
                    "order_id" => $orderId,
                    "success" => false,
                    "message" => "Exception: " . $e->getMessage(),
                    "error" => $e->getMessage(),
                    "provider" => "unknown",
                    "source" => "unknown"
                ];
                $failedCount++;
                
                // Continue to next order - don't break the loop
                // This ensures all orders are processed even if some fail
                continue;
            }
        }
        $successOrderIds = collect($labels)
            ->where("success", true)
            ->pluck("order_id")
            ->values();
        $failedOrderIds = collect($labels)
            ->where("success", false)
            ->pluck("order_id")
            ->values();
        
        // CRITICAL VALIDATION: Ensure all orders are accounted for
        $trackedOrderIds = collect($labels)->pluck("order_id")->values()->toArray();
        $missingOrderIds = array_diff($orderIds, $trackedOrderIds);
        
        if (!empty($missingOrderIds)) {
            Log::error("CRITICAL: Missing orders in labels array!", [
                "missing_order_ids" => $missingOrderIds,
                "total_requested" => count($orderIds),
                "total_tracked" => count($trackedOrderIds),
                "success_count" => $successCount,
                "failed_count" => $failedCount,
            ]);
            
            // Add missing orders to labels array as failed
            foreach ($missingOrderIds as $missingOrderId) {
                $labels[] = [
                    "order_id" => $missingOrderId,
                    "success" => false,
                    "provider" => "unknown",
                    "source" => "unknown",
                    "error" => "Order was not tracked during processing",
                    "message" => "Order processing completed but was not added to labels array",
                ];
                $failedCount++;
            }
            
            // Recalculate after adding missing orders
            $successOrderIds = collect($labels)
                ->where("success", true)
                ->pluck("order_id")
                ->values();
            $failedOrderIds = collect($labels)
                ->where("success", false)
                ->pluck("order_id")
                ->values();
        }
        
        // CRITICAL VALIDATION: Verify all successful orders have active shipments
        // This catches cases where order was updated but shipment creation failed silently
        // Note: Orders that already had shipments (skipped) are excluded from this check
        // because they were already verified to have shipments before being marked as success
        $ordersWithoutShipments = [];
        foreach ($successOrderIds as $successOrderId) {
            // Skip validation for orders that were skipped (already had shipments)
            // These are safe because we verified they had shipments before marking as success
            $labelEntry = collect($labels)->firstWhere('order_id', $successOrderId);
            if ($labelEntry && isset($labelEntry["message"]) && strpos($labelEntry["message"], "Active label already exists") !== false) {
                // This order already had a shipment, skip validation
                continue;
            }
            
            $hasActiveShipment = Shipment::where('order_id', $successOrderId)
                ->where('label_status', 'active')
                ->exists();
            
            if (!$hasActiveShipment) {
                $ordersWithoutShipments[] = $successOrderId;
                Log::error("CRITICAL: Order marked as success but has no active shipment!", [
                    "order_id" => $successOrderId,
                ]);
                
                // Mark order as failed but preserve order_status to keep it visible
                Order::where('id', $successOrderId)->update([
                    "label_status" => "failed",
                    "printing_status" => 0,
                    // Keep original order_status - don't change it
                    // "order_status" => "unshipped", // REMOVED - preserve original status
                ]);
                
                // Update the label entry to reflect failure
                foreach ($labels as &$labelEntry) {
                    if ($labelEntry["order_id"] == $successOrderId && isset($labelEntry["success"]) && $labelEntry["success"] === true) {
                        $labelEntry["success"] = false;
                        $labelEntry["error"] = "Shipment verification failed - no active shipment found";
                        $labelEntry["message"] = "Order was updated but shipment was not created";
                        break;
                    }
                }
                unset($labelEntry); // Break reference
                
                $successCount--;
                $failedCount++;
            }
        }
        
        // Recalculate after validation
        $successOrderIds = collect($labels)
            ->where("success", true)
            ->pluck("order_id")
            ->values();
        $failedOrderIds = collect($labels)
            ->where("success", false)
            ->pluck("order_id")
            ->values();
        
        if (!empty($ordersWithoutShipments)) {
            Log::error("CRITICAL: Found orders without shipments after processing", [
                "order_ids" => $ordersWithoutShipments,
                "total_fixed" => count($ordersWithoutShipments),
            ]);
        }
        
        $summary = [
            "total_processed" => count($orderIds),
            "success_count" => $successCount,
            "failed_count" => $failedCount,
            "success_order_ids" => $successOrderIds,
            "failed_order_ids" => $failedOrderIds,
        ];
        
        // Final validation log
        Log::info("Bulk label processing summary", [
            "total_requested" => count($orderIds),
            "total_tracked_in_labels" => count($labels),
            "success_count" => $successCount,
            "failed_count" => $failedCount,
            "summary" => $summary,
        ]);
        $mergedUrl = null;
        
        // CRITICAL FIX: Wrap history creation in try-catch to prevent silent failures
        try {
            $history = \App\Models\BulkShippingHistory::create([
                "order_ids" => $orderIds,
                "providers" =>'mixed',
                "merged_pdf_url" => $mergedUrl ?? null,
                "status" => $failedCount > 0 ? "partial" : "completed",
                "processed" => $summary["total_processed"],
                "success" => $summary["success_count"],
                "failed" => $summary["failed_count"],
                "success_order_ids" => $successOrderIds,
                "failed_order_ids" => $failedOrderIds,
                "user_id" => $userId
            ]);
            
            Log::info("BulkShippingHistory created successfully", [
                "history_id" => $history->id,
                "total_processed" => $summary["total_processed"],
                "success_count" => $summary["success_count"],
                "failed_count" => $summary["failed_count"],
            ]);
        } catch (\Exception $e) {
            // Log the error but don't fail the entire operation
            Log::error("CRITICAL: Failed to create BulkShippingHistory", [
                "error" => $e->getMessage(),
                "trace" => $e->getTraceAsString(),
                "order_ids" => $orderIds,
                "summary" => $summary,
                "user_id" => $userId,
            ]);
            // Re-throw to ensure this is visible
            throw new \Exception("Failed to save bulk shipping history: " . $e->getMessage(), 0, $e);
        }

        return [
            "labels" => $labels,
            "summary" => $summary,
        ];
    }
    /**
     * Merge all label PDFs for given orders
     * @param array $orderIds
     * @return string|null
     */
public function mergeLabelsPdf_v4(string $type, array $modes, string $filterDate): ?string
{
    ini_set("max_execution_time", 300);

    $labelDir = storage_path("app/public/labels");
    if (!file_exists($labelDir)) {
        mkdir($labelDir, 0755, true);
    }

    $mergedFileName = "merged_labels_" . time() . ".pdf";
    $mergedFilePath = $labelDir . "/" . $mergedFileName;

    $pdfFiles = [];
    $orderIds = []; // Collect order IDs for print count update

    // ✅ Include ShipHub PDFs if 'shiphub' is selected
    if (in_array('shiphub', $modes)) {
        $shipHubShipments = Shipment::whereDate('created_at', $filterDate)
            ->where("label_status", "active")
            ->where("void_status", "active")
            ->get();

        foreach ($shipHubShipments as $shipment) {
            if ($shipment->label_url) {
                $localPath = storage_path("app/public/labels/" . basename($shipment->label_url));
                if (file_exists($localPath)) {
                    $pdfFiles[] = $localPath;
                    // Collect order IDs for later update (only when printing)
                    if ($type === 'p' && $shipment->order_id) {
                        $orderIds[] = $shipment->order_id;
                    }
                }
            }
        }
    }

    // ✅ Include Manual PDFs if 'manual' is selected
    if (in_array('manual', $modes)) {
        $manualPdfs = \App\Models\Pdf::whereDate('upload_date', $filterDate)->get();

        foreach ($manualPdfs as $pdf) {
            if ($pdf->label_url) {
                $localPath = storage_path('app/public/' . basename($pdf->label_url));
                if (file_exists($localPath)) {
                    $pdfFiles[] = $localPath;
                }
            } elseif ($pdf->file_path) {
                $localPath = storage_path('app/public/' . basename($pdf->file_path));
                if (file_exists($localPath)) {
                    $pdfFiles[] = $localPath;
                }
            }
        }
    }

    if (count($pdfFiles) === 0) {
        \Log::warning("No valid PDFs found for merge on date: {$filterDate} with modes: " . implode(',', $modes));
        return null;
    }

        // ✅ Only one PDF → return original URL
        if (count($pdfFiles) === 1) {
            $singleFile = basename($pdfFiles[0]);
            $singleUrl = asset("storage/labels/" . $singleFile);
            \Log::info("🟢 Single PDF found — returning URL: " . $singleUrl);
            
            // Update print count only when printing (type='p'), set to 1 only if not already printed
            if ($type === 'p' && !empty($orderIds)) {
                \App\Models\Order::whereIn("id", array_unique($orderIds))
                    ->where(function($query) {
                        $query->whereNull('print_count')
                              ->orWhere('print_count', 0);
                    })
                    ->update([
                        "print_count" => 1,
                        "printing_status" => 2,
                    ]);
                // Update printing_status for all orders, even if already printed
                \App\Models\Order::whereIn("id", array_unique($orderIds))->update([
                    "printing_status" => 2,
                ]);
            }
            
            return $singleUrl;
        }

    // ✅ Merge multiple PDFs using Ghostscript
    $gsPath = '/usr/bin/gs';
    $inputFiles = implode(' ', array_map(fn($f) => escapeshellarg($f), $pdfFiles));
    $cmd = "{$gsPath} -dBATCH -dNOPAUSE -q -sDEVICE=pdfwrite -sOutputFile=" . escapeshellarg($mergedFilePath) . " {$inputFiles}";

    exec($cmd, $output, $returnCode);

    if ($returnCode === 0 && file_exists($mergedFilePath)) {
        $mergedUrl = asset("storage/labels/" . $mergedFileName);
        \Log::info("✅ Merged PDF created successfully: " . $mergedUrl);
        
        // Update print count only when printing (type='p'), set to 1 only if not already printed
        if ($type === 'p' && !empty($orderIds)) {
            \App\Models\Order::whereIn("id", array_unique($orderIds))
                ->where(function($query) {
                    $query->whereNull('print_count')
                          ->orWhere('print_count', 0);
                })
                ->update([
                    "print_count" => 1,
                    "printing_status" => 2,
                ]);
            // Update printing_status for all orders, even if already printed
            \App\Models\Order::whereIn("id", array_unique($orderIds))->update([
                "printing_status" => 2,
            ]);
        }
        
        return $mergedUrl;
    }

    \Log::error("❌ Ghostscript merge failed", [
        'cmd' => $cmd,
        'output' => $output,
        'return' => $returnCode,
    ]);

    return null;
}

// public function mergeLabelsPdf_v3(array $orderIds,$type): ?string
// {
//     ini_set("max_execution_time", 300);

//     $labelDir = storage_path("app/public/labels");
//     if (!file_exists($labelDir)) {
//         mkdir($labelDir, 0755, true);
//     }

//     $mergedFileName = "merged_labels_" . time() . ".pdf";
//     $mergedFilePath = $labelDir . "/" . $mergedFileName;

//     $pdfFiles = [];

//     foreach ($orderIds as $orderId) {
//         $shipment = Shipment::where("order_id", $orderId)
//             ->where("label_status", "active")
//             ->where("void_status", "active")
//             ->first();

//         if ($shipment && $shipment->label_url) {
//             $localPath = storage_path("app/public/labels/" . basename($shipment->label_url));
//             if (file_exists($localPath)) {
//                 $pdfFiles[] = $localPath;
//             }
//         }
//     }

//     if (count($pdfFiles) === 0) {
//         \Log::warning("No valid label PDFs found for merge.");
//         return null;
//     }

//     // ✅ If only one PDF → return the same file URL (no merging)
//     // if (count($pdfFiles) === 1) {
//     //     \App\Models\Order::whereIn("id", $orderIds)->update([
//     //         "print_count" => DB::raw("print_count + 1"),
//     //     ]);

//     //     $singleFile = basename($pdfFiles[0]);
//     //     $singleUrl = asset("storage/labels/" . $singleFile);

//     //     \Log::info("Only one PDF found — returning original: " . $singleUrl);
//     //     return $singleUrl;
//     // }
//     // ✅ If only one PDF → update print status + return the same file
//     if (count($pdfFiles) === 1) {
//         $shipment = Shipment::where("order_id", $orderIds[0])
//             ->where("label_status", "active")
//             ->where("void_status", "active")
//             ->first();

//         if ($shipment) {
//             \App\Models\Order::where("id", $shipment->order_id)->update([
//                 "print_count" => DB::raw("print_count + 1"),
//                 "printing_status" => $type === 'p' ? 2 : DB::raw("printing_status"),
//             ]);
//         }

//         $singleFile = basename($pdfFiles[0]);
//         $singleUrl = asset("storage/labels/" . $singleFile);

//         \Log::info("🟢 Single PDF found — updated order print info: " . $singleUrl);
//         return $singleUrl;
//     }


//     // ✅ Ghostscript path for Linux / FastPanel
//     $gsPath = '/usr/bin/gs';

//     // Build Ghostscript merge command
//     $inputFiles = implode(' ', array_map(fn($f) => escapeshellarg($f), $pdfFiles));
//     $cmd = "{$gsPath} -dBATCH -dNOPAUSE -q -sDEVICE=pdfwrite -sOutputFile=" . escapeshellarg($mergedFilePath) . " {$inputFiles}";

//     exec($cmd, $output, $returnCode);

//     if ($returnCode === 0 && file_exists($mergedFilePath)) {
//         \App\Models\Order::whereIn("id", $orderIds)->update([
//             "print_count" => DB::raw("print_count + 1"),
//         ]);
//         if($type=='p')
//         {
//               \App\Models\Order::whereIn("id", $orderIds)->update([
//                 "printing_status" => 2,
//                 "print_count" => DB::raw("print_count + 1"),
//             ]); 
//         }
       

//         $mergedUrl = asset("storage/labels/" . $mergedFileName);
//         \Log::info("✅ Merged PDF created successfully (text preserved): " . $mergedUrl);

//         return $mergedUrl;
//     }

//     \Log::error("❌ Ghostscript merge failed", [
//         'cmd' => $cmd,
//         'output' => $output,
//         'return' => $returnCode,
//     ]);

//     return null;
// }
// public function mergeLabelsPdf_v3(array $orderIds, string $type): ?string
// {
//     ini_set("max_execution_time", 300);

//     $labelDir = storage_path("app/public/labels");
//     if (!file_exists($labelDir)) {
//         mkdir($labelDir, 0755, true);
//     }

//     $mergedFileName = "merged_labels_" . time() . ".pdf";
//     $mergedFilePath = $labelDir . "/" . $mergedFileName;

//     $pdfFiles = [];

//     // Collect valid PDFs
//     foreach ($orderIds as $orderId) {
//         $shipment = Shipment::where("order_id", $orderId)
//             ->where("label_status", "active")
//             ->where("void_status", "active")
//             ->first();

//         if ($shipment && $shipment->label_url) {
//             $localPath = storage_path("app/public/labels/" . basename($shipment->label_url));
//             if (file_exists($localPath) && filesize($localPath) > 0) {
//                 $pdfFiles[] = $localPath;
//             }
//         }
//     }

//     if (count($pdfFiles) === 0) {
//         \Log::warning("No valid label PDFs found for merge.");
//         return null;
//     }

//     // Single PDF → update print info and return original
//     if (count($pdfFiles) === 1) {
//         $shipment = Shipment::where("order_id", $orderIds[0])->first();
//         if ($shipment) {
//             \App\Models\Order::where("id", $shipment->order_id)->update([
//                 "print_count" => DB::raw("print_count + 1"),
//                 "printing_status" => $type === 'p' ? 2 : DB::raw("printing_status"),
//             ]);
//         }

//         $singleFile = basename($pdfFiles[0]);
//         return asset("storage/labels/" . $singleFile);
//     }

//     // Merge PDFs using FPDI
//     $pdf = new Fpdi();

//     foreach ($pdfFiles as $file) {
//         $pageCount = $pdf->setSourceFile($file);
//         for ($pageNo = 1; $pageNo <= $pageCount; $pageNo++) {
//             $tpl = $pdf->importPage($pageNo);
//             $size = $pdf->getTemplateSize($tpl);

//             $pdf->AddPage($size['orientation'], [$size['width'], $size['height']]);
//             $pdf->useTemplate($tpl);
//         }
//     }

//     // Save merged PDF
//     $pdf->Output($mergedFilePath, 'F');

//     // Update orders print info
//     \App\Models\Order::whereIn("id", $orderIds)->update([
//         "print_count" => DB::raw("print_count + 1"),
//         "printing_status" => $type === 'p' ? 2 : DB::raw("printing_status"),
//     ]);

//     $mergedUrl = asset("storage/labels/" . $mergedFileName);
//     \Log::info("✅ Merged PDF created successfully using FPDI: " . $mergedUrl);

//     return $mergedUrl;
// }
public function mergeLabelsPdf_v3(array $orderIds, string $type): ?string
{
    try {
        ini_set("max_execution_time", 300);

        $labelDir = storage_path("app/public/labels");
        if (!file_exists($labelDir)) {
            if (!mkdir($labelDir, 0755, true)) {
                \Log::error("Failed to create labels directory: {$labelDir}");
                throw new \Exception("Failed to create labels directory");
            }
        }

        $mergedFileName = "merged_labels_" . time() . ".pdf";
        $mergedFilePath = $labelDir . "/" . $mergedFileName;

        $pdfFiles = [];
        $orderIdToPdfMap = []; // Track which order ID each PDF belongs to
        $missingOrders = []; // Track orders without valid labels with details
        $processedOrderIds = []; // Track successfully processed order IDs

        // Collect valid PDFs - ensure we process ALL order IDs
        // Get the latest active shipment for each order (in case of multiple shipments)
        $shipments = Shipment::whereIn("order_id", $orderIds)
            ->where("label_status", "active")
            ->where("void_status", "active")
            ->orderBy('created_at', 'desc')
            ->get()
            ->unique('order_id')
            ->keyBy('order_id'); // Key by order_id for easy lookup

        if ($shipments->isEmpty()) {
            \Log::warning("No active shipments found for order IDs: " . implode(', ', $orderIds));
            throw new \Exception("No active shipments found for the selected orders. Please ensure all orders have active labels.");
        }

        // Get order numbers for better error messages
        $orders = \App\Models\Order::whereIn('id', $orderIds)
            ->select('id', 'order_number')
            ->get()
            ->keyBy('id');

        // Validate ALL orders first before proceeding
        foreach ($orderIds as $orderId) {
            $order = $orders->get($orderId);
            $orderNumber = $order ? $order->order_number : "ID: {$orderId}";
            $shipment = $shipments->get($orderId);
            
            if (!$shipment) {
                $missingOrders[] = [
                    'order_id' => $orderId,
                    'order_number' => $orderNumber,
                    'reason' => 'No active shipment found'
                ];
                continue;
            }

            if (!$shipment->label_url) {
                $missingOrders[] = [
                    'order_id' => $orderId,
                    'order_number' => $orderNumber,
                    'reason' => 'Shipment has no label URL'
                ];
                continue;
            }

            // Handle both full URLs and relative paths
            $fileName = basename(parse_url($shipment->label_url, PHP_URL_PATH) ?: $shipment->label_url);
            $localPath = storage_path("app/public/labels/" . $fileName);
            
            if (!file_exists($localPath)) {
                $missingOrders[] = [
                    'order_id' => $orderId,
                    'order_number' => $orderNumber,
                    'reason' => 'Label PDF file not found on server'
                ];
                continue;
            }

            if (filesize($localPath) === 0) {
                $missingOrders[] = [
                    'order_id' => $orderId,
                    'order_number' => $orderNumber,
                    'reason' => 'Label PDF file is empty'
                ];
                continue;
            }

            // Valid PDF found - add it
            $pdfFiles[] = $localPath;
            $orderIdToPdfMap[$orderId] = $localPath;
            $processedOrderIds[] = $orderId;
        }

        // If ANY orders are missing, fail the entire operation
        if (count($missingOrders) > 0) {
            $missingCount = count($missingOrders);
            $totalCount = count($orderIds);
            $missingDetails = array_map(function($item) {
                return "Order #{$item['order_number']} ({$item['reason']})";
            }, $missingOrders);
            
            $errorMessage = "Cannot proceed with bulk print. {$missingCount} out of {$totalCount} order(s) have issues:\n\n" . 
                           implode("\n", $missingDetails) . 
                           "\n\nPlease ensure all selected orders have valid labels before attempting to print.";
            
            \Log::error("Bulk print failed - Missing orders: " . json_encode($missingOrders));
            throw new \Exception($errorMessage);
        }

        if (count($pdfFiles) === 0) {
            \Log::warning("No valid label PDFs found for merge. Order IDs: " . implode(', ', $orderIds));
            throw new \Exception("No valid label PDFs found. Please ensure labels are generated and files exist.");
        }

        // Single PDF → just return original
        if (count($pdfFiles) === 1) {
            $singleFile = basename($pdfFiles[0]);
            // Update print info only when printing (type='p'), set to 1 only if not already printed
            if ($type === 'p' && !empty($processedOrderIds)) {
                \App\Models\Order::whereIn("id", $processedOrderIds)
                    ->where(function($query) {
                        $query->whereNull('print_count')
                              ->orWhere('print_count', 0);
                    })
                    ->update([
                        "print_count" => 1,
                        "printing_status" => 2,
                    ]);
                // Update printing_status for successfully processed orders, even if already printed
                \App\Models\Order::whereIn("id", $processedOrderIds)->update([
                    "printing_status" => 2,
                ]);
            }

            \Log::info("✅ Single PDF returned - Order ID: " . implode(', ', $processedOrderIds));
            return asset("storage/labels/" . $singleFile);
        }

        // Merge PDFs using FPDI
        $pdf = new Fpdi();

        foreach ($pdfFiles as $file) {
            try {
                if (!file_exists($file) || filesize($file) === 0) {
                    \Log::warning("Skipping invalid PDF file: {$file}");
                    continue;
                }

                $pageCount = $pdf->setSourceFile($file);
                if ($pageCount === 0) {
                    \Log::warning("PDF file has no pages: {$file}");
                    continue;
                }

                for ($pageNo = 1; $pageNo <= $pageCount; $pageNo++) {
                    $tpl = $pdf->importPage($pageNo);
                    $size = $pdf->getTemplateSize($tpl);

                    if (!$size) {
                        \Log::warning("Failed to get template size for page {$pageNo} of {$file}");
                        continue;
                    }

                    $pdf->AddPage($size['orientation'], [$size['width'], $size['height']]);
                    $pdf->useTemplate($tpl);
                }
            } catch (\Exception $e) {
                \Log::error("Error processing PDF file {$file}: " . $e->getMessage());
                // Continue with other files
                continue;
            }
        }

        // Check if we have any pages added
        if ($pdf->PageNo() === 0) {
            \Log::error("No valid pages found in any PDF files");
            throw new \Exception("Failed to process PDF files. No valid pages found.");
        }

        // Save merged PDF
        if (!is_writable($labelDir)) {
            \Log::error("Labels directory is not writable: {$labelDir}");
            throw new \Exception("Labels directory is not writable");
        }

        $pdf->Output($mergedFilePath, 'F');

        if (!file_exists($mergedFilePath)) {
            \Log::error("Failed to create merged PDF file: {$mergedFilePath}");
            throw new \Exception("Failed to create merged PDF file");
        }

        // Update print info only when printing (type='p'), set to 1 only if not already printed
        // Only update for orders that were successfully included in the merge
        if ($type === 'p' && !empty($processedOrderIds)) {
            \App\Models\Order::whereIn("id", $processedOrderIds)
                ->where(function($query) {
                    $query->whereNull('print_count')
                          ->orWhere('print_count', 0);
                })
                ->update([
                    "print_count" => 1,
                    "printing_status" => 2,
                ]);
            // Update printing_status for all successfully processed orders, even if already printed
            \App\Models\Order::whereIn("id", $processedOrderIds)->update([
                "printing_status" => 2,
            ]);
        }

        $mergedUrl = asset("storage/labels/" . $mergedFileName);
        \Log::info("✅ Merged PDF created successfully using FPDI: " . $mergedUrl . " - Included all " . count($processedOrderIds) . " orders");

        return $mergedUrl;
    } catch (\Exception $e) {
        \Log::error("Error in mergeLabelsPdf_v3: " . $e->getMessage(), [
            'order_ids' => $orderIds,
            'type' => $type,
            'trace' => $e->getTraceAsString()
        ]);
        throw $e; // Re-throw to be caught by controller
    }
}

    public function sanitizeSenderName($name)
    {
        $cleanName = preg_replace("/[^a-zA-Z\s\-\.]/", "", $name);
        return $cleanName ?: "Sender";
    }
}
