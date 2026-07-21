<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler;

use Automattic\BlocksEngine\PhpTransformer\Path\ArtifactPath;
use InvalidArgumentException;
use JsonException;

/** Validates caller-declared, destination-independent runtime requirements. */
final class RuntimeDeclarations
{
    private const MAX_DECLARATIONS = 100;
    private const MAX_PAYLOAD_BYTES = 262144;
    public const MAX_PROVENANCE_BYTES = ArtifactNormalizer::DEFAULT_MAX_FILE_BYTES;
    public const MAX_PROVENANCE_KEYS = ArtifactNormalizer::DEFAULT_MAX_FILES;
    public const MAX_PROVENANCE_SCALAR_BYTES = ArtifactNormalizer::DEFAULT_MAX_FILE_BYTES;
    public const MAX_PROVENANCE_DEPTH = 32;
    private const MAX_CANONICAL_DEPTH = self::MAX_PROVENANCE_DEPTH + 1;

    /** @param array<string,mixed> $artifact @return array<int,array<string,mixed>> */
    public static function normalize(array $artifact): array
    {
        $topLevel = $artifact['runtime_declarations'] ?? null;
        $metadata = is_array($artifact['metadata'] ?? null) ? ($artifact['metadata']['runtime_declarations'] ?? null) : null;
        if (null !== $topLevel && null !== $metadata) throw new InvalidArgumentException('Runtime declarations must be provided in exactly one canonical artifact location.');
        $raw = $topLevel ?? $metadata;
        if (null === $raw) return array();
        return self::normalizeList($raw);
    }

    /** @return array<int,array<string,mixed>> */
    public static function normalizeList(mixed $raw): array
    {
        if (!is_array($raw) || !array_is_list($raw) || count($raw) > self::MAX_DECLARATIONS) throw new InvalidArgumentException('Runtime declarations must be a bounded ordered collection.');

        $declarations = array();
        $keys = array();
        $identities = array();
        foreach ($raw as $index => $declaration) {
            if (!is_array($declaration)) throw new InvalidArgumentException("Runtime declaration {$index} must be an object.");
            $kind = $declaration['kind'] ?? null;
            $type = $declaration['type'] ?? null;
            $capability = $declaration['capability'] ?? null;
            $sourcePath = $declaration['source_path'] ?? null;
            if (!is_string($kind) || !preg_match('/^[a-z][a-z0-9_-]{0,63}$/', $kind) || (!is_string($type) && !is_string($capability)) || (is_string($type) && is_string($capability)) || !is_string($sourcePath) || '' === ArtifactPath::safeRelativePath($sourcePath) || ArtifactPath::safeRelativePath($sourcePath) !== $sourcePath) throw new InvalidArgumentException("Runtime declaration {$index} has an unsafe or contradictory identity.");
            $name = is_string($type) ? $type : $capability;
            if (!preg_match('/^[a-z][a-z0-9_-]{0,127}$/', $name)) throw new InvalidArgumentException("Runtime declaration {$index} has an unsupported type or capability.");
            $key = $kind . ':' . $name;
            $identity = hash('sha256', "wordpress-site-plan/runtime-declaration/v1\n{$sourcePath}\n{$key}");
            if (isset($keys[$key]) || isset($identities[$identity])) throw new InvalidArgumentException("Runtime declaration {$index} has a duplicate reconciliation identity.");
            if (isset($declaration['reconciliation_identity']) && $declaration['reconciliation_identity'] !== $identity) throw new InvalidArgumentException("Runtime declaration {$index} reconciliation_identity must derive from its source path and kind.");

            $normalized = array('kind' => $kind, is_string($type) ? 'type' : 'capability' => $name, 'source_path' => $sourcePath, 'reconciliation_identity' => $identity);
            if (isset($declaration['provenance'])) {
                if (!is_array($declaration['provenance']) || (isset($declaration['provenance']['source_path']) && (!is_string($declaration['provenance']['source_path']) || $declaration['provenance']['source_path'] !== $sourcePath))) throw new InvalidArgumentException("Runtime declaration {$index} provenance must retain its safe source path.");
                $normalized['provenance'] = self::canonicalProvenance($declaration['provenance'], $index);
            }
            if (isset($declaration['payload'])) {
                if (!is_array($declaration['payload']) || !is_string($declaration['payload']['schema'] ?? null) || '' === trim($declaration['payload']['schema']) || trim($declaration['payload']['schema']) !== $declaration['payload']['schema'] || strlen($declaration['payload']['schema']) > 255) throw new InvalidArgumentException("Runtime declaration {$index} payload requires a bounded nonblank schema.");
                $payload = self::canonical($declaration['payload']);
                try { $encoded = self::canonicalJson($payload); } catch (InvalidArgumentException) { throw new InvalidArgumentException("Runtime declaration {$index} payload is not serializable."); }
                if (strlen($encoded) > self::MAX_PAYLOAD_BYTES) throw new InvalidArgumentException("Runtime declaration {$index} payload exceeds the byte limit.");
                $normalized['payload'] = $payload;
            }
            if ('entity_collection' === $kind && (!isset($normalized['type'], $normalized['payload']['entities']) || !array_is_list($normalized['payload']['entities']))) throw new InvalidArgumentException("Runtime declaration {$index} entity collections require a typed entities payload.");
            if (isset($declaration['required_for'])) {
                if (!is_array($declaration['required_for']) || !array_is_list($declaration['required_for']) || array_filter($declaration['required_for'], static fn(mixed $value): bool => !is_string($value) || '' === $value)) throw new InvalidArgumentException("Runtime declaration {$index} required_for must be a list of declaration keys.");
                if (count($declaration['required_for']) !== count(array_unique($declaration['required_for']))) throw new InvalidArgumentException("Runtime declaration {$index} required_for must not contain duplicates.");
                $normalized['required_for'] = array_values($declaration['required_for']);
                sort($normalized['required_for'], SORT_STRING);
            }
            $normalized['payload_hash'] = self::hash($normalized['payload'] ?? null);
            $mutable = $normalized;
            unset($mutable['reconciliation_identity'], $mutable['payload_hash']);
            $normalized['content_hash'] = self::hash($mutable);
            $declarations[] = $normalized;
            $keys[$key] = $identity;
            $identities[$identity] = true;
        }
        foreach ($declarations as $index => $declaration) foreach ($declaration['required_for'] ?? array() as $required) if (!isset($keys[$required])) throw new InvalidArgumentException("Runtime declaration {$index} required_for references unresolved declaration {$required}.");
        usort($declarations, static fn(array $left, array $right): int => strcmp($left['reconciliation_identity'], $right['reconciliation_identity']));
        return $declarations;
    }

    /** @param array<int,mixed> $declarations */
    public static function assertNormalized(array $declarations): void
    {
        if ($declarations !== self::normalizeList($declarations)) throw new InvalidArgumentException('Runtime declarations are not canonically normalized or have stale hashes.');
    }

    public static function canonicalJson(mixed $value): string
    {
        try { return json_encode(self::canonical($value), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE); } catch (JsonException) { throw new InvalidArgumentException('Runtime declaration payload is not serializable.'); }
    }

    public static function hash(mixed $value): string
    {
        return hash('sha256', self::canonicalJson($value));
    }

    private static function canonical(mixed $value, int $depth = 0): mixed
    {
        if ($depth > self::MAX_CANONICAL_DEPTH || is_resource($value) || is_object($value)) throw new InvalidArgumentException('Runtime declaration payload contains an unsupported value.');
        if (!is_array($value)) return $value;
        foreach ($value as $key => $item) if (!is_int($key) && !is_string($key)) throw new InvalidArgumentException('Runtime declaration payload has an unsupported key.');
        if (!array_is_list($value)) ksort($value, SORT_STRING);
        foreach ($value as $key => $item) $value[$key] = self::canonical($item, $depth + 1);
        return $value;
    }

    /** @param array<string,mixed> $provenance @return array<string,mixed> */
    private static function canonicalProvenance(array $provenance, int $index): array
    {
        $keys = 0;
        $canonical = self::canonicalProvenanceValue($provenance, $keys, 0, $index);
        if (!is_array($canonical)) throw new InvalidArgumentException("Runtime declaration {$index} provenance must be an object.");
        $bytes = strlen(self::canonicalJson($canonical));
        if ($bytes > self::MAX_PROVENANCE_BYTES) throw new InvalidArgumentException("Runtime declaration {$index} provenance exceeds the {$bytes}-byte limit of " . self::MAX_PROVENANCE_BYTES . '.');
        return $canonical;
    }

    private static function canonicalProvenanceValue(mixed $value, int &$keys, int $depth, int $index): mixed
    {
        if ($depth > self::MAX_PROVENANCE_DEPTH) throw new InvalidArgumentException("Runtime declaration {$index} provenance exceeds the nesting limit of " . self::MAX_PROVENANCE_DEPTH . '.');
        if (is_resource($value) || is_object($value)) throw new InvalidArgumentException("Runtime declaration {$index} provenance contains an unsupported value.");
        if (!is_array($value)) {
            if (is_string($value) && strlen($value) > self::MAX_PROVENANCE_SCALAR_BYTES) throw new InvalidArgumentException("Runtime declaration {$index} provenance scalar exceeds the byte limit of " . self::MAX_PROVENANCE_SCALAR_BYTES . '.');
            return $value;
        }
        foreach ($value as $key => $item) {
            if (!is_int($key) && !is_string($key)) throw new InvalidArgumentException("Runtime declaration {$index} provenance has an unsupported key.");
            if (++$keys > self::MAX_PROVENANCE_KEYS) throw new InvalidArgumentException("Runtime declaration {$index} provenance exceeds the key limit of " . self::MAX_PROVENANCE_KEYS . '.');
            if (is_string($key) && strlen($key) > self::MAX_PROVENANCE_SCALAR_BYTES) throw new InvalidArgumentException("Runtime declaration {$index} provenance key exceeds the byte limit of " . self::MAX_PROVENANCE_SCALAR_BYTES . '.');
        }
        if (!array_is_list($value)) ksort($value, SORT_STRING);
        foreach ($value as $key => $item) $value[$key] = self::canonicalProvenanceValue($item, $keys, $depth + 1, $index);
        return $value;
    }
}
