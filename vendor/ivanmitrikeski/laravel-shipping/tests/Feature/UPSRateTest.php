<?php

namespace Mitrik\Tests\Feature;

use Mitrik\Shipping\ServiceProviders\Address\Address;
use Mitrik\Shipping\ServiceProviders\Box\BoxCollection;
use Mitrik\Shipping\ServiceProviders\Box\BoxMetric;
use Mitrik\Shipping\ServiceProviders\Exceptions\BoxOverweight;
use Mitrik\Shipping\ServiceProviders\Exceptions\InvalidCredentials;
use Mitrik\Shipping\ServiceProviders\Exceptions\InvalidShipmentParameters;
use Mitrik\Shipping\ServiceProviders\Exceptions\PriceNotFound;
use Mitrik\Shipping\ServiceProviders\Exceptions\ShipmentNotCreated;
use Mitrik\Shipping\ServiceProviders\Phone\Phone;
use Mitrik\Shipping\ServiceProviders\ServiceProviderService\ServiceProviderService;
use Mitrik\Shipping\ServiceProviders\ServiceUPS\ServiceUPS;
use Mitrik\Shipping\ServiceProviders\ServiceUPS\ServiceUPSCredentials;
use Mitrik\Shipping\ServiceProviders\ShipFrom\ShipFrom;
use Mitrik\Shipping\ServiceProviders\ShipTo\ShipTo;
use PHPUnit\Framework\TestCase;

/**
 *
 */
class UPSRateTest extends TestCase
{
    /**
     * Test domestic rate for a single box
     *
     * @return void
     * @throws InvalidCredentials
     * @throws InvalidShipmentParameters
     * @throws PriceNotFound
     */
    public function test_ups_domestic_shipping_rate_response()
    {
        $credentials = new ServiceUPSCredentials(env('UPS_CLIENT_ID'), env('UPS_CLIENT_SECRET'), env('UPS_USER_ID'), env('UPS_ACCOUNT_NUMBER'), env('SHIPPING_SANDBOX'));
        $serviceUPS = new ServiceUPS($credentials);

        $rates = $serviceUPS->rate(
            new Address(
                env('TEST_SHIPPING_ORIGIN_FIRST_NAME'),
                env('TEST_SHIPPING_ORIGIN_LAST_NAME'),
                env('TEST_SHIPPING_ORIGIN_COMPANY'),
                env('TEST_SHIPPING_ORIGIN_LINE1'),
                env('TEST_SHIPPING_ORIGIN_LINE2'),
                env('TEST_SHIPPING_ORIGIN_CITY'),
                env('TEST_SHIPPING_ORIGIN_POSTAL_CODE'),
                env('TEST_SHIPPING_ORIGIN_STATE'),
                env('TEST_SHIPPING_ORIGIN_COUNTRY')
            ),
            new Address(
                'Ivan',
                'Mitrikeski',
                '',
                '100 City Centre Dr',
                '',
                'Mississauga',
                'L5B 2C9',
                'ON',
                'CA'
            ),
            new BoxCollection([
                new BoxMetric(20, 10, 5, 1)
            ])
        );

        $this->assertArrayHasKey(0, $rates);
    }

    /**
     * Test US rate for a single box
     *
     * @return void
     * @throws InvalidCredentials
     * @throws InvalidShipmentParameters
     * @throws PriceNotFound
     */
    public function test_ups_domestic_shipping_us_rate_response()
    {
        $credentials = new ServiceUPSCredentials(env('UPS_CLIENT_ID'), env('UPS_CLIENT_SECRET'), env('UPS_USER_ID'), env('UPS_ACCOUNT_NUMBER'), env('SHIPPING_SANDBOX'));
        $serviceUPS = new ServiceUPS($credentials);

        $rates = $serviceUPS->rate(
            new Address(
                env('TEST_SHIPPING_ORIGIN_FIRST_NAME'),
                env('TEST_SHIPPING_ORIGIN_LAST_NAME'),
                env('TEST_SHIPPING_ORIGIN_COMPANY'),
                env('TEST_SHIPPING_ORIGIN_LINE1'),
                env('TEST_SHIPPING_ORIGIN_LINE2'),
                env('TEST_SHIPPING_ORIGIN_CITY'),
                env('TEST_SHIPPING_ORIGIN_POSTAL_CODE'),
                env('TEST_SHIPPING_ORIGIN_STATE'),
                env('TEST_SHIPPING_ORIGIN_COUNTRY')
            ),
            new Address(
                'Ivan',
                'Mitrikeski',
                '',
                '1 Wall St',
                '',
                'New York',
                '10005',
                'NY',
                'US'
            ),
            new BoxCollection([
                new BoxMetric(20, 10, 5, 1)
            ])
        );

        $this->assertArrayHasKey(0, $rates);
        $this->assertArrayHasKey(1, $rates);
        $this->assertArrayHasKey(2, $rates);
    }

    /**
     * Test International rate for a single box
     *
     * @return void
     * @throws InvalidCredentials
     * @throws InvalidShipmentParameters
     * @throws PriceNotFound
     */
    public function test_ups_domestic_shipping_international_rate_response()
    {
        $credentials = new ServiceUPSCredentials(env('UPS_CLIENT_ID'), env('UPS_CLIENT_SECRET'), env('UPS_USER_ID'), env('UPS_ACCOUNT_NUMBER'), env('SHIPPING_SANDBOX'));
        $serviceUPS = new ServiceUPS($credentials);

        $rates = $serviceUPS->rate(
            new Address(
                env('TEST_SHIPPING_ORIGIN_FIRST_NAME'),
                env('TEST_SHIPPING_ORIGIN_LAST_NAME'),
                env('TEST_SHIPPING_ORIGIN_COMPANY'),
                env('TEST_SHIPPING_ORIGIN_LINE1'),
                env('TEST_SHIPPING_ORIGIN_LINE2'),
                env('TEST_SHIPPING_ORIGIN_CITY'),
                env('TEST_SHIPPING_ORIGIN_POSTAL_CODE'),
                env('TEST_SHIPPING_ORIGIN_STATE'),
                env('TEST_SHIPPING_ORIGIN_COUNTRY')
            ),
            new Address(
                'Ivan',
                'Mitrikeski',
                '',
                'Panoramastrasse 1A',
                '',
                'Berlin',
                '10178',
                '',
                'DE'
            ),
            new BoxCollection([
                new BoxMetric(20, 10, 5, 1)
            ])
        );

        $this->assertArrayHasKey(0, $rates);
        $this->assertArrayHasKey(1, $rates);
        $this->assertArrayHasKey(2, $rates);
    }

    /**
     * Test domestic rate for multiple boxes
     *
     * @return void
     */
    public function test_ups_domestic_shipping_rates_response()
    {
        $credentials = new ServiceUPSCredentials(env('UPS_CLIENT_ID'), env('UPS_CLIENT_SECRET'), env('UPS_USER_ID'), env('UPS_ACCOUNT_NUMBER'), env('SHIPPING_SANDBOX'));
        $serviceUPS = new ServiceUPS($credentials);

        $rates = $serviceUPS->rates(
            new Address(
                env('TEST_SHIPPING_ORIGIN_FIRST_NAME'),
                env('TEST_SHIPPING_ORIGIN_LAST_NAME'),
                env('TEST_SHIPPING_ORIGIN_COMPANY'),
                env('TEST_SHIPPING_ORIGIN_LINE1'),
                env('TEST_SHIPPING_ORIGIN_LINE2'),
                env('TEST_SHIPPING_ORIGIN_CITY'),
                env('TEST_SHIPPING_ORIGIN_POSTAL_CODE'),
                env('TEST_SHIPPING_ORIGIN_STATE'),
                env('TEST_SHIPPING_ORIGIN_COUNTRY')
            ),
            new Address(
                'Ivan',
                'Mitrikeski',
                '',
                '100 City Centre Dr',
                '',
                'Mississauga',
                'L5B 2C9',
                'ON',
                'CA'
            ),
            new BoxCollection([
                new BoxMetric(20, 10, 5, 1),
                new BoxMetric(20, 10, 5, 1),
            ])
        );

        $this->assertArrayHasKey(0, $rates);
    }

    /**
     * Test domestic rate for a single box
     *
     * @return void
     * @throws InvalidCredentials
     * @throws InvalidShipmentParameters
     * @throws PriceNotFound
     */
    public function test_ups_overweight_response()
    {
        $this->expectException(BoxOverweight::class);

        $credentials = new ServiceUPSCredentials(env('UPS_CLIENT_ID'), env('UPS_CLIENT_SECRET'), env('UPS_USER_ID'), env('UPS_ACCOUNT_NUMBER'), env('SHIPPING_SANDBOX'));
        $serviceUPS = new ServiceUPS($credentials);

        $rates = $serviceUPS->rate(
            new Address(
                env('TEST_SHIPPING_ORIGIN_FIRST_NAME'),
                env('TEST_SHIPPING_ORIGIN_LAST_NAME'),
                env('TEST_SHIPPING_ORIGIN_COMPANY'),
                env('TEST_SHIPPING_ORIGIN_LINE1'),
                env('TEST_SHIPPING_ORIGIN_LINE2'),
                env('TEST_SHIPPING_ORIGIN_CITY'),
                env('TEST_SHIPPING_ORIGIN_POSTAL_CODE'),
                env('TEST_SHIPPING_ORIGIN_STATE'),
                env('TEST_SHIPPING_ORIGIN_COUNTRY')
            ),
            new Address(
                'Ivan',
                'Mitrikeski',
                '',
                '100 City Centre Dr',
                '',
                'Mississauga',
                'L5B 2C9',
                'ON',
                'CA'
            ),
            new BoxCollection([
                new BoxMetric(20, 10, 5, 10, 5)
            ])
        );
    }

    /**
     * Test domestic rate for a single box
     *
     * @return void
     * @throws InvalidCredentials
     * @throws InvalidShipmentParameters
     * @throws PriceNotFound
     */
    public function test_ups_invalid_credentials_response()
    {
        $this->expectException(InvalidCredentials::class);

        $credentials = new ServiceUPSCredentials('1', '2', '3', '4');
        $serviceUPS = new ServiceUPS($credentials);

        $rates = $serviceUPS->rate(
            new Address(
                env('TEST_SHIPPING_ORIGIN_FIRST_NAME'),
                env('TEST_SHIPPING_ORIGIN_LAST_NAME'),
                env('TEST_SHIPPING_ORIGIN_COMPANY'),
                env('TEST_SHIPPING_ORIGIN_LINE1'),
                env('TEST_SHIPPING_ORIGIN_LINE2'),
                env('TEST_SHIPPING_ORIGIN_CITY'),
                env('TEST_SHIPPING_ORIGIN_POSTAL_CODE'),
                env('TEST_SHIPPING_ORIGIN_STATE'),
                env('TEST_SHIPPING_ORIGIN_COUNTRY')
            ),
            new Address(
                'Ivan',
                'Mitrikeski',
                '',
                '100 City Centre Dr',
                '',
                'Mississauga',
                'L5B 2C9',
                'ON',
                'CA'
            ),
            new BoxCollection([
                new BoxMetric(20, 10, 5, 1)
            ])
        );
    }

    /**
     * Test domestic rate for a single box
     *
     * @return void
     * @throws InvalidCredentials
     * @throws InvalidShipmentParameters
     * @throws PriceNotFound
     */
    public function test_ups_invalid_box_response()
    {
        $this->expectException(InvalidShipmentParameters::class);

        $credentials = new ServiceUPSCredentials(env('UPS_CLIENT_ID'), env('UPS_CLIENT_SECRET'), env('UPS_USER_ID'), env('UPS_ACCOUNT_NUMBER'), env('SHIPPING_SANDBOX'));
        $serviceUPS = new ServiceUPS($credentials);

        $rates = $serviceUPS->rate(
            new Address(
                env('TEST_SHIPPING_ORIGIN_FIRST_NAME'),
                env('TEST_SHIPPING_ORIGIN_LAST_NAME'),
                env('TEST_SHIPPING_ORIGIN_COMPANY'),
                env('TEST_SHIPPING_ORIGIN_LINE1'),
                env('TEST_SHIPPING_ORIGIN_LINE2'),
                env('TEST_SHIPPING_ORIGIN_CITY'),
                env('TEST_SHIPPING_ORIGIN_POSTAL_CODE'),
                env('TEST_SHIPPING_ORIGIN_STATE'),
                env('TEST_SHIPPING_ORIGIN_COUNTRY')
            ),
            new Address(
                'Ivan',
                'Mitrikeski',
                '',
                '100 City Centre Dr',
                '',
                'Mississauga',
                'L5B 2C9',
                'ON',
                'CA'
            ),
            new BoxCollection([
                new BoxMetric(200, 100, 500, 1)
            ])
        );
    }

    /**
     * Test domestic shipment for a single box
     *
     * @return void
     * @throws InvalidCredentials
     * @throws InvalidShipmentParameters
     * @throws ShipmentNotCreated
     */
    public function test_ups_domestic_shipment_response()
    {
        $credentials = new ServiceUPSCredentials(env('UPS_CLIENT_ID'), env('UPS_CLIENT_SECRET'), env('UPS_USER_ID'), env('UPS_ACCOUNT_NUMBER'), env('SHIPPING_SANDBOX'));
        $serviceUPS = new ServiceUPS($credentials);

        $result = $serviceUPS->ship(
            new ShipFrom(
                env('TEST_SHIPPING_ORIGIN_FIRST_NAME') . ' ' . env('TEST_SHIPPING_ORIGIN_LAST_NAME'),
                '',
                new Address(
                    env('TEST_SHIPPING_ORIGIN_FIRST_NAME'),
                    env('TEST_SHIPPING_ORIGIN_LAST_NAME'),
                    env('TEST_SHIPPING_ORIGIN_COMPANY'),
                    env('TEST_SHIPPING_ORIGIN_LINE1'),
                    env('TEST_SHIPPING_ORIGIN_LINE2'),
                    env('TEST_SHIPPING_ORIGIN_CITY'),
                    env('TEST_SHIPPING_ORIGIN_POSTAL_CODE'),
                    env('TEST_SHIPPING_ORIGIN_STATE'),
                    env('TEST_SHIPPING_ORIGIN_COUNTRY')
                ),
                new Phone('+1', '555', '1231234'),
                ''
            ),
            new ShipTo(
                'John Smith',
                '',
                new Address(
                    'Ivan',
                    'Mitrikeski',
                    '',
                    '100 City Centre Dr',
                    '',
                    'Mississauga',
                    'L5B 2C9',
                    'ON',
                    'CA'
                ),
                new Phone('+1', '555', '1231234')
            ),
            new BoxCollection([
                new BoxMetric(20, 10, 5, 1)
            ]),
            new ServiceProviderService('01', '')
        );

        $this->assertArrayHasKey(0, $result);
        $this->assertNotEmpty($result[0]->trackingNumber());
        $this->assertNotEmpty($result[0]->shipmentLabelData());
        $this->assertNotEmpty($result[0]->shipmentLabelDataFormat());
    }

    /**
     * Test US shipment for a single box
     *
     * @return void
     * @throws InvalidCredentials
     * @throws InvalidShipmentParameters
     * @throws ShipmentNotCreated
     */
    public function test_ups_domestic_shipping_us_shipment_response()
    {
        $credentials = new ServiceUPSCredentials(env('UPS_CLIENT_ID'), env('UPS_CLIENT_SECRET'), env('UPS_USER_ID'), env('UPS_ACCOUNT_NUMBER'), env('SHIPPING_SANDBOX'));
        $serviceUPS = new ServiceUPS($credentials);

        $result = $serviceUPS->ship(
            new ShipFrom(
                env('TEST_SHIPPING_ORIGIN_FIRST_NAME') . ' ' . env('TEST_SHIPPING_ORIGIN_LAST_NAME'),
                env('TEST_SHIPPING_ORIGIN_FIRST_NAME') . ' ' . env('TEST_SHIPPING_ORIGIN_LAST_NAME'),
                new Address(
                    env('TEST_SHIPPING_ORIGIN_FIRST_NAME'),
                    env('TEST_SHIPPING_ORIGIN_LAST_NAME'),
                    env('TEST_SHIPPING_ORIGIN_COMPANY'),
                    env('TEST_SHIPPING_ORIGIN_LINE1'),
                    env('TEST_SHIPPING_ORIGIN_LINE2'),
                    env('TEST_SHIPPING_ORIGIN_CITY'),
                    env('TEST_SHIPPING_ORIGIN_POSTAL_CODE'),
                    env('TEST_SHIPPING_ORIGIN_STATE'),
                    env('TEST_SHIPPING_ORIGIN_COUNTRY')
                ),
                new Phone('+1', '555', '1231234'),
                ''
            ),
            new ShipTo(
                'John Smith',
                'John Smith',
                new Address(
                    'Ivan',
                    'Mitrikeski',
                    '',
                    '1 Wall St',
                    '',
                    'New York',
                    '10005',
                    'NY',
                    'US'
                ),
                new Phone('+1', '555', '1231234')
            ),
            new BoxCollection([
                new BoxMetric(20, 10, 5, 1)
            ]),
            new ServiceProviderService('07', '')
        );

        $this->assertArrayHasKey(0, $result);
        $this->assertNotEmpty($result[0]->trackingNumber());
        $this->assertNotEmpty($result[0]->shipmentLabelData());
        $this->assertNotEmpty($result[0]->shipmentLabelDataFormat());
    }

    /**
     * Test International shipment for a single box
     *
     * @return void
     * @throws InvalidCredentials
     * @throws InvalidShipmentParameters
     * @throws ShipmentNotCreated
     */
    public function test_ups_domestic_shipping_international_shipment_response()
    {
        $credentials = new ServiceUPSCredentials(env('UPS_CLIENT_ID'), env('UPS_CLIENT_SECRET'), env('UPS_USER_ID'), env('UPS_ACCOUNT_NUMBER'), env('SHIPPING_SANDBOX'));
        $serviceUPS = new ServiceUPS($credentials);

        $result = $serviceUPS->ship(
            new ShipFrom(
                env('TEST_SHIPPING_ORIGIN_FIRST_NAME') . ' ' . env('TEST_SHIPPING_ORIGIN_LAST_NAME'),
                env('TEST_SHIPPING_ORIGIN_FIRST_NAME') . ' ' . env('TEST_SHIPPING_ORIGIN_LAST_NAME'),
                new Address(
                    env('TEST_SHIPPING_ORIGIN_FIRST_NAME'),
                    env('TEST_SHIPPING_ORIGIN_LAST_NAME'),
                    env('TEST_SHIPPING_ORIGIN_COMPANY'),
                    env('TEST_SHIPPING_ORIGIN_LINE1'),
                    env('TEST_SHIPPING_ORIGIN_LINE2'),
                    env('TEST_SHIPPING_ORIGIN_CITY'),
                    env('TEST_SHIPPING_ORIGIN_POSTAL_CODE'),
                    env('TEST_SHIPPING_ORIGIN_STATE'),
                    env('TEST_SHIPPING_ORIGIN_COUNTRY')
                ),
                new Phone('+1', '555', '1231234'),
                ''
            ),
            new ShipTo(
                'John Smith',
                'John Smith',
                new Address(
                    'Ivan',
                    'Mitrikeski',
                    '',
                    'Panoramastrasse 1A',
                    '',
                    'Berlin',
                    '10178',
                    '',
                    'DE'
                ),
                new Phone('+1', '555', '1231234')
            ),
            new BoxCollection([
                new BoxMetric(20, 10, 5, 1)
            ]),
            new ServiceProviderService('07', '')
        );

        $this->assertArrayHasKey(0, $result);
        $this->assertNotEmpty($result[0]->trackingNumber());
        $this->assertNotEmpty($result[0]->shipmentLabelData());
        $this->assertNotEmpty($result[0]->shipmentLabelDataFormat());
    }


    /**
     * Test domestic rate for multiple boxes
     *
     * @return void
     * @throws InvalidCredentials
     * @throws InvalidShipmentParameters
     * @throws ShipmentNotCreated
     */
    public function test_ups_domestic_shipping_shipments_response()
    {
        $credentials = new ServiceUPSCredentials(env('UPS_CLIENT_ID'), env('UPS_CLIENT_SECRET'), env('UPS_USER_ID'), env('UPS_ACCOUNT_NUMBER'), env('SHIPPING_SANDBOX'));
        $serviceUPS = new ServiceUPS($credentials);

        $result = $serviceUPS->ship(
            new ShipFrom(
                env('TEST_SHIPPING_ORIGIN_FIRST_NAME') . ' ' . env('TEST_SHIPPING_ORIGIN_LAST_NAME'),
                env('TEST_SHIPPING_ORIGIN_FIRST_NAME') . ' ' . env('TEST_SHIPPING_ORIGIN_LAST_NAME'),
                new Address(
                    env('TEST_SHIPPING_ORIGIN_FIRST_NAME'),
                    env('TEST_SHIPPING_ORIGIN_LAST_NAME'),
                    env('TEST_SHIPPING_ORIGIN_COMPANY'),
                    env('TEST_SHIPPING_ORIGIN_LINE1'),
                    env('TEST_SHIPPING_ORIGIN_LINE2'),
                    env('TEST_SHIPPING_ORIGIN_CITY'),
                    env('TEST_SHIPPING_ORIGIN_POSTAL_CODE'),
                    env('TEST_SHIPPING_ORIGIN_STATE'),
                    env('TEST_SHIPPING_ORIGIN_COUNTRY')
                ),
                new Phone('+1', '555', '1231234'),
                ''
            ),
            new ShipTo(
                'John Smith',
                'John Smith',
                new Address(
                    'Ivan',
                    'Mitrikeski',
                    '',
                    '100 City Centre Dr',
                    '',
                    'Mississauga',
                    'L5B 2C9',
                    'ON',
                    'CA'
                ),
                new Phone('+1', '555', '1231234')
            ),
            new BoxCollection([
                new BoxMetric(20, 10, 5, 1),
                new BoxMetric(20, 10, 5, 1),
            ]),
            new ServiceProviderService('01', '')
        );

        $this->assertArrayHasKey(0, $result);
        $this->assertArrayHasKey(1, $result);
        $this->assertNotEmpty($result[0]->trackingNumber());
        $this->assertNotEmpty($result[0]->shipmentLabelData());
        $this->assertNotEmpty($result[0]->shipmentLabelDataFormat());
        $this->assertNotEmpty($result[1]->trackingNumber());
        $this->assertNotEmpty($result[1]->shipmentLabelData());
        $this->assertNotEmpty($result[1]->shipmentLabelDataFormat());
    }

}
