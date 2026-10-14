<?php

namespace App\Services\Fs25;

/**
 * Attach savegame farmId / hours to live stats vehicles (no uniqueId in stats JSON).
 */
final class VehicleFarmMatcher
{
    /** Max horizontal metres between stats position and savegame component. */
    private const MAX_DISTANCE = 80.0;

    /**
     * @param  list<array{name: string|null, category: string|null, type: string|null, x: float|null, y: float|null, z: float|null}>  $statsVehicles
     * @param  list<array{uniqueId: string|null, farmId: int|null, filename: string|null, nameHint: string|null, operatingTime: float|null, x: float|null, y: float|null, z: float|null}>  $saveVehicles
     * @return list<array{name: string|null, category: string|null, type: string|null, x: float|null, y: float|null, z: float|null, farmId: int|null, uniqueId: string|null, hours: float|null}>
     */
    public static function enrich(array $statsVehicles, array $saveVehicles): array
    {
        if ($statsVehicles === []) {
            return [];
        }

        $pairs = [];
        foreach ($statsVehicles as $si => $stats) {
            foreach ($saveVehicles as $vi => $save) {
                $score = self::score($stats, $save);
                if ($score === null) {
                    continue;
                }
                $pairs[] = ['si' => $si, 'vi' => $vi, 'score' => $score];
            }
        }

        usort($pairs, static fn (array $a, array $b): int => $b['score'] <=> $a['score']);

        $usedStats = [];
        $usedSave = [];
        $match = [];
        foreach ($pairs as $pair) {
            $si = $pair['si'];
            $vi = $pair['vi'];
            if (isset($usedStats[$si]) || isset($usedSave[$vi])) {
                continue;
            }
            $usedStats[$si] = true;
            $usedSave[$vi] = true;
            $match[$si] = $saveVehicles[$vi];
        }

        $out = [];
        foreach ($statsVehicles as $si => $stats) {
            $save = $match[$si] ?? null;
            $out[] = [
                'name' => $stats['name'] ?? null,
                'category' => $stats['category'] ?? null,
                'type' => $stats['type'] ?? null,
                'x' => $stats['x'] ?? null,
                'y' => $stats['y'] ?? null,
                'z' => $stats['z'] ?? null,
                'farmId' => $save['farmId'] ?? null,
                'uniqueId' => $save['uniqueId'] ?? null,
                'hours' => isset($save['operatingTime']) && is_numeric($save['operatingTime'])
                    ? round((float) $save['operatingTime'], 1)
                    : null,
            ];
        }

        return $out;
    }

    /**
     * @param  array{name: string|null, category: string|null, type: string|null, x: float|null, y: float|null, z: float|null}  $stats
     * @param  array{uniqueId: string|null, farmId: int|null, filename: string|null, nameHint: string|null, operatingTime: float|null, x: float|null, y: float|null, z: float|null}  $save
     */
    private static function score(array $stats, array $save): ?float
    {
        $hasStatsPos = isset($stats['x'], $stats['z']) && $stats['x'] !== null && $stats['z'] !== null;
        $hasSavePos = isset($save['x'], $save['z']) && $save['x'] !== null && $save['z'] !== null;

        $distanceScore = 0.0;
        if ($hasStatsPos && $hasSavePos) {
            $dx = (float) $stats['x'] - (float) $save['x'];
            $dz = (float) $stats['z'] - (float) $save['z'];
            $dist = sqrt(($dx * $dx) + ($dz * $dz));
            if ($dist > self::MAX_DISTANCE) {
                return null;
            }
            $distanceScore = (self::MAX_DISTANCE - $dist) * 10.0;
        } elseif ($hasStatsPos || $hasSavePos) {
            // Position on only one side — allow weak name match only.
            $distanceScore = 0.0;
        } else {
            $distanceScore = 5.0;
        }

        $nameScore = self::nameScore($stats['name'] ?? null, $save['nameHint'] ?? null, $save['filename'] ?? null);
        if ($distanceScore <= 0.0 && $nameScore < 20.0) {
            return null;
        }

        return $distanceScore + $nameScore;
    }

    private static function nameScore(?string $statsName, ?string $hint, ?string $filename): float
    {
        $statsName = self::normalizeName($statsName);
        if ($statsName === '') {
            return 0.0;
        }

        $hint = self::normalizeName($hint);
        $file = self::normalizeName($filename);
        if ($hint !== '' && ($hint === $statsName || str_contains($hint, $statsName) || str_contains($statsName, $hint))) {
            return 40.0;
        }
        if ($file !== '' && str_contains($file, $statsName)) {
            return 30.0;
        }

        // Token overlap (e.g. "Fendt 728" vs "fendt728vario")
        $tokens = preg_split('/[^a-z0-9]+/', $statsName) ?: [];
        $tokens = array_values(array_filter($tokens, static fn (string $t): bool => strlen($t) >= 3));
        if ($tokens === []) {
            return 0.0;
        }
        $hay = $hint.' '.$file;
        $hits = 0;
        foreach ($tokens as $token) {
            if (str_contains($hay, $token)) {
                $hits++;
            }
        }
        if ($hits === 0) {
            return 0.0;
        }

        return 12.0 * $hits;
    }

    private static function normalizeName(?string $value): string
    {
        if ($value === null) {
            return '';
        }

        return strtolower(preg_replace('/[^a-z0-9]+/i', '', $value) ?? '');
    }
}
