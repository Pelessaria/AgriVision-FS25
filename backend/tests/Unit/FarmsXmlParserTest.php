<?php

namespace Tests\Unit;

use App\Services\Fs25\FarmsXmlParser;
use PHPUnit\Framework\TestCase;

class FarmsXmlParserTest extends TestCase
{
    public function test_parses_farm_attributes(): void
    {
        $xml = <<<'XML'
        <farms>
          <farm farmId="1" name="Hof Alpha" money="1250000" />
          <farm farmId="2" name="Hof Beta" money="0" />
        </farms>
        XML;

        $farms = FarmsXmlParser::parse($xml);

        $this->assertCount(2, $farms);
        $this->assertSame(1, $farms[0]['gameFarmId']);
        $this->assertSame('Hof Alpha', $farms[0]['name']);
        $this->assertSame(1250000, $farms[0]['money']);
        $this->assertSame(2, $farms[1]['gameFarmId']);
    }
}
