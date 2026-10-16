<?php

namespace Tests\Unit;

use App\Services\Fs25\DdsToPng;
use PHPUnit\Framework\TestCase;

final class DdsToPngTest extends TestCase
{
    public function test_converts_uncompressed_bgra_without_gd(): void
    {
        $width = 4;
        $height = 4;
        $header = str_repeat("\0", 128);
        $header = substr_replace($header, 'DDS ', 0, 4);
        $header = substr_replace($header, pack('V', 124), 4, 4); // dwSize
        $header = substr_replace($header, pack('V', $height), 12, 4);
        $header = substr_replace($header, pack('V', $width), 16, 4);
        $header = substr_replace($header, pack('V', 0), 80, 4); // pfFlags: no FOURCC
        $header = substr_replace($header, pack('V', 32), 88, 4); // rgbBitCount

        $pixels = '';
        for ($i = 0; $i < $width * $height; $i++) {
            $pixels .= "\x00\x80\xFF\xFF"; // BGRA → orange-ish
        }

        $png = DdsToPng::convert($header.$pixels);
        $this->assertNotNull($png);
        $this->assertSame("\x89PNG\r\n\x1a\n", substr((string) $png, 0, 8));
        $this->assertGreaterThan(64, strlen((string) $png));
    }

    public function test_converts_dx10_bc3_block(): void
    {
        $width = 4;
        $height = 4;
        $header = str_repeat("\0", 148);
        $header = substr_replace($header, 'DDS ', 0, 4);
        $header = substr_replace($header, pack('V', $height), 12, 4);
        $header = substr_replace($header, pack('V', $width), 16, 4);
        $header = substr_replace($header, pack('V', 0x4), 80, 4);
        $header = substr_replace($header, 'DX10', 84, 4);
        $header = substr_replace($header, pack('V', 77), 128, 4); // DXGI_FORMAT_BC3_UNORM

        $png = DdsToPng::convert($header.str_repeat("\0", 16));
        $this->assertNotNull($png);
        $this->assertSame("\x89PNG\r\n\x1a\n", substr((string) $png, 0, 8));
    }

    public function test_rejects_truncated_dx10_payload(): void
    {
        $header = str_repeat("\0", 148);
        $header = substr_replace($header, 'DDS ', 0, 4);
        $header = substr_replace($header, pack('V', 512), 12, 4);
        $header = substr_replace($header, pack('V', 512), 16, 4);
        $header = substr_replace($header, 'DX10', 84, 4);
        $header = substr_replace($header, pack('V', 77), 128, 4);
        $short = $header.str_repeat("\0", 64);

        $this->assertFalse(DdsToPng::hasPayload($short));
        $this->assertNull(DdsToPng::convert($short));

        $small = str_repeat("\0", 148);
        $small = substr_replace($small, 'DDS ', 0, 4);
        $small = substr_replace($small, pack('V', 4), 12, 4);
        $small = substr_replace($small, pack('V', 4), 16, 4);
        $small = substr_replace($small, 'DX10', 84, 4);
        $small = substr_replace($small, pack('V', 77), 128, 4);
        $this->assertTrue(DdsToPng::hasPayload($small.str_repeat("\0", 16)));
    }
}
