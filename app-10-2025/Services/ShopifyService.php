<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ShopifyService
{
    protected string $shopUrl;
    protected string $adminAccessToken;
    protected string $storefrontToken;
    protected string $version;

    public function __construct()
    {
        $this->shopUrl = env('SHOPIFY_DOMAIN', '5-core.myshopify.com');
        $this->adminAccessToken = env('SHOPIFY_TOKEN', '[REMOVED_SHOPIFY_TOKEN]');
        $this->storefrontToken = env('SHOPIFY_STOREFRONT_TOKEN'); // Storefront token needed for checkouts
        $this->version = env('SHOPIFY_API_VERSION', '2025-07');
    }

    /**
     * Fetch all products using Admin GraphQL with automatic pagination
     */
    public function getAllProducts($batchSize = 50)
    {
        $allProducts = [];
        $after = null;

        do {
            $productsData = $this->getProducts($batchSize, $after);
            if (!empty($productsData['edges'])) {
                $allProducts = array_merge($allProducts, $productsData['edges']);
            }
            $after = $productsData['pageInfo']['hasNextPage'] ?? false ? $productsData['pageInfo']['endCursor'] : null;
        } while ($after);

        return $allProducts;
    }

    /**
     * Fetch products batch (paginated) using Admin GraphQL
     */
    public function getProducts($first = 10, $after = null)
    {
        $query = <<<GQL
        query getProducts(\$first: Int!, \$after: String) {
            products(first: \$first, after: \$after) {
                edges {
                    cursor
                    node {
                        id
                        title
                        handle
                        createdAt
                        updatedAt
                        variants(first: 10) {
                            edges {
                                node {
                                    id
                                    title
                                    sku
                                    price
                                }
                            }
                        }
                    }
                }
                pageInfo {
                    hasNextPage
                    endCursor
                }
            }
        }
        GQL;

        $variables = ['first' => $first, 'after' => $after];

        return $this->runAdminGraphQL($query, $variables)['products'] ?? [];
    }

    /**
     * Fetch a single product by numeric ID
     */
    public function getProductById($id)
    {
        $gid = "gid://shopify/Product/{$id}";

        $query = <<<GQL
        query getProduct(\$id: ID!) {
            product(id: \$id) {
                id
                title
                handle
                createdAt
                updatedAt
                variants(first: 10) {
                    edges {
                        node {
                            id
                            title
                            sku
                            price
                        }
                    }
                }
            }
        }
        GQL;

        return $this->runAdminGraphQL($query, ['id' => $gid])['product'] ?? null;
    }

    /**
     * Create a checkout using Storefront GraphQL
     * Hardcoded version for testing
     */
    public function createCheckoutHardcoded()
    {
        // Replace with a real variant GraphQL ID
        $variantId = "gid://shopify/ProductVariant/123456789";

        $query = <<<GQL
        mutation {
            checkoutCreate(input: {
                lineItems: [
                    { variantId: "{$variantId}", quantity: 1 }
                ]
            }) {
                checkout {
                    id
                    webUrl
                }
                checkoutUserErrors {
                    code
                    field
                    message
                }
            }
        }
        GQL;

        return $this->runStorefrontGraphQL($query);
    }

    /**
     * Create a checkout dynamically using Storefront GraphQL
     */
    public function createCheckout(array $lineItems = [])
    {
        $formattedLineItems = [];
        foreach ($lineItems as $item) {
            $variantId = strpos($item['variantId'], 'gid://') === 0
                ? $item['variantId']
                : "gid://shopify/ProductVariant/{$item['variantId']}";

            $formattedLineItems[] = [
                'variantId' => $variantId,
                'quantity' => $item['quantity'],
            ];
        }

        $query = <<<GQL
        mutation createCheckout(\$lineItems: [CheckoutLineItemInput!]!) {
            checkoutCreate(input: {lineItems: \$lineItems}) {
                checkout {
                    id
                    webUrl
                }
                checkoutUserErrors {
                    code
                    field
                    message
                }
            }
        }
        GQL;

        $variables = ['lineItems' => $formattedLineItems];

        $result = $this->runStorefrontGraphQL($query, $variables);

        if (!empty($result['checkoutCreate']['checkoutUserErrors'])) {
            Log::error('Shopify Checkout User Errors', $result['checkoutCreate']['checkoutUserErrors']);
        }

        return $result['checkoutCreate'] ?? [];
    }

    /**
     * Run Admin GraphQL queries
     */
    protected function runAdminGraphQL(string $query, array $variables = [])
    {
        return $this->runGraphQL($query, $variables, $this->adminAccessToken, 'admin');
    }

    /**
     * Run Storefront GraphQL queries
     */
    protected function runStorefrontGraphQL(string $query, array $variables = [])
    {
        if (!$this->storefrontToken) {
            Log::error('Shopify Storefront token is missing in .env');
            return [];
        }
        return $this->runGraphQL($query, $variables, $this->storefrontToken, 'storefront');
    }

    /**
     * Generic GraphQL runner
     */
    protected function runGraphQL(string $query, array $variables, string $token, string $type = 'admin')
    {
        try {
            $url = $type === 'admin'
                ? "https://{$this->shopUrl}/admin/api/{$this->version}/graphql.json"
                : "https://{$this->shopUrl}/api/{$this->version}/graphql.json";

            $headerName = $type === 'admin' ? 'X-Shopify-Access-Token' : 'X-Shopify-Storefront-Access-Token';

            $response = Http::withHeaders([
                $headerName => $token,
                'Content-Type' => 'application/json',
            ])->post($url, [
                'query' => $query,
                'variables' => $variables,
            ]);

            $json = $response->json();

            if (!$response->successful() || isset($json['errors'])) {
                Log::error("Shopify {$type} GraphQL error", [
                    'status' => $response->status(),
                    'body' => $response->body(),
                    'errors' => $json['errors'] ?? null
                ]);
            }

            return $json['data'] ?? [];
        } catch (\Exception $e) {
            Log::error("Shopify {$type} GraphQL Exception: " . $e->getMessage());
            return [];
        }
    }
}
