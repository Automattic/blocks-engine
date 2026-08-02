<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler;

use Automattic\BlocksEngine\PhpTransformer\Path\ArtifactPath;

/** Validates optional, artifact-bound browser presentation observations. */
final class RuntimePresentationEvidence
{
    public const SCHEMA = 'blocks-engine/artifact-runtime-presentation-evidence/v1';
    private const MAX_OBSERVATIONS = 32;
    private const MAX_NUMBER = 1000000;

    /** @param array<string,mixed> $artifact @param array<int,array<string,mixed>> $files @return array{provenance:array<string,mixed>,observations:array<int,array<string,mixed>>,diagnostics:array<int,array<string,mixed>>,rejected_count:int} */
    public static function normalize(array $artifact, array $files): array
    {
        $raw = $artifact['artifact_runtime_presentation_evidence'] ?? null;
        if (null === $raw) return array('provenance' => array(), 'observations' => array(), 'diagnostics' => array(), 'rejected_count' => 0);
        if (!is_array($raw) || !self::keys($raw, array('schema', 'provenance', 'observations')) || self::SCHEMA !== ($raw['schema'] ?? null) || !self::provenance($raw['provenance'] ?? null) || !is_array($raw['observations'] ?? null) || !array_is_list($raw['observations']) || count($raw['observations']) > self::MAX_OBSERVATIONS) return self::rejected('artifact_runtime_presentation_evidence_invalid_envelope');

        $html = array(); $images = array();
        foreach ($files as $file) {
            if (!is_array($file)) continue;
            if ('html' === ($file['kind'] ?? '') && is_string($file['path'] ?? null)) $html[$file['path']] = (string) ($file['content'] ?? '');
            if (str_starts_with((string) ($file['mime_type'] ?? ''), 'image/') && is_string($file['path'] ?? null)) $images[$file['path']] = (string) ($file['provenance']['hash'] ?? '');
        }
        $observations = array(); $diagnostics = array(); $seen = array(); $rejected = 0;
        foreach ($raw['observations'] as $index => $row) {
            $observation = self::observation($row);
            if (null === $observation) { ++$rejected; $diagnostics[] = self::diagnostic('artifact_runtime_presentation_evidence_invalid_observation', array('index' => $index)); continue; }
            $path = $observation['element']['source_path'];
            if (!isset($html[$path]) || !hash_equals($observation['source_hash'], hash('sha256', $html[$path]))) { ++$rejected; $diagnostics[] = self::diagnostic('artifact_runtime_presentation_evidence_source_hash_mismatch', array('index' => $index, 'source_path' => $path)); continue; }
            $assetPath = self::resolvedImageSource($html[$path], $observation['element']['locator']['value'], $path);
            if (null === $assetPath) { ++$rejected; $diagnostics[] = self::diagnostic('artifact_runtime_presentation_evidence_locator_mismatch', array('index' => $index, 'source_path' => $path)); continue; }
            if ($assetPath !== $observation['asset']['path'] || !isset($images[$assetPath]) || !hash_equals($observation['asset']['hash'], $images[$assetPath])) { ++$rejected; $diagnostics[] = self::diagnostic('artifact_runtime_presentation_evidence_asset_mismatch', array('index' => $index, 'asset_path' => $observation['asset']['path'])); continue; }
            $key = $path . "\n" . $observation['element']['locator']['value'];
            if (isset($seen[$key])) { ++$rejected; $diagnostics[] = self::diagnostic('artifact_runtime_presentation_evidence_duplicate_observation', array('index' => $index)); continue; }
            $seen[$key] = true; $observations[] = $observation;
        }
        usort($observations, static fn(array $a, array $b): int => strcmp($a['element']['source_path'] . "\n" . $a['element']['locator']['value'], $b['element']['source_path'] . "\n" . $b['element']['locator']['value']));
        return array('provenance' => self::normalizedProvenance($raw['provenance']), 'observations' => $observations, 'diagnostics' => $diagnostics, 'rejected_count' => $rejected);
    }

    private static function provenance(mixed $value): bool
    {
        return is_array($value) && self::keys($value, array('browser', 'viewport', 'readiness')) && is_array($value['browser'] ?? null) && self::keys($value['browser'], array('name', 'version')) && self::text($value['browser']['name'] ?? null, 128) && self::text($value['browser']['version'] ?? null, 128) && is_array($value['viewport'] ?? null) && self::keys($value['viewport'], array('width', 'height', 'device_scale_factor')) && self::positive($value['viewport']['width'] ?? null) && self::positive($value['viewport']['height'] ?? null) && self::positive($value['viewport']['device_scale_factor'] ?? null) && is_array($value['readiness'] ?? null) && self::keys($value['readiness'], array('document', 'images')) && 'complete' === ($value['readiness']['document'] ?? null) && 'complete' === ($value['readiness']['images'] ?? null);
    }

    /** @param array<string,mixed> $value @return array<string,mixed> */
    private static function normalizedProvenance(array $value): array
    {
        return array(
            'browser' => array('name' => $value['browser']['name'], 'version' => $value['browser']['version']),
            'viewport' => array('width' => $value['viewport']['width'], 'height' => $value['viewport']['height'], 'device_scale_factor' => $value['viewport']['device_scale_factor']),
            'readiness' => array('document' => $value['readiness']['document'], 'images' => $value['readiness']['images']),
        );
    }

    /** @return array<string,mixed>|null */
    private static function observation(mixed $value): ?array
    {
        if (!is_array($value) || !self::keys($value, array('element', 'source_hash', 'asset', 'intrinsic', 'rendered', 'transform', 'clip')) || !is_array($value['element'] ?? null) || !self::keys($value['element'], array('source_path', 'locator')) || !self::text($value['element']['source_path'] ?? null, 512) || !is_array($value['element']['locator'] ?? null) || !self::keys($value['element']['locator'], array('kind', 'value')) || 'css-nth-of-type-body-path/v1' !== ($value['element']['locator']['kind'] ?? null) || !self::locator($value['element']['locator']['value'] ?? null) || !self::hash($value['source_hash'] ?? null) || !is_array($value['asset'] ?? null) || !self::keys($value['asset'], array('path', 'hash')) || !self::text($value['asset']['path'] ?? null, 512) || !self::hash($value['asset']['hash'] ?? null) || !self::dimensions($value['intrinsic'] ?? null) || !self::dimensions($value['rendered'] ?? null) || !self::transform($value['transform'] ?? null) || !self::clip($value['clip'] ?? null)) return null;
        if ((float) $value['clip']['x'] < 0 || (float) $value['clip']['y'] < 0 || (float) $value['clip']['x'] + (float) $value['clip']['width'] > (float) $value['rendered']['width'] || (float) $value['clip']['y'] + (float) $value['clip']['height'] > (float) $value['rendered']['height']) return null;
        $matrix = $value['transform']['matrix'];
        // Geometry replay has one defined coordinate system: an unrotated image
        // scaled on each axis, then translated within its clipped figure.
        if (0.0 !== (float) $matrix[1] || 0.0 !== (float) $matrix[2] || (float) $matrix[0] <= 0 || (float) $matrix[3] <= 0) return null;
        return array(
            'element' => array('source_path' => $value['element']['source_path'], 'locator' => array('kind' => $value['element']['locator']['kind'], 'value' => $value['element']['locator']['value'])),
            'source_hash' => $value['source_hash'],
            'asset' => array('path' => $value['asset']['path'], 'hash' => $value['asset']['hash']),
            'intrinsic' => array('width' => $value['intrinsic']['width'], 'height' => $value['intrinsic']['height']),
            'rendered' => array('width' => $value['rendered']['width'], 'height' => $value['rendered']['height']),
            'transform' => array('matrix' => array_values($matrix), 'origin' => array('x' => $value['transform']['origin']['x'], 'y' => $value['transform']['origin']['y'])),
            'clip' => array('x' => $value['clip']['x'], 'y' => $value['clip']['y'], 'width' => $value['clip']['width'], 'height' => $value['clip']['height']),
        );
    }

    private static function dimensions(mixed $value): bool { return is_array($value) && self::keys($value, array('width', 'height')) && self::positive($value['width'] ?? null) && self::positive($value['height'] ?? null); }
    private static function clip(mixed $value): bool { return is_array($value) && self::keys($value, array('x', 'y', 'width', 'height')) && self::number($value['x'] ?? null) && self::number($value['y'] ?? null) && self::positive($value['width'] ?? null) && self::positive($value['height'] ?? null); }
    private static function transform(mixed $value): bool { if (!is_array($value) || !self::keys($value, array('matrix', 'origin')) || !is_array($value['matrix'] ?? null) || !array_is_list($value['matrix']) || 6 !== count($value['matrix']) || !is_array($value['origin'] ?? null) || !self::keys($value['origin'], array('x', 'y'))) return false; foreach ($value['matrix'] as $number) if (!self::number($number)) return false; return self::number($value['origin']['x'] ?? null) && self::number($value['origin']['y'] ?? null); }
    private static function hash(mixed $value): bool { return is_string($value) && 1 === preg_match('/^[a-f0-9]{64}$/', $value); }
    private static function text(mixed $value, int $max): bool { return is_string($value) && '' !== trim($value) && strlen($value) <= $max && !preg_match('/[\x00-\x1f\x7f]/', $value); }
    private static function number(mixed $value): bool { return (is_int($value) || is_float($value)) && is_finite((float) $value) && abs((float) $value) <= self::MAX_NUMBER; }
    private static function positive(mixed $value): bool { return self::number($value) && (float) $value > 0; }
    private static function locator(mixed $value): bool { return self::text($value, 2048) && 1 === preg_match('/^(?:[a-z][a-z0-9-]*:nth-of-type\([1-9][0-9]*\) > )*img:nth-of-type\([1-9][0-9]*\)$/', $value); }
    /** @param array<string,mixed> $value @param array<int,string> $keys */
    private static function keys(array $value, array $keys): bool { sort($keys); $actual = array_keys($value); sort($actual); return $keys === $actual; }

    private static function resolvedImageSource(string $html, string $locator, string $sourcePath): ?string
    {
        $document = new \DOMDocument(); $previous = libxml_use_internal_errors(true);
        $documentSource = preg_match('/<(?:!doctype|html|head|body)\b/i', $html) ? '<?xml encoding="utf-8" ?>' . $html : '<?xml encoding="utf-8" ?><body>' . $html . '</body>';
        $loaded = $document->loadHTML($documentSource, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD); libxml_clear_errors(); libxml_use_internal_errors($previous); if (!$loaded) return null;
        $current = $document->getElementsByTagName('body')->item(0);
        foreach (explode(' > ', $locator) as $part) { if (!$current instanceof \DOMElement || 1 !== preg_match('/^([a-z][a-z0-9-]*):nth-of-type\(([1-9][0-9]*)\)$/', $part, $match)) return null; $found = null; $count = 0; foreach ($current->childNodes as $child) if ($child instanceof \DOMElement && strtolower($child->tagName) === $match[1] && ++$count === (int) $match[2]) { $found = $child; break; } $current = $found; }
        if (!$current instanceof \DOMElement || 'img' !== strtolower($current->tagName) || !$current->hasAttribute('src')) return null;
        return ArtifactPath::resolveArtifactReference($current->getAttribute('src'), $sourcePath) ?: null;
    }

    /** @param array<string,mixed> $observation */
    public static function marker(array $observation): string
    {
        return 'be-runtime-evidence-' . substr(hash('sha256', RuntimeDeclarations::canonicalJson($observation)), 0, 24);
    }

    /** @return array{provenance:array<string,mixed>,observations:array<int,array<string,mixed>>,diagnostics:array<int,array<string,mixed>>,rejected_count:int} */
    private static function rejected(string $code): array { return array('provenance' => array(), 'observations' => array(), 'diagnostics' => array(self::diagnostic($code, array())), 'rejected_count' => 1); }
    /** @param array<string,mixed> $context @return array<string,mixed> */
    private static function diagnostic(string $code, array $context): array { return array('code' => $code, 'severity' => 'warning', 'message' => 'Artifact runtime presentation evidence was ignored because its bounded provenance or exact artifact binding could not be verified.', 'source' => ArtifactCompiler::class, 'context' => $context); }
}
