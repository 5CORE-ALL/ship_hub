<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class EDeskService
{
    protected string $bearerToken;
    protected string $baseUrl;

    public function __construct()
    {
        $this->bearerToken = config('services.edesk.bearer_token');
        $this->baseUrl = config('services.edesk.base_url', 'https://api.edesk.com');
    }

    /**
     * Fetch customer details by Amazon order ID
     * 
     * @param string $amazonOrderId
     * @return array|null
     */
    public function getCustomerDetailsByOrderId(string $amazonOrderId): ?array
    {
        if (!$this->bearerToken) {
            Log::warning('eDesk bearer token not configured');
            return null;
        }

        try {
            // Try to find ticket/order by Amazon order ID
            // Common eDesk API patterns:
            // 1. Search tickets by order ID
            // 2. Get ticket details which includes customer info
            
            // First, try to search for tickets with the order ID
            $ticket = $this->searchTicketByOrderId($amazonOrderId);
            
            if ($ticket) {
                // Get full ticket details with customer information
                return $this->getTicketDetails($ticket['id'] ?? $ticket['ticket_id'] ?? null);
            }

            // Alternative: Try direct order lookup
            $order = $this->getOrderByOrderId($amazonOrderId);
            if ($order) {
                return $this->extractCustomerDetails($order);
            }

            Log::info("No eDesk ticket/order found for Amazon order ID: {$amazonOrderId}");
            return null;

        } catch (\Exception $e) {
            Log::error("eDesk API error for order {$amazonOrderId}: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Search for ticket by Amazon order ID
     * 
     * @param string $amazonOrderId
     * @return array|null
     */
    protected function searchTicketByOrderId(string $amazonOrderId): ?array
    {
        try {
            // Get endpoints from config, fallback to defaults
            $endpointPatterns = config('services.edesk.endpoints.ticket_search', [
                "/api/v1/tickets/search?order_id={order_id}",
                "/api/v1/tickets?order_number={order_id}",
                "/api/v2/tickets?filter[order_id]={order_id}",
                "/api/tickets?q={order_id}",
            ]);
            
            // Replace {order_id} placeholder with actual order ID
            $endpoints = array_map(function($pattern) use ($amazonOrderId) {
                return str_replace('{order_id}', $amazonOrderId, $pattern);
            }, $endpointPatterns);

            foreach ($endpoints as $endpoint) {
                try {
                    $fullUrl = $this->baseUrl . $endpoint;
                    $response = Http::timeout(10)->withHeaders([
                        'Authorization' => 'Bearer ' . $this->bearerToken,
                        'Accept' => 'application/json',
                    ])->get($fullUrl);

                    $statusCode = $response->status();
                    
                    if ($response->successful()) {
                        $data = $response->json();
                        
                        // Handle different response structures
                        if (isset($data['tickets']) && !empty($data['tickets'])) {
                            Log::info("eDesk ticket found via endpoint: {$endpoint}");
                            return $data['tickets'][0];
                        }
                        if (isset($data['data']) && !empty($data['data'])) {
                            Log::info("eDesk ticket found via endpoint: {$endpoint}");
                            return is_array($data['data']) && isset($data['data'][0]) ? $data['data'][0] : $data['data'];
                        }
                        if (isset($data['ticket'])) {
                            Log::info("eDesk ticket found via endpoint: {$endpoint}");
                            return $data['ticket'];
                        }
                        if (isset($data['id'])) {
                            Log::info("eDesk ticket found via endpoint: {$endpoint}");
                            return $data;
                        }
                    } else {
                        // Log failed responses with details
                        $errorBody = $response->body();
                        Log::debug("eDesk endpoint failed: {$endpoint}", [
                            'status' => $statusCode,
                            'error' => substr($errorBody, 0, 500), // Limit error message length
                        ]);
                    }
                } catch (\Exception $e) {
                    // Continue to next endpoint on error
                    Log::debug("eDesk endpoint exception: {$endpoint}", [
                        'error' => $e->getMessage(),
                        'trace' => substr($e->getTraceAsString(), 0, 200),
                    ]);
                    continue;
                }
            }

            return null;
        } catch (\Exception $e) {
            Log::warning("eDesk ticket search failed: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Get ticket details with customer information
     * 
     * @param int|string $ticketId
     * @return array|null
     */
    protected function getTicketDetails($ticketId): ?array
    {
        if (!$ticketId) {
            return null;
        }

        try {
            // Get endpoints from config, fallback to defaults
            $endpointPatterns = config('services.edesk.endpoints.ticket_details', [
                "/api/v1/tickets/{ticket_id}",
                "/api/v2/tickets/{ticket_id}",
                "/api/tickets/{ticket_id}",
            ]);
            
            // Replace {ticket_id} placeholder with actual ticket ID
            $endpoints = array_map(function($pattern) use ($ticketId) {
                return str_replace('{ticket_id}', $ticketId, $pattern);
            }, $endpointPatterns);

            foreach ($endpoints as $endpoint) {
                try {
                    $fullUrl = $this->baseUrl . $endpoint;
                    $response = Http::timeout(10)->withHeaders([
                        'Authorization' => 'Bearer ' . $this->bearerToken,
                        'Accept' => 'application/json',
                    ])->get($fullUrl);

                    $statusCode = $response->status();
                    
                    if ($response->successful()) {
                        $data = $response->json();
                        Log::info("eDesk ticket details fetched via endpoint: {$endpoint}");
                        return $this->extractCustomerDetails($data);
                    } else {
                        // Log failed responses
                        $errorBody = $response->body();
                        Log::debug("eDesk ticket details endpoint failed: {$endpoint}", [
                            'status' => $statusCode,
                            'error' => substr($errorBody, 0, 500),
                        ]);
                    }
                } catch (\Exception $e) {
                    // Continue to next endpoint on error
                    Log::debug("eDesk ticket details endpoint exception: {$endpoint}", [
                        'error' => $e->getMessage(),
                    ]);
                    continue;
                }
            }

            return null;
        } catch (\Exception $e) {
            Log::warning("eDesk ticket details fetch failed: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Get order by order ID
     * 
     * @param string $amazonOrderId
     * @return array|null
     */
    protected function getOrderByOrderId(string $amazonOrderId): ?array
    {
        try {
            // Get endpoints from config, fallback to defaults
            $endpointPatterns = config('services.edesk.endpoints.order_lookup', [
                "/api/v1/orders/{order_id}",
                "/api/v2/orders/{order_id}",
                "/api/orders/{order_id}",
            ]);
            
            // Replace {order_id} placeholder with actual order ID
            $endpoints = array_map(function($pattern) use ($amazonOrderId) {
                return str_replace('{order_id}', $amazonOrderId, $pattern);
            }, $endpointPatterns);

            foreach ($endpoints as $endpoint) {
                try {
                    $fullUrl = $this->baseUrl . $endpoint;
                    $response = Http::timeout(10)->withHeaders([
                        'Authorization' => 'Bearer ' . $this->bearerToken,
                        'Accept' => 'application/json',
                    ])->get($fullUrl);

                    $statusCode = $response->status();
                    
                    if ($response->successful()) {
                        $data = $response->json();
                        Log::info("eDesk order found via endpoint: {$endpoint}");
                        return $data['order'] ?? $data['data'] ?? $data;
                    } else {
                        // Log failed responses
                        $errorBody = $response->body();
                        Log::debug("eDesk order endpoint failed: {$endpoint}", [
                            'status' => $statusCode,
                            'error' => substr($errorBody, 0, 500),
                        ]);
                    }
                } catch (\Exception $e) {
                    // Continue to next endpoint on error
                    Log::debug("eDesk order endpoint exception: {$endpoint}", [
                        'error' => $e->getMessage(),
                    ]);
                    continue;
                }
            }

            return null;
        } catch (\Exception $e) {
            Log::warning("eDesk order fetch failed: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Extract customer details from eDesk response
     * 
     * @param array $data
     * @return array
     */
    protected function extractCustomerDetails(array $data): array
    {
        $customer = [];
        
        // Ensure data is an array
        if (!is_array($data)) {
            return $customer;
        }
        
        // Try different possible structures for customer data
        $customerData = $data['customer'] ?? $data['contact'] ?? $data['buyer'] ?? $data;
        $shippingAddress = $data['shipping_address'] ?? $data['address'] ?? $data['delivery_address'] ?? [];
        
        // Ensure customerData and shippingAddress are arrays
        if (!is_array($customerData)) {
            $customerData = [];
        }
        if (!is_array($shippingAddress) && isset($customerData['address']) && is_array($customerData['address'])) {
            $shippingAddress = $customerData['address'];
        }
        if (!is_array($shippingAddress)) {
            $shippingAddress = [];
        }

        // Extract customer name
        $customer['name'] = $customerData['name'] 
            ?? $customerData['full_name'] 
            ?? (!empty($customerData['first_name']) || !empty($customerData['last_name']) 
                ? trim(($customerData['first_name'] ?? '') . ' ' . ($customerData['last_name'] ?? '')) 
                : null)
            ?? $data['recipient_name']
            ?? null;

        // Extract email
        $customer['email'] = $customerData['email'] 
            ?? $customerData['contact_email']
            ?? $data['customer_email']
            ?? null;

        // Extract phone
        $customer['phone'] = $customerData['phone'] 
            ?? $customerData['phone_number']
            ?? $customerData['mobile']
            ?? $shippingAddress['phone']
            ?? null;

        // Extract address
        $customer['address1'] = $shippingAddress['address_line1'] 
            ?? $shippingAddress['address1']
            ?? $shippingAddress['street']
            ?? $shippingAddress['line1']
            ?? null;

        $customer['address2'] = $shippingAddress['address_line2'] 
            ?? $shippingAddress['address2']
            ?? $shippingAddress['line2']
            ?? null;

        $customer['city'] = $shippingAddress['city'] ?? null;
        $customer['state'] = $shippingAddress['state'] 
            ?? $shippingAddress['state_or_region']
            ?? $shippingAddress['province']
            ?? null;

        $customer['postal_code'] = $shippingAddress['postal_code'] 
            ?? $shippingAddress['zip']
            ?? $shippingAddress['zip_code']
            ?? null;

        $customer['country'] = $shippingAddress['country'] 
            ?? $shippingAddress['country_code']
            ?? null;

        // Clean up empty values
        return array_filter($customer, function($value) {
            return $value !== null && $value !== '';
        });
    }
}
