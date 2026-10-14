<?php

namespace App\Services\Fs25;

/**
 * Minimal DDS → PNG for FS25 store icons (DXT1 / DXT5 / uncompressed BGRA).
 */
final class DdsToPng
{
    public static function convert(string $ddsBytes): ?string
    {
        if (strlen($ddsBytes) < 128 || substr($ddsBytes, 0, 4) !== 'DDS ') {
            return null;
        }

        $height = unpack('V', substr($ddsBytes, 12, 4))[1] ?? 0;
        $width = unpack('V', substr($ddsBytes, 16, 4))[1] ?? 0;
        if ($width < 1 || $height < 1 || $width > 2048 || $height > 2048) {
            return null;
        }

        $pfFlags = unpack('V', substr($ddsBytes, 80, 4))[1] ?? 0;
        $fourCc = substr($ddsBytes, 84, 4);
        $rgbBitCount = unpack('V', substr($ddsBytes, 88, 4))[1] ?? 0;
        $data = substr($ddsBytes, 128);

        $rgba = null;
        if (($pfFlags & 0x4) !== 0) {
            $rgba = match ($fourCc) {
                'DXT1' => self::decodeDxt1($data, $width, $height),
                'DXT3' => self::decodeDxt3($data, $width, $height),
                'DXT5' => self::decodeDxt5($data, $width, $height),
                default => null,
            };
        } elseif ($rgbBitCount === 32) {
            $rgba = self::decodeBgra($data, $width, $height);
        }

        if ($rgba === null || ! function_exists('imagecreatetruecolor')) {
            return null;
        }

        $img = imagecreatetruecolor($width, $height);
        if ($img === false) {
            return null;
        }
        imagesavealpha($img, true);
        $transparent = imagecolorallocatealpha($img, 0, 0, 0, 127);
        imagefill($img, 0, 0, $transparent);

        $i = 0;
        for ($y = 0; $y < $height; $y++) {
            for ($x = 0; $x < $width; $x++) {
                $r = ord($rgba[$i++]);
                $g = ord($rgba[$i++]);
                $b = ord($rgba[$i++]);
                $a = ord($rgba[$i++]);
                $alpha = 127 - (int) round(($a / 255) * 127);
                $color = imagecolorallocatealpha($img, $r, $g, $b, $alpha);
                imagesetpixel($img, $x, $y, $color);
            }
        }

        ob_start();
        imagepng($img);
        imagedestroy($img);
        $png = ob_get_clean();

        return is_string($png) && $png !== '' ? $png : null;
    }

    private static function decodeBgra(string $data, int $width, int $height): ?string
    {
        $need = $width * $height * 4;
        if (strlen($data) < $need) {
            return null;
        }
        $out = '';
        for ($i = 0; $i < $need; $i += 4) {
            $b = $data[$i];
            $g = $data[$i + 1];
            $r = $data[$i + 2];
            $a = $data[$i + 3];
            $out .= $r.$g.$b.$a;
        }

        return $out;
    }

    private static function decodeDxt1(string $data, int $width, int $height): ?string
    {
        return self::decodeDxt($data, $width, $height, false);
    }

    private static function decodeDxt3(string $data, int $width, int $height): ?string
    {
        return self::decodeDxt($data, $width, $height, true, true);
    }

    private static function decodeDxt5(string $data, int $width, int $height): ?string
    {
        return self::decodeDxt($data, $width, $height, true, false);
    }

    private static function decodeDxt(string $data, int $width, int $height, bool $hasAlpha, bool $explicitAlpha = false): ?string
    {
        $blockSize = $hasAlpha ? 16 : 8;
        $blocksX = (int) ceil($width / 4);
        $blocksY = (int) ceil($height / 4);
        $need = $blocksX * $blocksY * $blockSize;
        if (strlen($data) < $need) {
            return null;
        }

        $pixels = array_fill(0, $width * $height * 4, "\0");
        $offset = 0;

        for ($by = 0; $by < $blocksY; $by++) {
            for ($bx = 0; $bx < $blocksX; $bx++) {
                $block = substr($data, $offset, $blockSize);
                $offset += $blockSize;

                $alpha = array_fill(0, 16, 255);
                $colorOffset = 0;
                if ($hasAlpha) {
                    if ($explicitAlpha) {
                        for ($i = 0; $i < 16; $i++) {
                            $nibble = ord($block[(int) floor($i / 2)]);
                            $alpha[$i] = (($i % 2) === 0 ? ($nibble & 0x0F) : (($nibble >> 4) & 0x0F)) * 17;
                        }
                    } else {
                        $a0 = ord($block[0]);
                        $a1 = ord($block[1]);
                        $bits = 0;
                        for ($i = 0; $i < 6; $i++) {
                            $bits |= ord($block[2 + $i]) << (8 * $i);
                        }
                        $table = [$a0, $a1];
                        if ($a0 > $a1) {
                            for ($i = 1; $i <= 6; $i++) {
                                $table[$i + 1] = (int) round(((7 - $i) * $a0 + $i * $a1) / 7);
                            }
                        } else {
                            for ($i = 1; $i <= 4; $i++) {
                                $table[$i + 1] = (int) round(((5 - $i) * $a0 + $i * $a1) / 5);
                            }
                            $table[6] = 0;
                            $table[7] = 255;
                        }
                        for ($i = 0; $i < 16; $i++) {
                            $code = ($bits >> (3 * $i)) & 0x7;
                            $alpha[$i] = $table[$code];
                        }
                    }
                    $colorOffset = 8;
                }

                $c0 = unpack('v', substr($block, $colorOffset, 2))[1];
                $c1 = unpack('v', substr($block, $colorOffset + 2, 2))[1];
                $colors = [
                    self::rgb565($c0),
                    self::rgb565($c1),
                ];
                if ($c0 > $c1 || $hasAlpha) {
                    $colors[2] = self::lerpRgb($colors[0], $colors[1], 1 / 3);
                    $colors[3] = self::lerpRgb($colors[0], $colors[1], 2 / 3);
                } else {
                    $colors[2] = self::lerpRgb($colors[0], $colors[1], 0.5);
                    $colors[3] = [0, 0, 0];
                }

                $indices = unpack('V', substr($block, $colorOffset + 4, 4))[1];
                for ($i = 0; $i < 16; $i++) {
                    $px = $bx * 4 + ($i % 4);
                    $py = $by * 4 + intdiv($i, 4);
                    if ($px >= $width || $py >= $height) {
                        continue;
                    }
                    $code = ($indices >> (2 * $i)) & 0x3;
                    $rgb = $colors[$code];
                    $a = (! $hasAlpha && $c0 <= $c1 && $code === 3) ? 0 : $alpha[$i];
                    $idx = ($py * $width + $px) * 4;
                    $pixels[$idx] = chr($rgb[0]);
                    $pixels[$idx + 1] = chr($rgb[1]);
                    $pixels[$idx + 2] = chr($rgb[2]);
                    $pixels[$idx + 3] = chr($a);
                }
            }
        }

        return implode('', $pixels);
    }

    /** @return array{0:int,1:int,2:int} */
    private static function rgb565(int $c): array
    {
        $r = (($c >> 11) & 0x1F) * 255 / 31;
        $g = (($c >> 5) & 0x3F) * 255 / 63;
        $b = ($c & 0x1F) * 255 / 31;

        return [(int) round($r), (int) round($g), (int) round($b)];
    }

    /**
     * @param  array{0:int,1:int,2:int}  $a
     * @param  array{0:int,1:int,2:int}  $b
     * @return array{0:int,1:int,2:int}
     */
    private static function lerpRgb(array $a, array $b, float $t): array
    {
        return [
            (int) round($a[0] + ($b[0] - $a[0]) * $t),
            (int) round($a[1] + ($b[1] - $a[1]) * $t),
            (int) round($a[2] + ($b[2] - $a[2]) * $t),
        ];
    }
}
