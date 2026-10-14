<?php

namespace Tests\Unit;

use App\Services\Fs25\ModKey;
use PHPUnit\Framework\TestCase;

class ModKeyTest extends TestCase
{
    public function test_from_filename_variants(): void
    {
        $this->assertSame(
            'FS25_Foo',
            ModKey::fromFilename('$moddir$FS25_Foo/vehicles/a.xml')
        );
        $this->assertSame(
            'FS25_Bar',
            ModKey::fromFilename('C:/Games/mods/FS25_Bar/vehicles/b.xml')
        );
        $this->assertSame(
            'FS25_Baz',
            ModKey::fromFilename('FS25_Baz/trailers/c.xml')
        );
        $this->assertNull(ModKey::fromFilename('data/vehicles/fendt/fendt.xml'));
    }
}
