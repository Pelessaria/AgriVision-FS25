<?php

namespace App\Services\Fs25;

/**
 * Parse connector state/icons_manifest.xml.
 */
final class ConnectorIconsParser
{
    /**
     * @return list<array{key: string, name: string|null, author: string|null, version: string|null, iconFile: string}>
     */
    public static function parse(string $xml): array
    {
        $xml = trim($xml);
        if ($xml === '' || ! preg_match_all('/<mod\b([^>]*)\/?>/i', $xml, $matches)) {
            return [];
        }

        $mods = [];
        foreach ($matches[1] as $attrBlob) {
            $key = self::attr($attrBlob, 'key');
            $iconFile = self::attr($attrBlob, 'iconFile');
            if ($key === null || $iconFile === null) {
                continue;
            }
            $mods[] = [
                'key' => $key,
                'name' => self::attr($attrBlob, 'name'),
                'author' => self::attr($attrBlob, 'author'),
                'version' => self::attr($attrBlob, 'version'),
                'iconFile' => $iconFile,
            ];
        }

        return $mods;
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
