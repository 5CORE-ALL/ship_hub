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
                $ticketDetails = $this->getTicketDetails($ticket['id'] ?? $ticket['ticket_id'] ?? null);
                return $ticketDetails;
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
                "/v1/tickets?order_id={order_id}",
                "/v1/tickets?order_number={order_id}",
                "/v2/tickets?filter[order_id]={order_id}",
                "/tickets?q={order_id}",
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
                            return is_array($data['tickets']) && isset($data['tickets'][0]) ? $data['tickets'][0] : $data['tickets'];
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
                "/v1/tickets/{ticket_id}",
                "/v2/tickets/{ticket_id}",
                "/tickets/{ticket_id}",
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
                        
                        // Try to extract customer details from ticket
                        $customerDetails = $this->extractCustomerDetails($data);
                        
                        // If we have contact_id but missing address data, fetch contact details
                        // (even if we have a name, we still need address)
                        if (empty($customerDetails['address1'])) {
                            $ticketData = $data['data'] ?? $data;
                            $salesOrder = $data['sales_order'] ?? $ticketData['sales_order'] ?? null;
                            $contactId = $ticketData['contact_id'] 
                                ?? $salesOrder['contact_id'] 
                                ?? $data['contact_id'] 
                                ?? null;
                            
                            if ($contactId) {
                                Log::info("eDesk ticket has contact_id but missing address, fetching contact details", [
                                    'contact_id' => $contactId,
                                    'has_name' => !empty($customerDetails['name']),
                                ]);
                                $contactDetails = $this->getContactDetails($contactId);
                                if ($contactDetails) {
                                    // Merge contact details with any existing customer details (contact overwrites ticket data)
                                    $customerDetails = array_merge($customerDetails, $contactDetails);
                                    Log::info("eDesk contact details fetched and merged", [
                                        'fields_found' => array_keys($customerDetails),
                                        'has_address' => !empty($customerDetails['address1']),
                                    ]);
                                }
                            }
                        }
                        
                        // Log extracted customer details
                        if (!empty($customerDetails)) {
                            Log::info("eDesk customer details extracted", [
                                'fields_found' => array_keys($customerDetails),
                                'has_name' => !empty($customerDetails['name']),
                                'has_address' => !empty($customerDetails['address1']),
                            ]);
                        } else {
                            Log::warning("eDesk customer details extraction returned empty", [
                                'response_keys' => array_keys($data),
                            ]);
                        }
                        
                        return $customerDetails;
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
                "/v1/orders/{order_id}",
                "/v2/orders/{order_id}",
                "/orders/{order_id}",
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
     * Get contact details by contact ID
     * 
     * @param int|string $contactId
     * @return array|null
     */
    protected function getContactDetails($contactId): ?array
    {
        if (!$contactId) {
            return null;
        }

        try {
            // Get endpoints from config, fallback to defaults
            $endpointPatterns = config('services.edesk.endpoints.contact_lookup', [
                '/v1/contacts/{contact_id}',
                '/v2/contacts/{contact_id}',
                '/contacts/{contact_id}',
            ]);
            
            // Replace {contact_id} placeholder with actual contact ID
            $endpoints = array_map(function($pattern) use ($contactId) {
                return str_replace('{contact_id}', $contactId, $pattern);
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
                        Log::info("eDesk contact details fetched via endpoint: {$endpoint}");
                        return $this->extractCustomerDetails($data);
                    } else {
                        // Log failed responses
                        $errorBody = $response->body();
                        Log::debug("eDesk contact endpoint failed: {$endpoint}", [
                            'status' => $statusCode,
                            'error' => substr($errorBody, 0, 500),
                        ]);
                    }
                } catch (\Exception $e) {
                    // Continue to next endpoint on error
                    Log::debug("eDesk contact endpoint exception: {$endpoint}", [
                        'error' => $e->getMessage(),
                    ]);
                    continue;
                }
            }

            return null;
        } catch (\Exception $e) {
            Log::warning("eDesk contact fetch failed: " . $e->getMessage());
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
        
        // Handle eDesk API response structure: data.sales_order or direct structure
        $salesOrder = $data['sales_order'] ?? ($data['data']['sales_order'] ?? null);
        $ticketData = $data['data'] ?? $data;
        
        // Try different possible structures for customer data
        $customerData = $data['customer'] 
            ?? $data['contact'] 
            ?? $ticketData['contact'] 
            ?? $salesOrder['contact'] 
            ?? $salesOrder['ship_to']
            ?? $salesOrder['bill_to']
            ?? $data['buyer'] 
            ?? $data;
            
        $shippingAddress = $data['shipping_address'] 
            ?? $data['address'] 
            ?? $salesOrder['ship_to']
            ?? $salesOrder['shipping_address']
            ?? $salesOrder['address']
            ?? $data['delivery_address'] 
            ?? [];
        
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

        // Extract customer name - try multiple sources
        $customer['name'] = $customerData['name'] 
            ?? $customerData['full_name'] 
            ?? $customerData['contact_name']
            ?? (!empty($customerData['first_name']) || !empty($customerData['last_name']) 
                ? trim(($customerData['first_name'] ?? '') . ' ' . ($customerData['last_name'] ?? '')) 
                : null)
            ?? $shippingAddress['name']
            ?? $shippingAddress['full_name']
            ?? $ticketData['subject'] // Sometimes subject contains customer name
            ?? $data['recipient_name']
            ?? null;

        // Extract email
        $customer['email'] = $customerData['email'] 
            ?? $customerData['contact_email']
            ?? $customerData['email_address']
            ?? $data['customer_email']
            ?? $ticketData['customer_email']
            ?? null;

        // Extract phone
        $customer['phone'] = $customerData['phone'] 
            ?? $customerData['contact_phone']
            ?? $customerData['phone_number']
            ?? $customerData['mobile']
            ?? $shippingAddress['phone']
            ?? $shippingAddress['phone_number']
            ?? $data['customer_phone']
            ?? null;

        // Extract address fields from shipping address
        $customer['address1'] = $shippingAddress['address1'] 
            ?? $shippingAddress['address_line1'] 
            ?? $shippingAddress['street']
            ?? $shippingAddress['street_address']
            ?? $shippingAddress['AddressLine1']
            ?? $customerData['address1']
            ?? $customerData['address_line1']
            ?? null;

        $customer['address2'] = $shippingAddress['address2'] 
            ?? $shippingAddress['address_line2'] 
            ?? $shippingAddress['street2']
            ?? $shippingAddress['AddressLine2']
            ?? $customerData['address2']
            ?? $customerData['address_line2']
            ?? null;

        $customer['city'] = $shippingAddress['city'] 
            ?? $shippingAddress['City']
            ?? $customerData['city']
            ?? null;

        $customer['state'] = $shippingAddress['state'] 
            ?? $shippingAddress['state_or_region']
            ?? $shippingAddress['StateOrRegion']
            ?? $shippingAddress['province']
            ?? $customerData['state']
            ?? null;

        $customer['postal_code'] = $shippingAddress['postal_code'] 
            ?? $shippingAddress['postal']
            ?? $shippingAddress['zip_code']
            ?? $shippingAddress['zip']
            ?? $shippingAddress['PostalCode']
            ?? $customerData['postal_code']
            ?? $customerData['zip_code']
            ?? null;

        $customer['country'] = $shippingAddress['country'] 
            ?? $shippingAddress['country_code']
            ?? $shippingAddress['CountryCode']
            ?? $customerData['country']
            ?? $customerData['country_code']
            ?? null;

        // Filter out null values
        return array_filter($customer, function($value) {
            return $value !== null && $value !== '';
        });
    }
}
