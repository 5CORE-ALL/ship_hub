<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Sale;
use App\Models\Store;
use App\Models\order;
use App\Models\OrderItem;
use App\Models\Integration;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Log;


class AdminOrderController extends Controller
{
    /**
     * Display a listing of orders.
     */
    public function index()
    {
        $orders = [];
        return view('admin.orders.index', compact('orders'));
    }

    /**
     * Show the form for creating a new order.
     */
    public function create()
    {
        $stores = Store::all();
        return view('admin.orders.create', compact('stores'));
    }

    /**
     * Store a newly created order in storage.
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'store_id' => 'required|exists:stores,id',
            'order_id' => 'required|unique:sales,order_id',
            'amount' => 'required|numeric',
            'status' => 'required|in:pending,shipped,paid,awaiting_shipment,on_hold,cancelled,alert',
        ]);

        if ($validator->fails()) {
            return redirect()->back()->withErrors($validator)->withInput();
        }

        Sale::create([
            'store_id' => $request->store_id,
            'order_id' => $request->order_id,
            'amount' => $request->amount,
            'status' => $request->status,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return redirect()->route('admin.orders.index')->with('success', 'Order created successfully');
    }

    /**
     * Fetch orders from Amazon API and store them.
     */
public function fetchOrders()
{

    // test
    $clientId = env('EBAY_3_APP_ID');
    $redirectUri = urlencode('https://5coremanagement.com/login');
    $scope = urlencode(implode(' ', [
        'https://api.ebay.com/oauth/api_scope',
        'https://api.ebay.com/oauth/api_scope/sell.inventory',
        'https://api.ebay.com/oauth/api_scope/sell.fulfillment',
        // add other scopes you need
    ]));

    $url = "https://auth.ebay.com/oauth2/authorize?client_id={$clientId}&response_type=code&redirect_uri={$redirectUri}&scope={$scope}";

    return redirect($url);
    // test
    $integration = Integration::where('store_id', 1)->first();
    if (!$integration || $integration->expires_at->lt(now())) {
        $response = Http::asForm()->post('https://api.amazon.com/auth/o2/token', [
            'grant_type'    => 'refresh_token',
            'refresh_token' => $integration->refresh_token ?? null,
            'client_id'     => env('AMAZON_CLIENT_ID'),
            'client_secret' => env('AMAZON_CLIENT_SECRET'),
        ]);

        if ($response->successful()) {
            $data = $response->json();
            $integration->update([
                'access_token'  => $data['access_token'],
                'refresh_token' => $data['refresh_token'] ?? $integration->refresh_token,
                'expires_at'    => now()->addSeconds($data['expires_in']),
            ]);
        } else {
            Log::error('Amazon token refresh failed', [
                'response_status' => $response->status(),
                'response_body'   => $response->body(),
            ]);
            return response()->json([
                'success' => false,
                'message' => 'Token refresh failed'
            ], 500);
        }
    }

    $endpoint     = env('AMAZON_BASE_URL', 'https://sellingpartnerapi-na.amazon.com') . '/orders/v0/orders';
    $createdAfter = now()->startOfDay()->toISOString();

    $response = Http::withHeaders([
        'Authorization'      => 'Bearer ' . $integration->access_token,
        'x-amz-access-token' => $integration->access_token,
    ])->get($endpoint, [
        'MarketplaceIds' => 'ATVPDKIKX0DER',
        'CreatedAfter'   => $createdAfter
    ]);

    if ($response->successful()) {
        $orders = $response->json()['payload']['Orders'] ?? [];

        foreach ($orders as $order) {
            $orderId = $order['AmazonOrderId'];

            // Fetch order items for each order
            $itemsEndpoint = env('AMAZON_BASE_URL', 'https://sellingpartnerapi-na.amazon.com') . "/orders/v0/orders/{$orderId}/orderItems";
           $itemsResponse = Http::withHeaders([
                'Authorization'      => 'Bearer ' . $integration->access_token,
                'x-amz-access-token' => $integration->access_token,
            ])->get($itemsEndpoint);

            $items = [];
            if ($itemsResponse->successful()) {
                $items = $itemsResponse->json()['payload']['OrderItems'] ?? [];
            } else {
                Log::warning("Failed to fetch items for Order {$orderId}", [
                    'status' => $itemsResponse->status(),
                    'body'   => $itemsResponse->body()
                ]);
            }
            $orderRecord = Order::updateOrCreate(
                ['order_number' => $orderId],
                [
                    'marketplace'        => 'Amazon',
                    'store_id'           => $integration->store_id,
                    'order_date'         => $order['PurchaseDate'] ?? null,
                    'order_age'          => isset($order['PurchaseDate']) ? now()->diffInDays(Carbon::parse($order['PurchaseDate'])) : null,
                    'quantity'           => $order['NumberOfItemsUnshipped'] ?? 1,
                    'order_total'        => $order['OrderTotal']['Amount'] ?? 0.00,
                    'recipient_name'     => $order['ShippingAddress']['Name'] ?? null,
                    'recipient_email'    => $order['BuyerEmail'] ?? null,
                    'recipient_phone'    => $order['ShippingAddress']['Phone'] ?? null,
                    'ship_address1'      => $order['ShippingAddress']['AddressLine1'] ?? null,
                    'ship_address2'      => $order['ShippingAddress']['AddressLine2'] ?? null,
                    'ship_city'          => $order['ShippingAddress']['City'] ?? null,
                    'ship_state'         => $order['ShippingAddress']['StateOrRegion'] ?? null,
                    'ship_postal_code'   => $order['ShippingAddress']['PostalCode'] ?? null,
                    'ship_country'       => $order['ShippingAddress']['CountryCode'] ?? null,
                    'shipper_name'     => $order['DefaultShipFromLocationAddress']['Name'] ?? null,
                    'shipper_street'   => $order['DefaultShipFromLocationAddress']['AddressLine1'] ?? null,
                    'shipper_city'     => $order['DefaultShipFromLocationAddress']['City'] ?? null,
                    'shipper_state'    => $order['DefaultShipFromLocationAddress']['StateOrRegion'] ?? null,
                    'shipper_postal'   => $order['DefaultShipFromLocationAddress']['PostalCode'] ?? null,
                    'shipper_country'  => $order['DefaultShipFromLocationAddress']['CountryCode'] ?? null,
                    'order_status'       => $order['OrderStatus'] ?? null,
                    'external_order_id'  => $orderId,
                    'raw_data'           => $order,
                    'raw_items'          => $items,
                ]
            );
            foreach ($items as $item) {
                OrderItem::updateOrCreate(
                    [
                        'order_id' => $orderRecord->id,
                        'sku'      => $item['SellerSKU'] ?? null,
                    ],
                    [
                        'asin'          => $item['ASIN'] ?? null,
                        'order_number'          => $item['AmazonOrderId'] ?? null,
                        'product_name'         => $item['Title'] ?? null,
                        'quantity_ordered'      => $item['QuantityOrdered'] ?? 0,
                        'price'         => $item['ItemPrice']['Amount'] ?? 0.00,
                        'currency'      => $item['ItemPrice']['CurrencyCode'] ?? null,
                        'raw_data'      => $item,
                    ]
                );
                   Order::where('id', $orderRecord->id)
                ->update(['quantity' => $item['QuantityOrdered'] ?? 0]);
            }
        }

        return response()->json([
            'success'      => true,
            'message'      => 'Orders and items fetched and stored',
            'orders_count' => count($orders),
        ]);
    }

    Log::error('Failed to fetch Amazon orders', [
        'response_status' => $response->status(),
        'response_body'   => $response->body(),
    ]);

    return response()->json([
        'success' => false,
        'message' => 'Failed to fetch orders',
    ], 500);
}
// public function fetchOrders()
// {
//     $integration = Integration::where('store_id', 1)->first();

//     // Refresh token if expired
//     if (!$integration || $integration->expires_at->lt(now())) {
//         $response = Http::asForm()->post('https://api.amazon.com/auth/o2/token', [
//             'grant_type'    => 'refresh_token',
//             'refresh_token' => $integration->refresh_token ?? null,
//             'client_id'     => env('AMAZON_CLIENT_ID'),
//             'client_secret' => env('AMAZON_CLIENT_SECRET'),
//         ]);

//         if ($response->successful()) {
//             $data = $response->json();
//             $integration->update([
//                 'access_token'  => $data['access_token'],
//                 'refresh_token' => $data['refresh_token'] ?? $integration->refresh_token,
//                 'expires_at'    => now()->addSeconds($data['expires_in']),
//             ]);
//         } else {
//             Log::error('Amazon token refresh failed', [
//                 'response_status' => $response->status(),
//                 'response_body'   => $response->body(),
//             ]);
//             return response()->json([
//                 'success' => false,
//                 'message' => 'Token refresh failed'
//             ], 500);
//         }
//     }

//     $endpoint     = 'https://sellingpartnerapi-na.amazon.com/orders/v0/orders';
//     $createdAfter = now()->subDays(7)->toISOString();

//     $response = Http::withHeaders([
//         'Authorization'      => 'Bearer ' . $integration->access_token,
//         'x-amz-access-token' => $integration->access_token,
//     ])->get($endpoint, [
//         'MarketplaceIds' => 'ATVPDKIKX0DER',
//         'CreatedAfter'   => $createdAfter
//     ]);

//     if ($response->successful()) {
//         $orders = $response->json()['payload']['Orders'] ?? [];

//         foreach ($orders as $order) {
//             Order::updateOrCreate(
//                 ['order_number' => $order['AmazonOrderId']],
//                 [
//                     'marketplace'  => 'Amazon',
//                     'store_id'  => $integration->store_id,
//                     'order_date'   => $order['PurchaseDate'] ?? null,
//                     'order_age'    => isset($order['PurchaseDate']) ? now()->diffInDays(Carbon::parse($order['PurchaseDate'])) : null,
//                     'notes'        => null,
//                     'is_gift'      => $order['IsGift'] ?? false,

//                     // Item details (Amazon requires separate call to get items)
//                     'item_sku'     => null,
//                     'item_name'    => null,
//                     'batch'        => null,
//                     'quantity'     => $order['NumberOfItemsUnshipped'] ?? 1,
//                     'order_total'  => $order['OrderTotal']['Amount'] ?? 0.00,

//                     // Recipient details
//                     'recipient_name'    => $order['ShippingAddress']['Name'] ?? null,
//                     'recipient_company' => null,
//                     'recipient_email'   => $order['BuyerEmail'] ?? null,
//                     'recipient_phone'   => $order['ShippingAddress']['Phone'] ?? null,
//                     'ship_address1'     => $order['ShippingAddress']['AddressLine1'] ?? null,
//                     'ship_address2'     => $order['ShippingAddress']['AddressLine2'] ?? null,
//                     'ship_city'         => $order['ShippingAddress']['City'] ?? null,
//                     'ship_state'        => $order['ShippingAddress']['StateOrRegion'] ?? null,
//                     'ship_postal_code'  => $order['ShippingAddress']['PostalCode'] ?? null,
//                     'ship_country'      => $order['ShippingAddress']['CountryCode'] ?? null,

//                     // Shipping / label placeholders
//                     'shipping_carrier'  => null,
//                     'shipping_service'  => null,
//                     'shipping_cost'     => null,
//                     'tracking_number'   => null,
//                     'ship_date'         => null,
//                     'label_status'      => null,

//                     // Status & integration info
//                     'order_status'      => $order['OrderStatus'] ?? null,
//                     'payment_status'    => null,
//                     'fulfillment_status'=> null,
//                     'external_order_id' => $order['AmazonOrderId'] ?? null,
//                     'raw_data'          => $order,
//                 ]
//             );
//         }

//         return response()->json([
//             'success'      => true,
//             'message'      => 'Orders fetched and stored',
//             'orders_count' => count($orders),
//         ]);
//     }

//     Log::error('Failed to fetch Amazon orders', [
//         'response_status' => $response->status(),
//         'response_body'   => $response->body(),
//     ]);

//     return response()->json([
//         'success' => false,
//         'message' => 'Failed to fetch orders',
//     ], 500);
// }


    // public function fetchOrders()
    // {
    //     $integration = Integration::where('store_id', 1)->first();
    //     if (!$integration || $integration->expires_at->lt(now())) {
    //         $response = Http::asForm()->post('https://api.amazon.com/auth/o2/token', [
    //             'grant_type' => 'refresh_token',
    //             'refresh_token' => $integration->refresh_token,
    //             'client_id' => env('AMAZON_CLIENT_ID'),
    //             'client_secret' => env('AMAZON_CLIENT_SECRET'),
    //         ]);

    //         if ($response->successful()) {
    //             $data = $response->json();
    //             $integration->update([
    //                 'access_token' => $data['access_token'],
    //                 'refresh_token' => $data['refresh_token'] ?? $integration->refresh_token,
    //                 'expires_at' => Carbon::now()->addSeconds($data['expires_in']),
    //                 'updated_at' => now(),
    //             ]);
    //         } else {
    //             return redirect()->back()->with('error', 'Token refresh failed');
    //         }
    //     }

    //     $endpoint = 'https://sellingpartnerapi-na.amazon.com/orders/v0/orders';
    //     $response = Http::withHeaders([
    //         'Authorization' => 'Bearer ' . $integration->access_token,
    //         'x-amz-access-token' => $integration->access_token,
    //     ])->get($endpoint, [
    //         'MarketplaceIds' => 'ATVPDKIKX0DER', 
    //     ]);

    //     if ($response->successful()) {
    //         $orders = $response->json()['payload']['Orders'] ?? [];
    //         foreach ($orders as $order) {
    //             Sale::updateOrCreate(
    //                 ['order_id' => $order['AmazonOrderId']],
    //                 [
    //                     'store_id' => $integration->store_id,
    //                     'amount' => $order['OrderTotal']['Amount'] ?? 0.00,
    //                     'status' => $order['OrderStatus'],
    //                     'created_at' => now(),
    //                     'updated_at' => now(),
    //                 ]
    //             );
    //         }
    //         return redirect()->route('admin.orders.index')->with('success', 'Orders fetched and stored');
    //     }

    //     return redirect()->back()->with('error', 'Failed to fetch orders');
    // }
}