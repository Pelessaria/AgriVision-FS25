<?php

namespace Tests\Unit;

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

    public function test_matches_stats_vehicle_to_savegame_farm_by_position(): void
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
        ];

        $enriched = VehicleFarmMatcher::enrich($stats, $save);
        $this->assertSame(1, $enriched[0]['farmId']);
        $this->assertSame('v1', $enriched[0]['uniqueId']);
        $this->assertSame(12.5, $enriched[0]['hours']);
        $this->assertSame(2, $enriched[1]['farmId']);
    }

    public function test_does_not_match_distant_vehicles(): void
    {
        $stats = [
            ['name' => 'Fendt', 'category' => 'tractors', 'type' => 'tractor', 'x' => 0.0, 'y' => 0.0, 'z' => 0.0],
        ];
        $save = [
            [
                'uniqueId' => 'v1',
                'farmId' => 1,
                'filename' => 'data/vehicles/other/other.xml',
                'nameHint' => 'other',
                'operatingTime' => 1.0,
                'x' => 500.0,
                'y' => 0.0,
                'z' => 500.0,
            ],
        ];

        $enriched = VehicleFarmMatcher::enrich($stats, $save);
        $this->assertNull($enriched[0]['farmId']);
    }
}
