<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Session;

/** Per-transform source metadata and generated asset state. */
final class AssetMaterializationState
{
    /** Where inline SVG payloads are materialized, relative to the asset root. */
    public const INLINE_SVG_DIRECTORY = 'assets/materialized-svg';

    /** @var array<string, array<string, mixed>> */
    private array $generated = array();

    /** The content-addressed asset path for one sanitized inline SVG payload. */
    public function inlineSvgPath(string $payload): string
    {
        return $this->rootedPath(self::INLINE_SVG_DIRECTORY . '/inline-svg-' . substr(hash('sha256', $payload), 0, 16) . '.svg');
    }

    /**
     * Whether a reference names an inline SVG asset this engine materialized,
     * in this transform or a previous one. The content-addressed name is the
     * engine's own, so re-ingested saved markup is recognized from the
     * reference alone, exactly as the transform that produced it treated it.
     */
    public static function isInlineSvgAssetReference(string $source): bool
    {
        $path = (string) parse_url($source, PHP_URL_PATH);
        return 1 === preg_match('@(?:^|/)' . preg_quote(self::INLINE_SVG_DIRECTORY, '@') . '/inline-svg-[a-f0-9]{16}\.svg$@', $path);
    }

    /** @param array<string, array<string, mixed>> $metadata */
    public function __construct(
        private readonly string $root,
        private readonly array $metadata
    ) {
    }

    public function rootedPath(string $path): string
    {
        return ('' !== $this->root ? $this->root . '/' : '') . ltrim($path, '/');
    }

    /** @param array<string, mixed> $asset */
    public function register(string $path, array $asset): void
    {
        $this->generated[$path] = $asset;
    }

    /**
     * @param array<string, mixed> $asset
     * @param array<string, mixed> $occurrence
     */
    public function registerInlineSvg(string $path, array $asset, array $occurrence, string $visualPayload): void
    {
        if (!isset($this->generated[$path])) {
            if (is_string($occurrence['fingerprint'] ?? null)) {
                $asset['component_occurrence_counts'] = array($occurrence['fingerprint'] => 1);
            }
            $asset['component_occurrences_omitted'] = 0;
            $this->generated[$path] = $asset;
            return;
        }

        $existing = $this->generated[$path];
        // Shared visual identity must not retain instance accessibility metadata.
        $existing['content'] = (string) ($existing['visual_payload'] ?? $visualPayload . "\n");
        $existing['bytes'] = strlen($existing['content']);
        $existing['hash'] = hash('sha256', $existing['content']);
        $existing['source_hash'] = $existing['hash'];
        $occurrences = is_array($existing['component_occurrences'] ?? null) ? $existing['component_occurrences'] : array();
        $counts = is_array($existing['component_occurrence_counts'] ?? null) ? $existing['component_occurrence_counts'] : array();
        if (is_string($occurrence['fingerprint'] ?? null)) {
            $counts[$occurrence['fingerprint']] = (int) ($counts[$occurrence['fingerprint']] ?? 0) + 1;
        }
        if (count($occurrences) < 8 && !in_array($occurrence, $occurrences, true)) {
            $occurrences[] = $occurrence;
        } elseif (!in_array($occurrence, $occurrences, true)) {
            $existing['component_occurrences_omitted'] = (int) ($existing['component_occurrences_omitted'] ?? 0) + 1;
        }
        $existing['component_occurrences'] = $occurrences;
        $existing['component_occurrence_counts'] = $counts;
        $existing['selector'] = $occurrences[0]['selector'] ?? $existing['selector'];
        $this->generated[$path] = $existing;
    }

    /** @return array<int, array<string, mixed>> */
    public function assets(): array
    {
        return array_values($this->generated);
    }

    /** @return array<string, array<string, mixed>> */
    public function checkpoint(): array
    {
        return $this->generated;
    }

    /** @param array<string, array<string, mixed>> $checkpoint */
    public function restore(array $checkpoint): void
    {
        $this->generated = $checkpoint;
    }

    public function hasInlineSvgSource(string $source): bool
    {
        if (isset($this->generated[$source]) && 'inline-svg' === ($this->generated[$source]['source'] ?? '')) {
            return true;
        }
        foreach ($this->generated as $asset) {
            if ('inline-svg' === ($asset['source'] ?? '') && $source === ($asset['source_url'] ?? null)) {
                return true;
            }
        }
        return self::isInlineSvgAssetReference($source);
    }

    /** @return array<string, mixed>|null */
    public function metadataForUrl(string $url): ?array
    {
        foreach ($this->metadataLookupKeys($url) as $key) {
            if (isset($this->metadata[$key])) {
                return $this->metadata[$key];
            }
        }
        return null;
    }

    /** @return array<int, string> */
    private function metadataLookupKeys(string $url): array
    {
        $keys = array();
        // Root-relative URLs cannot traverse out of their website root.
        if (str_starts_with(trim($url), '/') && preg_match('~(?:^|/)\.\.(?:/|$)~', parse_url($url, PHP_URL_PATH) ?: '')) {
            return array(trim($url));
        }
        foreach (array(trim($url), ltrim(trim($url), '/')) as $key) {
            if ('' !== $key && !in_array($key, $keys, true)) {
                $keys[] = $key;
            }
        }
        $path = parse_url($url, PHP_URL_PATH);
        if (is_string($path)) {
            foreach (array($path, ltrim($path, '/')) as $key) {
                if ('' !== $key && !in_array($key, $keys, true)) {
                    $keys[] = $key;
                }
            }
        }
        return $keys;
    }
}
