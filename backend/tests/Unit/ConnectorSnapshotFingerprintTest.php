<?php

namespace Tests\Unit;

use App\Services\Fs25\ConnectorSnapshotService;
use PHPUnit\Framework\TestCase;

final class ConnectorSnapshotFingerprintTest extends TestCase
{
    public function test_generation_and_timestamp_do_not_count_as_a_change(): void
    {
        $a = <<<'XML'
<?xml version="1.0" encoding="utf-8"?>
<vehiclesFull schema="1" generation="3" writtenAtUtc="2026-01-01T00:00:00Z" kind="full">
  <vehicle uniqueId="a" storeKey="tractor" />
</vehiclesFull>
XML;
        $b = <<<'XML'
<?xml version="1.0" encoding="utf-8"?>
<vehiclesFull schema="1" generation="99" writtenAtUtc="2026-12-31T23:59:59Z" kind="full">
  <vehicle uniqueId="a" storeKey="tractor" />
</vehiclesFull>
XML;
        $c = <<<'XML'
<?xml version="1.0" encoding="utf-8"?>
<vehiclesFull schema="1" generation="99" writtenAtUtc="2026-12-31T23:59:59Z" kind="full">
  <vehicle uniqueId="b" storeKey="trailer" />
</vehiclesFull>
XML;

        $this->assertSame(
            ConnectorSnapshotService::stableXmlFingerprint($a),
            ConnectorSnapshotService::stableXmlFingerprint($b)
        );
        $this->assertNotSame(
            ConnectorSnapshotService::stableXmlFingerprint($a),
            ConnectorSnapshotService::stableXmlFingerprint($c)
        );
    }
}
