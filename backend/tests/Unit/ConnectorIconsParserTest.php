<?php

namespace Tests\Unit;

use App\Services\Fs25\ConnectorIconsParser;
use PHPUnit\Framework\TestCase;

final class ConnectorIconsParserTest extends TestCase
{
    public function test_parses_store_rows_from_connector_manifest(): void
    {
        $xml = <<<'XML'
<?xml version="1.0" encoding="utf-8"?>
<iconsManifest schema="1" generation="2" writtenAtUtc="2026-10-15T00:00:00Z" scan="vehicles" kind="store">
  <store key="data_vehicles_fendt_x" filename="data/vehicles/fendt/x.xml" iconFile="icons/store/data_vehicles_fendt_x.dds" />
  <store key="ator_documents_my_games_farmingsimulator2025_mods_fs25_poettingermowerpack_novacat301_novacat301" filename="C:/Users/Administrator/Documents/My Games/FarmingSimulator2025/mods/FS25_poettingerMowerPack/novacat301/novacat301.xml" iconFile="icons/store/ator_documents_my_games_farmingsimulator2025_mods_fs25_poettingermowerpack_novacat301_novacat301.dds" />
</iconsManifest>
XML;

        $parsed = ConnectorIconsParser::parse($xml);

        $this->assertCount(2, $parsed);
        $this->assertSame('store', $parsed[0]['kind']);
        $this->assertSame('icons/store/data_vehicles_fendt_x.dds', $parsed[0]['iconFile']);
        $this->assertSame(
            'icons/store/ator_documents_my_games_farmingsimulator2025_mods_fs25_poettingermowerpack_novacat301_novacat301.dds',
            $parsed[1]['iconFile']
        );
    }
}
