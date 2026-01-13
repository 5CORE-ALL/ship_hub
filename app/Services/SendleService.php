<?php
namespace App\Services;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Exception\ServerException;
use Illuminate\Support\Facades\Log;

class SendleService
{
    protected string $baseUrl;
    protected string $apiKey;
    protected string $apiSecret;
    protected Client $client;

    public function __construct()
    {
        $this->apiKey    = config('services.sendle.key');
        $this->apiSecret = config('services.sendle.secret');
        $this->baseUrl   = config('services.sendle.url');

        $this->client = new Client([
            'base_uri' => $this->baseUrl,
            'auth'     => [$this->apiKey, $this->apiSecret], // Basic auth
            'headers'  => [
                'Accept'       => 'application/json',
                'Content-Type' => 'application/json',
            ],
        ]);
    }
    public function getRates(array $params): array
    {
        $data = [
            "sender_suburb"     => $params['sender_suburb'] ?? 'New York',
            "sender_postcode"   => $params['sender_postcode'] ?? '10001',
            "sender_country"    => $params['sender_country'] ?? 'US',
            "receiver_suburb"   => $params['receiver_suburb'] ?? 'Los Angeles',
            "receiver_postcode" => $params['receiver_postcode'] ?? '90001',
            "receiver_country"  => $params['receiver_country'] ?? 'US',
            "weight_value"      => isset($params['weight_value']) ? (float)$params['weight_value'] : 2.0,
            "weight_units"      => $params['weight_units'] ?? 'lb',
            "dimension_units"   => $params['dimension_units'] ?? 'in',
            "length"            => isset($params['length']) ? (float)$params['length'] : null,
            "width"             => isset($params['width']) ? (float)$params['width'] : null,
            "height"            => isset($params['height']) ? (float)$params['height'] : null,
        ];
        Log::info("Sendle rate request payload", $data);
        try {
            $response = $this->client->get('api/products', [
                'json' => $data
            ]);
            $rates = json_decode($response->getBody()->getContents(), true);
            Log::info("Sendle rate response", $rates);
            $options = [];

            if (is_array($rates)) {
                foreach ($rates as $rate) {
                    $options[] = [
                        'rate_id' => $rate['product']['code'] ?? 'unknown',
                        'id' => $rate['product']['service'] ?? $rate['product']['code'] ?? 'unknown',
                        'name' => $rate['product']['name'] ?? 'Unknown Service',
                        'estimated_time' => isset($rate['eta']['days_range']) 
                                            ? max($rate['eta']['days_range']) 
                                            : null,
                        'description' => $rate['route']['description'] ?? 'Shipping',
                        'price' => $rate['quote']['gross']['amount'] ?? 0,
                        'currency' => strtolower($rate['quote']['gross']['currency'] ?? 'USD'),
                        'length' => $data['length'],
                        'width' => $data['width'],
                        'height' => $data['height'],
                        'weight' => $data['weight_value'],
                        'carrier' => 'Sendle',
                    ];
                }
            }
            usort($options, fn($a, $b) => $a['price'] <=> $b['price']);

            return [
                'success' => true,
                'options' => $options,
            ];

        } catch (\Exception $e) {
            Log::error("Sendle rate error: " . $e->getMessage());
            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }
    public function createOrder(array $data): array
    {
        $maxRetries = 3;
        $retryDelay = 2; // Initial delay in seconds

        for ($attempt = 1; $attempt <= $maxRetries; $attempt++) {
            try {
                Log::info('Sendle Order Request Payload', ['attempt' => $attempt, 'data' => $data]);
                $response = $this->client->post('api/orders', [
                    'json' => $data
                ]);

                $result = json_decode($response->getBody()->getContents(), true);
                $labelUrl = null;
                if (!empty($result['labels'])) {
                    foreach ($result['labels'] as $label) {
                        if (($label['size'] ?? null) === 'cropped') {
                            $labelUrl = $label['url'];
                            break;
                        }
                    }
                }
                $localUrl = null;
                if ($labelUrl) {
                    $localUrl = fetchSendleLabelPdf($labelUrl, $this->apiKey, $this->apiSecret);
                }

                Log::info('Sendle Order Created', $result);
                $mappedLabel = [
                    'label_id'       => $result['order_id'] ?? null,
                    'trackingNumber' => $result['sendle_reference'] ?? null,
                    'labelUrl'       => $localUrl, 
                    'shipDate'       => $result['scheduling']['pickup_date'] ?? now()->toDateString(),
                    'raw'            => [
                        'carrier_code'   => 'Sendle',
                        'service_code'   => $result['product']['service'] ?? null,
                        'shipment_cost'  => [
                            'amount'   => $result['price']['gross']['amount'] ?? null,
                            'currency' => $result['price']['gross']['currency'] ?? null,
                        ],
                        'tracking_url'   => $result['tracking_url'] ?? null,
                        'packages'       => [
                            [
                                'weight'     => ['value' => $result['weight']['value'] ?? null],
                                'dimensions' => $result['dimensions'] ?? null,
                            ]
                        ]
                    ]
                ];
                return [
                    'success' => true,
                    'label_id' => $mappedLabel['label_id'],
                    'trackingNumber' => $mappedLabel['trackingNumber'],
                    'labelUrl' => $mappedLabel['labelUrl'],
                    'raw' => $mappedLabel['raw'],
                    'shipDate' => $mappedLabel['shipDate']
                ];

            } catch (RequestException $e) {
                $statusCode = $e->hasResponse() ? $e->getResponse()->getStatusCode() : 0;
                
                // Parse error response
                $errorData = [];
                if ($e->hasResponse()) {
                    try {
                        $responseBody = $e->getResponse()->getBody()->getContents();
                        if (!empty($responseBody)) {
                            $errorData = json_decode($responseBody, true) ?? [];
                        }
                    } catch (\Exception $bodyException) {
                        // If we can't read the body, continue with empty error data
                        Log::warning('Could not read Sendle error response body', ['error' => $bodyException->getMessage()]);
                    }
                }

                // Check if it's a retryable error (503 Service Unavailable, 502 Bad Gateway, 504 Gateway Timeout, 429 Too Many Requests)
                $isRetryable = in_array($statusCode, [503, 502, 504, 429]);
                
                if ($isRetryable && $attempt < $maxRetries) {
                    $waitTime = $retryDelay * pow(2, $attempt - 1); // Exponential backoff
                    Log::warning("Sendle API returned {$statusCode} (attempt {$attempt}/{$maxRetries}). Retrying in {$waitTime} seconds...", [
                        'status_code' => $statusCode,
                        'error' => $errorData,
                        'attempt' => $attempt
                    ]);
                    sleep($waitTime);
                    continue; // Retry
                }

                // Non-retryable error or max retries reached
                $errorMessage = $errorData['error_description'] ?? $errorData['error'] ?? $e->getMessage();
                
                if ($isRetryable && $attempt >= $maxRetries) {
                    $errorMessage = "Sendle API service unavailable (HTTP {$statusCode}). All {$maxRetries} retry attempts failed. Please check Sendle's status page or try again later. Original error: " . $errorMessage;
                } elseif ($statusCode === 503) {
                    $errorMessage = "Sendle API service temporarily unavailable (HTTP 503). Please check Sendle's status page or contact Sendle support if this persists. Error: " . $errorMessage;
                }

                Log::error('Sendle Order Error', [
                    'status_code' => $statusCode,
                    'attempt' => $attempt,
                    'error' => $errorMessage,
                    'error_data' => $errorData,
                    'request_payload' => $data
                ]);

                return [
                    'success' => false,
                    'error' => $errorMessage,
                    'status_code' => $statusCode,
                    'retries_attempted' => $attempt
                ];

            } catch (\Exception $e) {
                Log::error('Sendle Order Error: ' . $e->getMessage(), [
                    'request_payload' => $data,
                    'attempt' => $attempt,
                    'trace' => $e->getTraceAsString()
                ]);

                return [
                    'success' => false,
                    'error' => $e->getMessage(),
                    'retries_attempted' => $attempt
                ];
            }
        }

        // Should never reach here, but just in case
        return [
            'success' => false,
            'error' => 'Sendle order creation failed after all retry attempts'
        ];
    }
    public function voidLabel(string $orderId): array
    {
    try {
        Log::info("Sendle Void Label Request", ['order_id' => $orderId]);
        $response = $this->client->delete("api/orders/{$orderId}");
        $result = json_decode($response->getBody()->getContents(), true);

        Log::info("Sendle Void Label Response", $result);

        // Map Sendle response to match existing structure
        return [
            'success' => true,
            'label_id' => $result['order_id'] ?? null,
            'trackingNumber' => $result['sendle_reference'] ?? null,
            'labelUrl' => $result['labels'][0]['url'] ?? null,
            'raw' => $result
        ];
    } catch (\Exception $e) {
        Log::error("Sendle Void Label Error: " . $e->getMessage(), ['order_id' => $orderId]);

        return [
            'success' => false,
            'label_id' => null,
            'trackingNumber' => null,
            'labelUrl' => null,
            'raw' => ['error' => $e->getMessage()]
        ];
    }
}
public function verifyLabelUrl(string $labelUrl): array
{
    try {
        $apiKey = config('services.sendle.key', env('SENDLE_KEY', ''));
        $apiSecret = config('services.sendle.secret', env('SENDLE_SECRET', ''));
        $parsed = parse_url($labelUrl);
        $urlWithAuth = $parsed['scheme'] . '://' . $apiKey . ':' . $apiSecret . '@' . $parsed['host'] . $parsed['path'];

        if (isset($parsed['query'])) {
            $urlWithAuth .= '?' . $parsed['query'];
        }
        $pdfContent = file_get_contents($urlWithAuth);

        if ($pdfContent === false) {
            return [
                'success' => false,
                'message' => 'Unable to fetch label from Sendle.',
                'labelUrl' => $labelUrl,
            ];
        }

        // Save to local storage
        $fileName = 'labels/sendle_' . time() . '.pdf';
        \Illuminate\Support\Facades\Storage::disk('public')->put($fileName, $pdfContent);

        $localUrl = asset('storage/' . $fileName);

        return [
            'success' => true,
            'labelUrl' => $localUrl,
        ];

    } catch (\Exception $e) {
        \Illuminate\Support\Facades\Log::error("Sendle Verify Label Error: " . $e->getMessage(), ['labelUrl' => $labelUrl]);

        return [
            'success' => false,
            'message' => $e->getMessage(),
            'labelUrl' => $labelUrl,
        ];
    }
}





}
