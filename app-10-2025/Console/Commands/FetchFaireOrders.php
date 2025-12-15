<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use GuzzleHttp\Client;

class FetchFaireOrders extends Command
{
    protected $signature = 'faire:fetch-orders';
    protected $description = 'Fetch orders from Faire API using OAuth credentials';

    protected $client;
    protected $appId;
    protected $appSecret;
    protected $redirectUrl;

    public function __construct()
    {
        parent::__construct();
        $this->client = new Client();
        $this->appId = env('FAIRE_APP_ID');
        $this->appSecret = env('FAIRE_APP_SECRET');
        $this->redirectUrl = env('FAIRE_REDIRECT_URL');
    }

    public function handle()
    {
        // Step 1: Ask for authorization code
        $authorizationCode = $this->ask('Enter Faire Authorization Code');

        // Step 2: Get access token
        try {
            $tokenResponse = $this->client->post('https://www.faire.com/api/external-api-oauth2/token', [
                'json' => [
                    'applicationId' => $this->appId,
                    'applicationSecret' => $this->appSecret,
                    'redirectUrl' => $this->redirectUrl,
                    'scope' => ['READ_ORDERS'],
                    'grantType' => 'AUTHORIZATION_CODE',
                    'authorizationCode' => $authorizationCode,
                ],
            ]);

            $tokenData = json_decode($tokenResponse->getBody(), true);
            $accessToken = $tokenData['access_token'] ?? null;

            if (!$accessToken) {
                $this->error('Failed to get access token.');
                return 1;
            }
        } catch (\Exception $e) {
            $this->error('Error getting access token: ' . $e->getMessage());
            return 1;
        }

        // Step 3: Fetch orders
        try {
            $credentials = base64_encode("{$this->appId}:{$this->appSecret}");

            $response = $this->client->get('https://www.faire.com/external-api/v2/orders', [
                'headers' => [
                    'X-FAIRE-APP-CREDENTIALS' => $credentials,
                    'X-FAIRE-OAUTH-ACCESS-TOKEN' => $accessToken,
                    'Accept' => 'application/json',
                ],
            ]);

            $orders = json_decode($response->getBody(), true);

            $this->info('Fetched Orders:');
            $this->line(json_encode($orders, JSON_PRETTY_PRINT));

        } catch (\Exception $e) {
            $this->error('Error fetching orders: ' . $e->getMessage());
            return 1;
        }

        return 0;
    }
}
