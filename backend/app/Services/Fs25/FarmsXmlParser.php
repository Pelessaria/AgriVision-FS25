<?php

namespace App\Services\Fs25;

/**
 * Parse Giants farms.xml into farm stubs (id, name, money).
 */
final class FarmsXmlParser
{
    /**
     * @return list<array{gameFarmId: int, name: string|null, money: int|null}>
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

        $farms = [];
        foreach ($doc->xpath('//farm') ?: [] as $node) {
            $attrs = $node->attributes();
            $id = self::intAttr($attrs, 'farmId') ?? self::intAttr($attrs, 'id');
            if ($id === null || $id <= 0) {
                continue;
            }
            $name = self::strAttr($attrs, 'name');
            $money = self::intAttr($attrs, 'money');
            if ($money === null && isset($node->money)) {
                $money = self::toInt((string) $node->money);
            }
            $farms[] = [
                'gameFarmId' => $id,
                'name' => $name,
                'money' => $money,
            ];
        }

        if ($farms === []) {
            return self::parseWithRegex($xml);
        }

        usort($farms, static fn (array $a, array $b): int => $a['gameFarmId'] <=> $b['gameFarmId']);

        return $farms;
    }

    /**
     * @return list<array{gameFarmId: int, name: string|null, money: int|null}>
     */
    private static function parseWithRegex(string $xml): array
    {
        $farms = [];
        if (! preg_match_all('/<farm\b([^>]*)>/i', $xml, $matches)) {
            return [];
        }
        foreach ($matches[1] as $attrBlob) {
            $id = null;
            $name = null;
            $money = null;
            if (preg_match('/\bfarmId="(\d+)"/i', $attrBlob, $m) || preg_match('/\bid="(\d+)"/i', $attrBlob, $m)) {
                $id = (int) $m[1];
            }
            if (preg_match('/\bname="([^"]*)"/i', $attrBlob, $m)) {
                $name = html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
                $name = trim($name) === '' ? null : trim($name);
            }
            if (preg_match('/\bmoney="(-?\d+)"/i', $attrBlob, $m)) {
                $money = (int) $m[1];
            }
            if ($id === null || $id <= 0) {
                continue;
            }
            $farms[$id] = [
                'gameFarmId' => $id,
                'name' => $name,
                'money' => $money,
            ];
        }

        ksort($farms);

        return array_values($farms);
    }

    private static function intAttr(mixed $attrs, string $key): ?int
    {
        if ($attrs === null || ! isset($attrs[$key])) {
            return null;
        }

        return self::toInt((string) $attrs[$key]);
    }

    private static function strAttr(mixed $attrs, string $key): ?string
    {
        if ($attrs === null || ! isset($attrs[$key])) {
            return null;
        }
        $value = trim(html_entity_decode((string) $attrs[$key], ENT_QUOTES | ENT_HTML5, 'UTF-8'));

        return $value === '' ? null : $value;
    }

    private static function toInt(string $value): ?int
    {
        $value = trim($value);
        if ($value === '' || ! is_numeric($value)) {
            return null;
        }

        return (int) $value;
    }
}
