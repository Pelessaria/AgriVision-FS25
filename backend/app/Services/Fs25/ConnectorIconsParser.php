<?php

namespace App\Services\Fs25;

/**
 * Parse connector state/icons_manifest.xml (store icons; legacy <mod> still accepted).
 */
final class ConnectorIconsParser
{
    /**
     * @return list<array{key: string, name: string|null, author: string|null, version: string|null, iconFile: string, kind: string}>
     */
    public static function parse(string $xml): array
    {
        $xml = trim($xml);
        if ($xml === '' || ! preg_match_all('/<(store|mod)\b([^>]*)\/?>/i', $xml, $matches, PREG_SET_ORDER)) {
            return [];
        }

        $entries = [];
        foreach ($matches as $match) {
            $kind = strtolower($match[1]);
            $attrBlob = $match[2];
            $key = self::attr($attrBlob, 'key');
            $iconFile = self::attr($attrBlob, 'iconFile');
            if ($key === null || $iconFile === null) {
                continue;
            }
            $entries[] = [
                'key' => $key,
                'name' => self::attr($attrBlob, 'name') ?? self::attr($attrBlob, 'filename'),
                'author' => self::attr($attrBlob, 'author'),
                'version' => self::attr($attrBlob, 'version'),
                'iconFile' => $iconFile,
                'kind' => $kind === 'store' ? 'store' : 'mod',
            ];
        }

        return $entries;
    }

    private static function attr(string $blob, string $key): ?string
    {
        if (! preg_match('/\b'.preg_quote($key, '/').'="([^"]*)"/i', $blob, $m)) {
            return null;
        }
        $value = trim(html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8'));

        return $value === '' ? null : $value;
    }
}
