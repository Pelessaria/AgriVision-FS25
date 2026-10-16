<?php

namespace App\Services\Fs25;

/**
 * Parse Giants savegame vehicles.xml (farmId, uniqueId, position, hours).
 */
final class VehiclesXmlParser
{
    /**
     * @return list<array{
     *   uniqueId: string|null,
     *   farmId: int|null,
     *   filename: string|null,
     *   nameHint: string|null,
     *   operatingTime: float|null,
     *   x: float|null,
     *   y: float|null,
     *   z: float|null
     * }>
     */
    public static function parse(string $xml): array
    {
        $xml = trim($xml);
        if ($xml === '') {
            return [];
        }

        $previous = libxml_use_internal_errors(true);
        $doc = simplexml_load_string($xml);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if ($doc === false) {
            return self::parseWithRegex($xml);
        }

        $vehicles = [];
        foreach ($doc->xpath('//vehicle') ?: [] as $node) {
            $attrs = $node->attributes();
            $farmId = self::intAttr($attrs, 'farmId');
            if ($farmId !== null && $farmId <= 0) {
                $farmId = null;
            }
            $filename = self::strAttr($attrs, 'filename');
            $uniqueId = self::strAttr($attrs, 'uniqueId');
            $operatingTime = self::floatAttr($attrs, 'operatingTime');
            [$x, $y, $z] = self::positionFromNode($node);

            $vehicles[] = [
                'uniqueId' => $uniqueId,
                'farmId' => $farmId,
                'filename' => $filename,
                'nameHint' => self::nameHintFromFilename($filename),
                'operatingTime' => $operatingTime,
                'x' => $x,
                'y' => $y,
                'z' => $z,
                'wear' => self::wearFromNode($node),
                'plate' => self::plateFromNode($node),
            ];
        }

        return $vehicles !== [] ? $vehicles : self::parseWithRegex($xml);
    }

    /**
     * @return list<array{
     *   uniqueId: string|null,
     *   farmId: int|null,
     *   filename: string|null,
     *   nameHint: string|null,
     *   operatingTime: float|null,
     *   x: float|null,
     *   y: float|null,
     *   z: float|null
     * }>
     */
    private static function parseWithRegex(string $xml): array
    {
        if (! preg_match_all('/<vehicle\b([^>]*)>(.*?)<\/vehicle>/is', $xml, $matches, PREG_SET_ORDER)) {
            return [];
        }

        $vehicles = [];
        foreach ($matches as $match) {
            $attrBlob = $match[1];
            $inner = $match[2];
            $farmId = null;
            if (preg_match('/\bfarmId="(\d+)"/i', $attrBlob, $m)) {
                $farmId = (int) $m[1];
                if ($farmId <= 0) {
                    $farmId = null;
                }
            }
            $uniqueId = null;
            if (preg_match('/\buniqueId="([^"]*)"/i', $attrBlob, $m)) {
                $uniqueId = trim(html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8')) ?: null;
            }
            $filename = null;
            if (preg_match('/\bfilename="([^"]*)"/i', $attrBlob, $m)) {
                $filename = trim(html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8')) ?: null;
            }
            $operatingTime = null;
            if (preg_match('/\boperatingTime="([^"]*)"/i', $attrBlob, $m) && is_numeric($m[1])) {
                $operatingTime = (float) $m[1];
            }
            [$x, $y, $z] = self::positionFromInnerXml($inner);
            $wear = null;
            if (preg_match('/<wearable\b[^>]*\bdamage="([^"]+)"/i', $inner, $m) && is_numeric($m[1])) {
                $wear = (int) max(0, min(100, round(((float) $m[1]) * 100)));
            }
            $plate = null;
            if (preg_match('/<licensePlate\b[^>]*\bcharacters="([^"]*)"/i', $inner, $m)) {
                $plate = trim(html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8')) ?: null;
            }
            $vehicles[] = [
                'uniqueId' => $uniqueId,
                'farmId' => $farmId,
                'filename' => $filename,
                'nameHint' => self::nameHintFromFilename($filename),
                'operatingTime' => $operatingTime,
                'x' => $x,
                'y' => $y,
                'z' => $z,
                'wear' => $wear,
                'plate' => $plate,
            ];
        }

        return $vehicles;
    }

    /**
     * @return array{0: float|null, 1: float|null, 2: float|null}
     */
    private static function positionFromNode(\SimpleXMLElement $node): array
    {
        foreach ($node->children() ?: [] as $child) {
            $name = strtolower($child->getName());
            if (! str_starts_with($name, 'component')) {
                continue;
            }
            $attrs = $child->attributes();
            $pos = self::strAttr($attrs, 'position');
            if ($pos !== null) {
                return self::parsePositionTriplet($pos);
            }
        }

        return [null, null, null];
    }

    /**
     * @return array{0: float|null, 1: float|null, 2: float|null}
     */
    private static function positionFromInnerXml(string $inner): array
    {
        if (preg_match('/<component\d*\b[^>]*\bposition="([^"]+)"/i', $inner, $m)) {
            return self::parsePositionTriplet($m[1]);
        }

        return [null, null, null];
    }

    /**
     * @return array{0: float|null, 1: float|null, 2: float|null}
     */
    private static function parsePositionTriplet(string $raw): array
    {
        $parts = preg_split('/\s+/', trim($raw)) ?: [];
        if (count($parts) < 3) {
            return [null, null, null];
        }
        if (! is_numeric($parts[0]) || ! is_numeric($parts[1]) || ! is_numeric($parts[2])) {
            return [null, null, null];
        }

        return [(float) $parts[0], (float) $parts[1], (float) $parts[2]];
    }

    private static function wearFromNode(\SimpleXMLElement $node): ?int
    {
        $nodes = $node->xpath('./wearable');
        if (! is_array($nodes) || $nodes === []) {
            return null;
        }
        $damage = self::floatAttr($nodes[0]->attributes(), 'damage');
        if ($damage === null) {
            return null;
        }

        return (int) max(0, min(100, round($damage * 100)));
    }

    private static function plateFromNode(\SimpleXMLElement $node): ?string
    {
        $direct = self::strAttr($node->attributes(), 'licensePlate');
        if ($direct !== null) {
            return $direct;
        }
        $nodes = $node->xpath('./licensePlate');
        if (! is_array($nodes) || $nodes === []) {
            return null;
        }
        $attrs = $nodes[0]->attributes();

        return self::strAttr($attrs, 'characters') ?? self::strAttr($attrs, 'text');
    }

    public static function nameHintFromFilename(?string $filename): ?string
    {
        if ($filename === null || $filename === '') {
            return null;
        }
        $base = basename(str_replace('\\', '/', $filename));
        $base = preg_replace('/\.xml$/i', '', $base) ?? $base;
        $base = trim($base);

        return $base === '' ? null : $base;
    }

    private static function strAttr(?\SimpleXMLElement $attrs, string $key): ?string
    {
        if ($attrs === null || ! isset($attrs[$key])) {
            return null;
        }
        $value = trim(html_entity_decode((string) $attrs[$key], ENT_QUOTES | ENT_HTML5, 'UTF-8'));

        return $value === '' ? null : $value;
    }

    private static function intAttr(?\SimpleXMLElement $attrs, string $key): ?int
    {
        if ($attrs === null || ! isset($attrs[$key])) {
            return null;
        }
        $raw = (string) $attrs[$key];
        if (! is_numeric($raw)) {
            return null;
        }

        return (int) $raw;
    }

    private static function floatAttr(?\SimpleXMLElement $attrs, string $key): ?float
    {
        if ($attrs === null || ! isset($attrs[$key])) {
            return null;
        }
        $raw = (string) $attrs[$key];
        if (! is_numeric($raw)) {
            return null;
        }

        return (float) $raw;
    }
}
