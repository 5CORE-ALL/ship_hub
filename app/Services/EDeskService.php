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
            // Try different possible endpoints
            $endpoints = [
                "/api/v1/tickets/search?order_id={$amazonOrderId}",
                "/api/v1/tickets?order_number={$amazonOrderId}",
                "/api/v2/tickets?filter[order_id]={$amazonOrderId}",
                "/api/tickets?q={$amazonOrderId}",
            ];

            foreach ($endpoints as $endpoint) {
                $response = Http::withHeaders([
                    'Authorization' => 'Bearer ' . $this->bearerToken,
                    'Accept' => 'application/json',
                ])->get($this->baseUrl . $endpoint);

                if ($response->successful()) {
                    $data = $response->json();
                    
                    // Handle different response structures
                    if (isset($data['tickets']) && !empty($data['tickets'])) {
                        return $data['tickets'][0];
                    }
                    if (isset($data['data']) && !empty($data['data'])) {
                        return is_array($data['data']) && isset($data['data'][0]) ? $data['data'][0] : $data['data'];
                    }
                    if (isset($data['ticket'])) {
                        return $data['ticket'];
                    }
                    if (isset($data['id'])) {
                        return $data;
                    }
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
            $endpoints = [
                "/api/v1/tickets/{$ticketId}",
                "/api/v2/tickets/{$ticketId}",
                "/api/tickets/{$ticketId}",
            ];

            foreach ($endpoints as $endpoint) {
                $response = Http::withHeaders([
                    'Authorization' => 'Bearer ' . $this->bearerToken,
                    'Accept' => 'application/json',
                ])->get($this->baseUrl . $endpoint);

                if ($response->successful()) {
                    $data = $response->json();
                    return $this->extractCustomerDetails($data);
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
            $endpoints = [
                "/api/v1/orders/{$amazonOrderId}",
                "/api/v2/orders/{$amazonOrderId}",
                "/api/orders/{$amazonOrderId}",
            ];

            foreach ($endpoints as $endpoint) {
                $response = Http::withHeaders([
                    'Authorization' => 'Bearer ' . $this->bearerToken,
                    'Accept' => 'application/json',
                ])->get($this->baseUrl . $endpoint);

                if ($response->successful()) {
                    $data = $response->json();
                    return $data['order'] ?? $data['data'] ?? $data;
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
        
        // Try different possible structures for customer data
        $customerData = $data['customer'] ?? $data['contact'] ?? $data['buyer'] ?? $data;
        $shippingAddress = $data['shipping_address'] ?? $data['address'] ?? $data['delivery_address'] ?? $customerData['address'] ?? [];

        // Extract customer name
        $customer['name'] = $customerData['name'] 
            ?? $customerData['full_name'] 
            ?? ($customerData['first_name'] ?? '') . ' ' . ($customerData['last_name'] ?? '')
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
