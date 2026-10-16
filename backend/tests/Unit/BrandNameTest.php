<?php

namespace Tests\Unit;

use App\Services\Fs25\BrandName;
use PHPUnit\Framework\TestCase;

class BrandNameTest extends TestCase
{
    public function test_prefers_connector_brand_and_canonicalizes_it(): void
    {
        $this->assertSame(
            'John Deere',
            BrandName::resolve('JOHNDEERE', 'data/vehicles/claas/x.xml', 'FS25_CLAAS_X', 'Xerion')
        );
    }

    public function test_reads_brand_from_mod_folder_and_base_game_path(): void
    {
        $this->assertSame(
            'CLAAS',
            BrandName::resolve(null, 'C:/mods/FS25_CLAAS_Xerion_3000_series/xerion_tracvc.xml', 'FS25_CLAAS_Xerion_3000_series', 'Xerion 3000 series')
        );
        $this->assertSame(
            'Fendt',
            BrandName::resolve(null, 'data/vehicles/fendt/fendt728.xml', null, '700 Vario')
        );
        $this->assertSame(
            'Weidemann',
            BrandName::resolve(null, null, 'FS25_Weidemann1140', '1140')
        );
        $this->assertSame(
            'Meiller',
            BrandName::resolve(null, null, 'FS25_MR_Meiller_Trailer_Pack', 'Anhänger')
        );
    }

    public function test_short_brands_do_not_match_inside_longer_words(): void
    {
        $this->assertNull(BrandName::resolve(null, 'C:/Users/Administrator/Documents/manual.xml', null, 'Anhänger'));
        $this->assertSame('MAN', BrandName::resolve(null, null, 'FS25_MAN_TGS', '19.403'));
    }
}
