<?php

namespace Tests\Unit;

use App\Services\Fs25\HostConnectionError;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class HostConnectionErrorTest extends TestCase
{
    public function test_formats_exception_chain_and_redacts_password(): void
    {
        $inner = new RuntimeException('login failed password=secret123');
        $outer = new RuntimeException('Host listing failed.', 0, $inner);

        $text = HostConnectionError::format($outer, 'Dateizugang FTPS demo:21');

        $this->assertStringContainsString('Dateizugang FTPS demo:21', $text);
        $this->assertStringContainsString('Host listing failed.', $text);
        $this->assertStringContainsString('password=***', $text);
        $this->assertStringNotContainsString('secret123', $text);
    }
}
