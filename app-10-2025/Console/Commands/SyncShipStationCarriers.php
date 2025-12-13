<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Services\ShipStationService;
use App\Models\CarriersList;
use App\Models\CarrierServicesList;
use App\Models\CarrierPackagesList;
use Exception;

class SyncShipStationCarriers extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:sync-ship-station-carriers';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Sync carrier details from ShipStation API into the database';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $shipStationService = new ShipStationService();

        try {
            // Fetch carriers from ShipStation API
            $carriersResponse = $shipStationService->getCarriers();
            $this->info('Fetching carriers from ShipStation...');

            // Process each carrier from the response
            foreach ($carriersResponse['carriers'] as $carrier) {
                // Insert or update carriers_list
                CarriersList::updateOrCreate(
                    ['carrier_id' => $carrier['carrier_id']],
                    [
                        'carrier_code' => $carrier['carrier_code'],
                        'account_number' => $carrier['account_number'] ?? '-',
                        'requires_funded_amount' => $carrier['requires_funded_amount'] ?? false,
                        'balance' => $carrier['balance'] ?? 0.0000,
                        'nickname' => $carrier['nickname'] ?? null,
                        'friendly_name' => $carrier['friendly_name'] ?? 'Unknown',
                        'primary_carrier' => $carrier['primary'] ?? false,
                        'has_multi_package_supporting_services' => $carrier['has_multi_package_supporting_services'] ?? false,
                        'allows_returns' => $carrier['allows_returns'] ?? false,
                        'supports_label_messages' => $carrier['supports_label_messages'] ?? false,
                        'disabled_by_billing_plan' => $carrier['disabled_by_billing_plan'] ?? false,
                        'funding_source_id' => $carrier['funding_source_id'] ?? null,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]
                );
                $this->info("Synced carrier: {$carrier['friendly_name']}");

                // Insert or update carrier_services_list
                foreach ($carrier['services'] as $service) {
                    CarrierServicesList::updateOrCreate(
                        [
                            'carrier_id' => $service['carrier_id'],
                            'service_code' => $service['service_code'],
                        ],
                        [
                            'name' => $service['name'],
                            'domestic' => $service['domestic'],
                            'international' => $service['international'],
                            'is_multi_package_supported' => $service['is_multi_package_supported'],
                            'is_return_supported' => $service['is_return_supported'],
                            'display_schemes' => json_encode($service['display_schemes']),
                            'created_at' => now(),
                            'updated_at' => now(),
                        ]
                    );
                }
                $this->info("Synced services for carrier: {$carrier['friendly_name']}");

                // Insert or update carrier_packages_list
                foreach ($carrier['packages'] as $package) {
                    CarrierPackagesList::updateOrCreate(
                        [
                            'carrier_id' => $carrier['carrier_id'],
                            'package_code' => $package['package_code'],
                        ],
                        [
                            'name' => $package['name'],
                            'description' => $package['description'] ?? '',
                            'created_at' => now(),
                            'updated_at' => now(),
                        ]
                    );
                }
                $this->info("Synced packages for carrier: {$carrier['friendly_name']}");
            }

            $this->info('Carrier sync completed successfully.');
        } catch (Exception $e) {
            $this->error('Error syncing ShipStation data: ' . $e->getMessage());
        }
    }
}