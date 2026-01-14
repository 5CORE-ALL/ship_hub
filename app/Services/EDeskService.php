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
                        
                        // Log the actual response structure to debug (limit size to avoid huge logs)
                        $logData = [
                            'top_level_keys' => array_keys($data),
                            'has_data_key' => isset($data['data']),
                        ];
                        if (isset($data['data']) && is_array($data['data'])) {
                            $logData['data_keys'] = array_keys($data['data']);
                            // Log a sample of the data structure (first level only)
                            foreach ($data['data'] as $key => $value) {
                                if (is_array($value)) {
                                    $logData["data_{$key}_keys"] = array_keys($value);
                                } else {
                                    $logData["data_{$key}"] = is_string($value) ? substr($value, 0, 50) : $value;
                                }
                            }
                        }
                        Log::info("eDesk ticket response structure", $logData);
                        
                        // Try to extract customer details from ticket
                        $customerDetails = $this->extractCustomerDetails($data);
                        
                        // Always try to get contact details if contact_id is available (for better name and address)
                        $ticketData = $data['data'] ?? $data;
                        $salesOrder = $data['sales_order'] ?? $ticketData['sales_order'] ?? null;
                        $ticketId = $ticketData['id'] ?? $data['id'] ?? null;
                        $contactId = $ticketData['contact_id'] 
                            ?? $salesOrder['contact_id'] 
                            ?? $data['contact_id'] 
                            ?? null;
                        
                        // Log sales_order structure for debugging
                        if ($salesOrder && is_array($salesOrder)) {
                            Log::debug("eDesk sales_order structure", [
                                'has_ship_to' => isset($salesOrder['ship_to']),
                                'has_bill_to' => isset($salesOrder['bill_to']),
                                'has_contact_id' => isset($salesOrder['contact_id']),
                                'ship_to_type' => isset($salesOrder['ship_to']) ? gettype($salesOrder['ship_to']) : 'not_set',
                                'ship_to_keys' => isset($salesOrder['ship_to']) && is_array($salesOrder['ship_to']) ? array_keys($salesOrder['ship_to']) : [],
                                'sales_order_keys' => array_keys($salesOrder),
                            ]);
                        }
                        
                        // Try to get sales_order details if we have sales_order_id (might have customer data)
                        $salesOrderId = $salesOrder['id'] ?? $ticketData['sales_order_id'] ?? null;
                        if ($salesOrderId && empty($customerDetails['name']) && empty($customerDetails['address1'])) {
                            Log::info("eDesk trying sales_order endpoint for customer data", [
                                'sales_order_id' => $salesOrderId,
                            ]);
                            $salesOrderDetails = $this->getSalesOrderDetails($salesOrderId);
                            if ($salesOrderDetails) {
                                $salesOrderCustomer = $this->extractCustomerDetails($salesOrderDetails);
                                if (!empty($salesOrderCustomer['name']) || !empty($salesOrderCustomer['address1'])) {
                                    $customerDetails = array_merge($customerDetails, $salesOrderCustomer);
                                    Log::info("eDesk customer data from sales_order", [
                                        'fields_found' => array_keys($customerDetails),
                                        'has_name' => !empty($customerDetails['name']),
                                        'has_address' => !empty($customerDetails['address1']),
                                    ]);
                                }
                            }
                        }
                        
                        if ($contactId && (empty($customerDetails['name']) || empty($customerDetails['address1']))) {
                            Log::info("eDesk ticket has contact_id, fetching contact details", [
                                'contact_id' => $contactId,
                                'has_name' => !empty($customerDetails['name']),
                                'has_address' => !empty($customerDetails['address1']),
                            ]);
                            $contactDetails = $this->getContactDetails($contactId);
                            if ($contactDetails) {
                                // Merge contact details - contact data is more reliable than ticket data
                                $customerDetails = array_merge($customerDetails, $contactDetails);
                                Log::info("eDesk contact details fetched and merged", [
                                    'fields_found' => array_keys($customerDetails),
                                    'has_name' => !empty($customerDetails['name']),
                                    'has_address' => !empty($customerDetails['address1']),
                                ]);
                            } else {
                                // If contact endpoint fails, try to get from messages endpoint
                                Log::info("eDesk contact endpoint failed, trying messages endpoint", [
                                    'contact_id' => $contactId,
                                ]);
                                
                                // If we have ticket_id, try messages endpoint to get customer info
                                if ($ticketId) {
                                    $messagesEndpoints = [
                                        "/v1/tickets/{$ticketId}/messages",
                                        "/v1/tickets/{$ticketId}/threads",
                                        "/tickets/{$ticketId}/messages",
                                    ];
                                    
                                    foreach ($messagesEndpoints as $messagesEndpoint) {
                                        try {
                                            $messagesUrl = $this->baseUrl . $messagesEndpoint;
                                            $messagesResponse = Http::timeout(10)->withHeaders([
                                                'Authorization' => 'Bearer ' . $this->bearerToken,
                                                'Accept' => 'application/json',
                                            ])->get($messagesUrl);
                                            
                                            if ($messagesResponse->successful()) {
                                                $messagesData = $messagesResponse->json();
                                                $messages = $messagesData['messages'] ?? $messagesData['data'] ?? [];
                                                if (!empty($messages) && is_array($messages)) {
                                                    // Get customer info from messages (usually has customer data)
                                                    foreach ($messages as $message) {
                                                        if (isset($message['contact']) && is_array($message['contact'])) {
                                                            $contactExtracted = $this->extractCustomerDetails($message['contact']);
                                                            if (!empty($contactExtracted['name']) || !empty($contactExtracted['address1'])) {
                                                                $customerDetails = array_merge($customerDetails, $contactExtracted);
                                                                Log::info("eDesk contact data extracted from messages", [
                                                                    'fields_found' => array_keys($customerDetails),
                                                                    'endpoint' => $messagesEndpoint,
                                                                ]);
                                                                break 2; // Break out of both loops
                                                            }
                                                        }
                                                        // Also check if message itself has customer fields
                                                        if (isset($message['name']) || isset($message['email']) || isset($message['address'])) {
                                                            $messageExtracted = $this->extractCustomerDetails($message);
                                                            if (!empty($messageExtracted['name']) || !empty($messageExtracted['address1'])) {
                                                                $customerDetails = array_merge($customerDetails, $messageExtracted);
                                                                Log::info("eDesk customer data extracted from message", [
                                                                    'fields_found' => array_keys($customerDetails),
                                                                ]);
                                                                break 2;
                                                            }
                                                        }
                                                    }
                                                }
                                            }
                                        } catch (\Exception $e) {
                                            Log::debug("eDesk messages endpoint failed", [
                                                'endpoint' => $messagesEndpoint,
                                                'error' => $e->getMessage()
                                            ]);
                                            continue;
                                        }
                                    }
                                }
                                
                                // Also try to extract from ticket data directly as fallback
                                $contactInTicket = $ticketData['contact'] ?? $data['contact'] ?? null;
                                if ($contactInTicket && is_array($contactInTicket)) {
                                    $contactExtracted = $this->extractCustomerDetails($contactInTicket);
                                    if (!empty($contactExtracted)) {
                                        $customerDetails = array_merge($customerDetails, $contactExtracted);
                                        Log::info("eDesk contact data extracted from ticket", [
                                            'fields_found' => array_keys($customerDetails),
                                        ]);
                                    }
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
     * Get sales order details by sales order ID
     * 
     * @param int|string $salesOrderId
     * @return array|null
     */
    protected function getSalesOrderDetails($salesOrderId): ?array
    {
        if (!$salesOrderId) {
            return null;
        }

        try {
            // Try different sales order endpoints
            $endpointPatterns = [
                "/v1/sales_orders/{sales_order_id}",
                "/v1/orders/{sales_order_id}",
                "/sales_orders/{sales_order_id}",
                "/orders/{sales_order_id}",
            ];
            
            $endpoints = array_map(function($pattern) use ($salesOrderId) {
                return str_replace('{sales_order_id}', $salesOrderId, $pattern);
            }, $endpointPatterns);

            foreach ($endpoints as $endpoint) {
                try {
                    $fullUrl = $this->baseUrl . $endpoint;
                    $response = Http::timeout(10)->withHeaders([
                        'Authorization' => 'Bearer ' . $this->bearerToken,
                        'Accept' => 'application/json',
                    ])->get($fullUrl);

                    if ($response->successful()) {
                        $data = $response->json();
                        Log::info("eDesk sales_order details fetched via endpoint: {$endpoint}");
                        return $data['sales_order'] ?? $data['order'] ?? $data['data'] ?? $data;
                    } else {
                        Log::debug("eDesk sales_order endpoint failed: {$endpoint}", [
                            'status' => $response->status(),
                            'error' => substr($response->body(), 0, 200),
                        ]);
                    }
                } catch (\Exception $e) {
                    Log::debug("eDesk sales_order endpoint exception: {$endpoint}", [
                        'error' => $e->getMessage(),
                    ]);
                    continue;
                }
            }

            return null;
        } catch (\Exception $e) {
            Log::warning("eDesk sales_order fetch failed: " . $e->getMessage());
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
        
        // Log what we're looking at for debugging
        Log::debug("eDesk extraction - checking structures", [
            'has_data' => isset($data['data']),
            'has_contact' => isset($data['contact']) || isset($ticketData['contact']),
            'has_customer' => isset($data['customer']),
            'has_sales_order' => !empty($salesOrder),
            'ticket_data_keys' => is_array($ticketData) ? array_keys($ticketData) : [],
        ]);
        
        // Try different possible structures for customer data - prioritize contact/customer over ticket data
        $customerData = $data['customer'] 
            ?? $data['contact'] 
            ?? $ticketData['contact'] 
            ?? $salesOrder['contact'] 
            ?? $salesOrder['ship_to']
            ?? $salesOrder['bill_to']
            ?? $data['buyer'] 
            ?? null; // Don't fall back to $data - that would include ticket subject
        
        // If we didn't find customer data, try to extract from ticket messages or other fields
        if (empty($customerData) || !is_array($customerData)) {
            // Check if there's customer info in messages or other ticket fields
            $messages = $data['messages'] ?? $ticketData['messages'] ?? [];
            if (!empty($messages) && is_array($messages)) {
                // Try to get customer from first message
                $firstMessage = is_array($messages[0] ?? null) ? $messages[0] : null;
                if ($firstMessage) {
                    $customerData = $firstMessage['contact'] ?? $firstMessage['customer'] ?? $firstMessage['author'] ?? null;
                }
            }
        }
        
        // If still no customer data, set to empty array
        if (empty($customerData) || !is_array($customerData)) {
            $customerData = [];
        }
            
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

        // Extract customer name - prioritize contact/customer data, NEVER use ticket subject
        // Try contact/customer fields first (most reliable)
        // Also check if sales_order has customer name directly
        $rawName = $customerData['name'] 
            ?? $customerData['full_name'] 
            ?? $customerData['contact_name']
            ?? (!empty($customerData['first_name']) || !empty($customerData['last_name']) 
                ? trim(($customerData['first_name'] ?? '') . ' ' . ($customerData['last_name'] ?? '')) 
                : null)
            ?? $shippingAddress['name']
            ?? $shippingAddress['full_name']
            ?? ($salesOrder && is_array($salesOrder) ? ($salesOrder['customer_name'] ?? $salesOrder['buyer_name'] ?? $salesOrder['recipient_name'] ?? null) : null)
            ?? $data['recipient_name']
            ?? null;
        
        // Validate the name - reject ticket subjects
        if (!empty($rawName)) {
            $nameLower = strtolower(trim($rawName));
            $invalidPatterns = [
                'sent a message',
                'about',
                'buyer wants',
                'wants to cancel',
                'cancel an order',
                'a buyer',
                'the buyer',
                'wants to',
            ];
            
            // Check for invalid patterns
            $isInvalid = false;
            foreach ($invalidPatterns as $pattern) {
                if (strpos($nameLower, $pattern) !== false) {
                    $isInvalid = true;
                    break;
                }
            }
            
            // Check if it starts with "A buyer" or "The buyer"
            if (!$isInvalid && (strpos($nameLower, 'a buyer') === 0 || strpos($nameLower, 'the buyer') === 0)) {
                $isInvalid = true;
            }
            
            // Check for ticket subject patterns (e.g., "username sent a message about...")
            if (!$isInvalid && (
                preg_match('/^[a-z0-9]+ sent a message/i', $rawName) ||
                preg_match('/about .*#\d+/i', $rawName) ||
                strlen($rawName) > 80
            )) {
                $isInvalid = true;
            }
            
            // Only use if valid
            if (!$isInvalid) {
                $customer['name'] = trim($rawName);
            }
            // If invalid, don't set name (will be null)
        }
        
        // NEVER use ticket subject - it's always a message, not a customer name
        // Ticket subjects are like "pi2847 sent a message about..." which are NOT customer names

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
        // Also check sales_order.ship_to if available (even if null, might be in sales_order directly)
        $shipTo = $salesOrder['ship_to'] ?? null;
        if ($shipTo && is_array($shipTo)) {
            $shippingAddress = array_merge($shippingAddress, $shipTo);
        }
        
        // Also check if sales_order has address fields directly
        if ($salesOrder && is_array($salesOrder)) {
            $soAddressFields = ['address1', 'address_line1', 'street', 'city', 'state', 'postal_code', 'zip_code', 'country'];
            foreach ($soAddressFields as $field) {
                if (isset($salesOrder[$field]) && !isset($shippingAddress[$field])) {
                    $shippingAddress[$field] = $salesOrder[$field];
                }
            }
        }
        
        $customer['address1'] = $shippingAddress['address1'] 
            ?? $shippingAddress['address_line1'] 
            ?? $shippingAddress['street']
            ?? $shippingAddress['street_address']
            ?? $shippingAddress['AddressLine1']
            ?? ($shipTo && is_array($shipTo) ? ($shipTo['address1'] ?? $shipTo['address_line1'] ?? null) : null)
            ?? ($salesOrder['address1'] ?? $salesOrder['address_line1'] ?? null)
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
            ?? ($shipTo && is_array($shipTo) ? ($shipTo['city'] ?? null) : null)
            ?? ($salesOrder['city'] ?? null)
            ?? $customerData['city']
            ?? null;

        $customer['state'] = $shippingAddress['state'] 
            ?? $shippingAddress['state_or_region']
            ?? $shippingAddress['StateOrRegion']
            ?? $shippingAddress['province']
            ?? ($shipTo && is_array($shipTo) ? ($shipTo['state'] ?? $shipTo['state_or_region'] ?? null) : null)
            ?? ($salesOrder['state'] ?? $salesOrder['state_or_region'] ?? null)
            ?? $customerData['state']
            ?? null;

        $customer['postal_code'] = $shippingAddress['postal_code'] 
            ?? $shippingAddress['postal']
            ?? $shippingAddress['zip_code']
            ?? $shippingAddress['zip']
            ?? $shippingAddress['PostalCode']
            ?? ($shipTo && is_array($shipTo) ? ($shipTo['postal_code'] ?? $shipTo['zip_code'] ?? null) : null)
            ?? ($salesOrder['postal_code'] ?? $salesOrder['zip_code'] ?? null)
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
