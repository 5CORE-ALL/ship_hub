<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Services\AliExpressAuthService;

class AliExpressOrderController extends Controller
{
    protected $aliService;

    public function __construct()
    {
        $this->aliService = new AliExpressAuthService();
    }
    public function getAllOrders(Request $request)
    {
        $request->validate([
            'days' => 'nullable|integer|min:1|max:60', 
        ]);

        $days = $request->days ?? 2; 


        $ordersResponse = $this->aliService->getOrders($days);

        if (isset($ordersResponse['error'])) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch orders',
                'error' => $ordersResponse['error']
            ], 400);
        }

        $ordersList = $ordersResponse['aliexpress_trade_seller_orderlist_get_response']['result']['target_list']['aeop_order_item_dto'] ?? [];

        if (empty($ordersList)) {
            return response()->json([
                'success' => false,
                'message' => 'No orders found in the last ' . $days . ' day(s)'
            ], 404);
        }
        return response()->json([
            'success' => true,
            'orders' => $ordersList
        ]);
    }
    public function getProductDetail(Request $request)
    {
        $request->validate([
            'product_id' => 'required|string',
        ]);

        $productId = $request->input('product_id');

        $productData = $this->aliService->getProductDetail($productId);

        if (isset($productData['error'])) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch product details',
                'error' => $productData['error'],
            ], 400);
        }

        return response()->json([
            'success' => true,
            'product' => $productData,
        ]);
    }
    public function getProductList(Request $request)
    {
        $request->validate([
            'page' => 'nullable|integer|min:1',
            'page_size' => 'nullable|integer|min:1|max:100',
        ]);

        $page = $request->query('page', 1);
        $pageSize = $request->query('page_size', 20);
        $productsResponse = $this->aliService->getProductList($page, $pageSize);

        if (isset($productsResponse['error'])) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch products',
                'error' => $productsResponse['error']
            ], 400);
        }

         $productList = $productsResponse['aliexpress_solution_product_list_get_response']['result']['aeop_a_e_product_display_d_t_o_list']['item_display_dto'] ?? [];

        if (empty($productList)) {
            return response()->json([
                'success' => false,
                'message' => 'No products found',
            ], 404);
        }

        return response()->json([
            'success' => true,
            'products' => $productList
        ]);
    }
}
