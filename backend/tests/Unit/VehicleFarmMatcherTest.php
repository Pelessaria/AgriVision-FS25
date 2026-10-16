<?php

namespace Tests\Unit;

use App\Services\Fs25\ConnectorSnapshotService;
use App\Services\Fs25\ConnectorVehiclesParser;
use App\Services\Fs25\VehicleFarmMatcher;
use App\Services\Fs25\VehiclesXmlParser;
use PHPUnit\Framework\TestCase;

class VehicleFarmMatcherTest extends TestCase
{
    public function test_parses_vehicles_xml_farm_and_position(): void
    {
        $xml = <<<'XML'
        <vehicles>
          <vehicle filename="data/vehicles/fendt/fendt728.xml" uniqueId="v1" farmId="1" operatingTime="12.5">
            <component1 position="100.0 40.0 -200.0" />
          </vehicle>
          <vehicle filename="data/vehicles/trailer/fliegl.xml" uniqueId="v2" farmId="2" operatingTime="3">
            <component1 position="10 5 10" />
          </vehicle>
        </vehicles>
        XML;

        $parsed = VehiclesXmlParser::parse($xml);
        $this->assertCount(2, $parsed);
        $this->assertSame(1, $parsed[0]['farmId']);
        $this->assertSame('v1', $parsed[0]['uniqueId']);
        $this->assertSame(100.0, $parsed[0]['x']);
        $this->assertSame(-200.0, $parsed[0]['z']);
        $this->assertSame('fendt728', $parsed[0]['nameHint']);
    }

    public function test_merge_inventory_is_savegame_first_with_live_position(): void
    {
        $stats = [
            ['name' => 'Fendt 728', 'category' => 'tractors', 'type' => 'tractor', 'x' => 101.0, 'y' => 40.0, 'z' => -199.0],
            ['name' => 'Fliegl', 'category' => 'trailers', 'type' => 'trailer', 'x' => 10.5, 'y' => 5.0, 'z' => 10.2],
        ];
        $save = [
            [
                'uniqueId' => 'v1',
                'farmId' => 1,
                'filename' => 'data/vehicles/fendt/fendt728.xml',
                'nameHint' => 'fendt728',
                'operatingTime' => 12.5,
                'x' => 100.0,
                'y' => 40.0,
                'z' => -200.0,
            ],
            [
                'uniqueId' => 'v2',
                'farmId' => 2,
                'filename' => 'data/vehicles/trailer/fliegl.xml',
                'nameHint' => 'fliegl',
                'operatingTime' => 3.0,
                'x' => 10.0,
                'y' => 5.0,
                'z' => 10.0,
            ],
            [
                'uniqueId' => 'v3',
                'farmId' => 1,
                'filename' => 'data/vehicles/tools/cultivator.xml',
                'nameHint' => 'cultivator',
                'operatingTime' => 1.0,
                'x' => 50.0,
                'y' => 0.0,
                'z' => 50.0,
            ],
        ];

        $merged = VehicleFarmMatcher::mergeInventory($stats, $save);
        $this->assertCount(3, $merged);
        $this->assertSame(1, $merged[0]['farmId']);
        $this->assertSame(101.0, $merged[0]['x']); // live stats position
        $this->assertSame('v1', $merged[0]['uniqueId']);
        $this->assertSame(1, $merged[2]['farmId']); // unmatched save vehicle kept
        $this->assertSame(50.0, $merged[2]['x']);
    }

    public function test_connector_meta_overrides_savegame_by_unique_id(): void
    {
        $save = [[
            'uniqueId' => 'v1',
            'farmId' => 1,
            'filename' => 'data/a.xml',
            'nameHint' => 'a',
            'operatingTime' => 1.0,
            'x' => 1.0,
            'y' => 0.0,
            'z' => 1.0,
            'modKey' => null,
        ]];
        $connector = [[
            'uniqueId' => 'v1',
            'farmId' => 1,
            'filename' => 'data/a.xml',
            'nameHint' => 'a',
            'name' => 'Fendt Live',
            'category' => 'tractor',
            'operatingTime' => 9.0,
            'modKey' => 'hash123',
            'x' => 2.0,
            'y' => 0.0,
            'z' => 2.0,
        ]];

        $merged = ConnectorSnapshotService::mergeMeta($connector, $save);
        $this->assertCount(1, $merged);
        $this->assertSame('hash123', $merged[0]['modKey']);
        $this->assertSame('Fendt Live', $merged[0]['name']);
        $this->assertSame(9.0, $merged[0]['operatingTime']);
    }

    public function test_parses_connector_vehicles_full(): void
    {
        $xml = '<vehiclesFull><vehicle uniqueId="u1" farmId="2" filename="data/vehicles/fendt/x.xml" name="Tool" storeKey="data_vehicles_fendt_x" modKey="k1" operatingTime="4" x="1" y="2" z="3" /></vehiclesFull>';
        $parsed = ConnectorVehiclesParser::parse($xml);
        $this->assertCount(1, $parsed);
        $this->assertSame(2, $parsed[0]['farmId']);
        $this->assertSame('data_vehicles_fendt_x', $parsed[0]['storeKey']);
        $this->assertSame('k1', $parsed[0]['modKey']);
        $this->assertSame('Tool', $parsed[0]['name']);
    }

    public function test_parses_connector_fluids_wear_and_plate(): void
    {
        $xml = '<vehiclesFull><vehicle uniqueId="u1" fuel="660" fuelCapacity="740" defLevel="18" defCapacity="40" wear="10" plate="EF NW 1" /></vehiclesFull>';
        $parsed = ConnectorVehiclesParser::parse($xml);
        $this->assertSame(660.0, $parsed[0]['fuel']);
        $this->assertSame(740.0, $parsed[0]['fuelCapacity']);
        $this->assertSame(18.0, $parsed[0]['defLevel']);
        $this->assertSame(40.0, $parsed[0]['defCapacity']);
        $this->assertSame(10, $parsed[0]['wear']);
        $this->assertSame('EF NW 1', $parsed[0]['plate']);
    }

    public function test_parses_savegame_wear_and_plate(): void
    {
        $xml = <<<'XML'
        <vehicles>
          <vehicle filename="data/vehicles/fendt/fendt728.xml" uniqueId="v1" farmId="1">
            <wearable damage="0.12" />
            <licensePlate characters="EF NW 701" />
          </vehicle>
        </vehicles>
        XML;
        $parsed = VehiclesXmlParser::parse($xml);
        $this->assertSame(12, $parsed[0]['wear']);
        $this->assertSame('EF NW 701', $parsed[0]['plate']);
    }

    public function test_parses_savegame_fuel_and_def(): void
    {
        $xml = <<<'XML'
        <vehicles>
          <vehicle filename="data/vehicles/fendt/fendt728.xml" uniqueId="v1" farmId="1">
            <fillUnit>
              <unit index="1" fillType="DIESEL" fillLevel="420" capacity="600"/>
              <unit index="2" fillType="DEF" fillLevel="18" capacity="40"/>
            </fillUnit>
          </vehicle>
        </vehicles>
        XML;
        $parsed = VehiclesXmlParser::parse($xml);
        $this->assertSame(420.0, $parsed[0]['fuel']);
        $this->assertSame(600.0, $parsed[0]['fuelCapacity']);
        $this->assertSame(18.0, $parsed[0]['defLevel']);
        $this->assertSame(40.0, $parsed[0]['defCapacity']);
    }
}
