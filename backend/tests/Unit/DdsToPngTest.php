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
}
