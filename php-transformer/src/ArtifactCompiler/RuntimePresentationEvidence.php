<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler;

/** Validates optional browser-collected media presentation observations. */
final class RuntimePresentationEvidence
{
    public const SCHEMA = 'blocks-engine/php-transformer/runtime-presentation-evidence/v1';
    private const MAX_OBSERVATIONS = 100;

    /**
     * @param array<string,mixed> $artifact
     * @param array<int,array<string,mixed>> $files
     * @return array{provenance:array<string,mixed>,observations:array<int,array<string,mixed>>,diagnostics:array<int,array<string,mixed>>,rejected_count:int}
     */
    public static function normalize(array $artifact, array $files): array
    {
        $raw = $artifact['runtime_presentation_evidence'] ?? null;
        if (null === $raw) return array('provenance' => array(), 'observations' => array(), 'diagnostics' => array(), 'rejected_count' => 0);
        if (!is_array($raw) || self::SCHEMA !== ($raw['schema'] ?? null) || !self::provenance($raw['provenance'] ?? null) || !is_array($raw['observations'] ?? null) || !array_is_list($raw['observations']) || count($raw['observations']) > self::MAX_OBSERVATIONS) {
            return array('provenance' => array(), 'observations' => array(), 'diagnostics' => array(self::diagnostic('runtime_presentation_evidence_invalid_envelope', array())), 'rejected_count' => 1);
        }

        $htmlPaths = array();
        $imageHashes = array();
        foreach ($files as $file) {
            if (!is_array($file)) continue;
            if ('html' === ($file['kind'] ?? '') && is_string($file['path'] ?? null)) $htmlPaths[$file['path']] = true;
            if (str_starts_with((string) ($file['mime_type'] ?? ''), 'image/') && is_string($file['provenance']['hash'] ?? null)) $imageHashes[$file['provenance']['hash']] = true;
        }

        $observations = array(); $diagnostics = array(); $rejected = 0; $seen = array();
        foreach ($raw['observations'] as $index => $observation) {
            $normalized = self::observation($observation);
            if (null === $normalized) {
                ++$rejected; $diagnostics[] = self::diagnostic('runtime_presentation_evidence_invalid_observation', array('index' => $index)); continue;
            }
            if (!isset($htmlPaths[$normalized['element']['source_path']])) {
                ++$rejected; $diagnostics[] = self::diagnostic('runtime_presentation_evidence_unknown_source', array('index' => $index, 'source_path' => $normalized['element']['source_path'])); continue;
            }
            if (!isset($imageHashes[$normalized['asset_hash']])) {
                ++$rejected; $diagnostics[] = self::diagnostic('runtime_presentation_evidence_asset_hash_mismatch', array('index' => $index, 'asset_hash' => $normalized['asset_hash'])); continue;
            }
            $key = $normalized['element']['source_path'] . "\n" . $normalized['element']['selector'];
            if (isset($seen[$key])) {
                ++$rejected; $diagnostics[] = self::diagnostic('runtime_presentation_evidence_duplicate_observation', array('index' => $index)); continue;
            }
            $seen[$key] = true; $observations[] = $normalized;
        }
        usort($observations, static fn(array $left, array $right): int => strcmp($left['element']['source_path'] . "\n" . $left['element']['selector'], $right['element']['source_path'] . "\n" . $right['element']['selector']));
        return array('provenance' => self::normalizedProvenance($raw['provenance']), 'observations' => $observations, 'diagnostics' => $diagnostics, 'rejected_count' => $rejected);
    }

    private static function provenance(mixed $value): bool
    {
        if (!is_array($value) || !is_array($value['browser'] ?? null) || !is_array($value['viewport'] ?? null) || !is_array($value['lifecycle'] ?? null)) return false;
        return self::text($value['browser']['name'] ?? null, 128) && self::text($value['browser']['version'] ?? null, 128)
            && self::positive($value['viewport']['width'] ?? null) && self::positive($value['viewport']['height'] ?? null) && self::positive($value['viewport']['device_scale_factor'] ?? null)
            && self::text($value['lifecycle']['phase'] ?? null, 128);
    }

    /** @param array<string,mixed> $value @return array<string,mixed> */
    private static function normalizedProvenance(array $value): array
    {
        return array(
            'browser' => array('name' => $value['browser']['name'], 'version' => $value['browser']['version']),
            'viewport' => array('width' => $value['viewport']['width'], 'height' => $value['viewport']['height'], 'device_scale_factor' => $value['viewport']['device_scale_factor']),
            'lifecycle' => array('phase' => $value['lifecycle']['phase']),
        );
    }

    /** @return array<string,mixed>|null */
    private static function observation(mixed $value): ?array
    {
        if (!is_array($value) || !is_array($value['element'] ?? null) || !self::text($value['element']['source_path'] ?? null, 1024) || !self::text($value['element']['selector'] ?? null, 2048) || !is_string($value['asset_hash'] ?? null) || 1 !== preg_match('/^[a-f0-9]{64}$/', $value['asset_hash']) || !self::dimensions($value['intrinsic'] ?? null) || !self::dimensions($value['rendered'] ?? null) || !self::transform($value['transform'] ?? null) || !self::clip($value['clip'] ?? null)) return null;
        return array(
            'element' => array('source_path' => $value['element']['source_path'], 'selector' => $value['element']['selector']),
            'asset_hash' => $value['asset_hash'],
            'intrinsic' => self::numbers($value['intrinsic'], array('width', 'height')),
            'rendered' => self::numbers($value['rendered'], array('width', 'height')),
            'transform' => array('matrix' => array_values($value['transform']['matrix']), 'origin' => self::numbers($value['transform']['origin'], array('x', 'y'))),
            'clip' => self::numbers($value['clip'], array('x', 'y', 'width', 'height')),
        );
    }

    private static function dimensions(mixed $value): bool { return is_array($value) && self::positive($value['width'] ?? null) && self::positive($value['height'] ?? null); }
    private static function clip(mixed $value): bool { return is_array($value) && self::number($value['x'] ?? null) && self::number($value['y'] ?? null) && self::positive($value['width'] ?? null) && self::positive($value['height'] ?? null); }
    private static function transform(mixed $value): bool { if (!is_array($value) || !is_array($value['matrix'] ?? null) || 6 !== count($value['matrix']) || !is_array($value['origin'] ?? null)) return false; foreach ($value['matrix'] as $number) if (!self::number($number)) return false; return self::number($value['origin']['x'] ?? null) && self::number($value['origin']['y'] ?? null); }
    private static function text(mixed $value, int $max): bool { return is_string($value) && '' !== trim($value) && strlen($value) <= $max && !preg_match('/[\x00-\x1f\x7f]/', $value); }
    private static function number(mixed $value): bool { return (is_int($value) || is_float($value)) && is_finite((float) $value); }
    private static function positive(mixed $value): bool { return self::number($value) && (float) $value > 0; }
    /** @param array<string,mixed> $value @param array<int,string> $keys @return array<string,int|float> */
    private static function numbers(array $value, array $keys): array { $result = array(); foreach ($keys as $key) $result[$key] = $value[$key]; return $result; }
    /** @param array<string,mixed> $context @return array<string,mixed> */
    private static function diagnostic(string $code, array $context): array { return array('code' => $code, 'severity' => 'warning', 'message' => 'Runtime presentation evidence was ignored because its browser provenance or artifact binding could not be verified.', 'source' => ArtifactCompiler::class, 'context' => $context); }
}
