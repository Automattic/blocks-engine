<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler;

use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\WordPressSitePlan;
use InvalidArgumentException;

/** Bounded, content-addressed records for declaration entities. */
final class RuntimeEntityManifest
{
    public const SCHEMA = 'blocks-engine/runtime-entity-manifest/v1';

    /** @param array<int,array<string,mixed>> $entities @return array{payload:array<string,mixed>,records:array<int,array<string,mixed>>} */
    public static function fromEntities(string $entitySchema, array $entities): array
    {
        $records = array(); $references = array();
        foreach ($entities as $entity) {
            if (!is_array($entity)) throw new InvalidArgumentException('Runtime entity manifest records must be objects.');
            $entity = RuntimeDeclarations::canonical($entity);
            $hash = RuntimeDeclarations::hash($entity);
            $record = array('content_hash' => $hash, 'entity' => $entity);
            if (strlen(RuntimeDeclarations::canonicalJson($record)) > RuntimeDeclarations::MAX_PAYLOAD_BYTES) throw new InvalidArgumentException('Runtime entity manifest record exceeds the 5 MiB payload limit.');
            $records[$hash] = $record;
            $references[] = array('content_hash' => $hash);
        }
        ksort($records, SORT_STRING);
        $payload = array('schema' => self::SCHEMA, 'entity_schema' => $entitySchema, 'entities' => $references);
        if (strlen(RuntimeDeclarations::canonicalJson($payload)) > RuntimeDeclarations::MAX_PAYLOAD_BYTES) throw new InvalidArgumentException('Runtime entity manifest references exceed the 5 MiB payload limit.');
        return array('payload' => $payload, 'records' => array_values($records));
    }

    /**
     * Fit one generated entity collection into the canonical declaration limits.
     *
     * An entity no declaration can carry is dropped with its bindings, so its
     * block stays static (`runtime_entity_dropped`). The rest is declared
     * inline, or as a manifest of content-addressed records when the inline
     * payload is over its limit. A manifest still over the per-declaration
     * limit drops the declaration (`runtime_declaration_dropped`). Limits over
     * the whole declaration set stay fail-closed.
     *
     * @param array<int,array<string,mixed>> $declarations Declarations already accepted.
     * @param array<int,array<string,mixed>> $entities
     * @return array{payload:array<string,mixed>|null,records:array<int,array<string,mixed>>,diagnostics:array<int,array<string,mixed>>}
     */
    public static function declareCollection(array $declarations, string $type, string $schema, array $entities, string $sourcePath, ?string $dependencyCapability): array
    {
        $screened = self::screenEntities($entities, $schema, $sourcePath);
        $kept = $screened['entities'];
        $result = array('payload' => null, 'records' => array(), 'diagnostics' => $screened['diagnostics']);
        if (array() === $kept) return $result;

        $declarable = static function (array $payload, bool $aggregateFits) use ($declarations, $type, $sourcePath, $dependencyCapability): bool {
            $candidate = array_merge($declarations, array(array('kind' => 'entity_collection', 'type' => $type, 'source_path' => $sourcePath, 'payload' => $payload)));
            if (null !== $dependencyCapability) $candidate[] = array('kind' => 'dependency', 'capability' => $dependencyCapability, 'source_path' => $sourcePath, 'required_for' => array('entity_collection:' . $type));
            try {
                RuntimeDeclarations::normalizeList($candidate);
                return true;
            } catch (InvalidArgumentException $error) {
                if (str_contains($error->getMessage(), 'payload exceeds the byte limit')) return false;
                if (!$aggregateFits && str_contains($error->getMessage(), 'aggregate canonical byte limit')) return false;
                throw $error;
            }
        };
        $inline = array('schema' => $schema, 'entities' => $kept);
        if ($declarable($inline, false)) return array_merge($result, array('payload' => $inline));

        // Every kept entity fits one record, so only the reference list itself can
        // exceed the manifest payload limit here.
        try {
            $manifest = self::fromEntities($schema, $kept);
            if ($declarable($manifest['payload'], true)) return array_merge($result, array('payload' => $manifest['payload'], 'records' => $manifest['records']));
        } catch (InvalidArgumentException $error) {
            if (!str_contains($error->getMessage(), 'references exceed')) throw $error;
        }
        $result['diagnostics'][] = self::droppedDeclarationDiagnostic($type, $schema, count($kept), $sourcePath);
        return $result;
    }

    /**
     * Apply the same per-entity rule to generated and composition-grown entities.
     * @param array<int,array<string,mixed>> $entities
     * @return array{entities:array<int,array<string,mixed>>,diagnostics:array<int,array<string,mixed>>}
     */
    public static function screenEntities(array $entities, string $schema, string $sourcePath): array
    {
        $kept = array();
        $diagnostics = array();
        foreach ($entities as $entity) {
            $reason = RuntimeDeclarations::unrepresentableEntityReason(self::planForm($entity), $bytes);
            if (null === $reason) $kept[] = $entity;
            else $diagnostics[] = self::droppedEntityDiagnostic($entity, $reason, $bytes, $schema, $sourcePath);
        }
        return array('entities' => $kept, 'diagnostics' => $diagnostics);
    }

    /**
     * @param array<string,mixed> $entity
     * @return array<string,mixed>
     */
    private static function droppedEntityDiagnostic(array $entity, string $reason, ?int $bytes, string $schema, string $declarationSourcePath): array
    {
        $binding = is_array($entity['bindings'][0] ?? null) ? $entity['bindings'][0] : array();
        $sourcePath = is_string($entity['source_path'] ?? null) ? $entity['source_path'] : (is_string($binding['source_path'] ?? null) ? $binding['source_path'] : $declarationSourcePath);
        $because = 'byte_limit' === $reason
            ? sprintf('its site-plan record is %d bytes, over the %d-byte runtime declaration payload limit', (int) $bytes, RuntimeDeclarations::MAX_PAYLOAD_BYTES)
            : 'it is nested deeper than a site plan can carry, or holds a value canonical JSON cannot represent';
        return array_filter(array(
            'code' => 'runtime_entity_dropped',
            'severity' => 'warning',
            'message' => mb_strcut('A generated runtime entity was dropped with its bindings, so its block stays static, because ' . $because . '.', 0, 256, 'UTF-8'),
            'source' => self::class,
            'unit' => 'item',
            'source_path' => mb_strcut($sourcePath, 0, 256, 'UTF-8'),
            'selector' => is_string($entity['selector'] ?? null) ? mb_strcut($entity['selector'], 0, 256, 'UTF-8') : null,
            'entity_schema' => $schema,
            'reason_code' => 'byte_limit' === $reason ? 'runtime_entity_exceeds_byte_limit' : 'runtime_entity_unsupported_value',
            'metrics' => 'byte_limit' === $reason ? array('bytes' => $bytes, 'limit_bytes' => RuntimeDeclarations::MAX_PAYLOAD_BYTES) : null,
        ), static fn (mixed $value): bool => null !== $value);
    }

    /** @return array<string,mixed> */
    private static function droppedDeclarationDiagnostic(string $type, string $schema, int $entityCount, string $sourcePath): array
    {
        return array(
            'code' => 'runtime_declaration_dropped',
            'severity' => 'warning',
            'message' => substr(sprintf('The generated %s entity collection was dropped with its dependency and bindings, so its blocks stay static, because even its record manifest exceeds the %d-byte runtime declaration payload limit.', $type, RuntimeDeclarations::MAX_PAYLOAD_BYTES), 0, 256),
            'source' => self::class,
            'unit' => 'declaration',
            'source_path' => mb_strcut($sourcePath, 0, 256, 'UTF-8'),
            'declaration_key' => 'entity_collection:' . $type,
            'entity_schema' => $schema,
            'reason_code' => 'runtime_declaration_manifest_exceeds_byte_limit',
            'metrics' => array('entity_count' => $entityCount, 'limit_bytes' => RuntimeDeclarations::MAX_PAYLOAD_BYTES),
        );
    }

    /**
     * The entity as a site plan carries it: each binding also keeps its source
     * anchor, which repeats the binding's block markup.
     *
     * @param array<string,mixed> $entity
     * @return array<string,mixed>
     */
    private static function planForm(array $entity): array
    {
        if (!is_array($entity['bindings'] ?? null)) return $entity;
        foreach ($entity['bindings'] as &$binding) if (is_array($binding) && !is_array($binding['projected_anchor'] ?? null)) $binding['projected_anchor'] = WordPressSitePlan::projectedBindingAnchor($binding);
        unset($binding);
        return $entity;
    }

    /** @param array<int,mixed> $records @return array<int,array<string,mixed>> */
    public static function normalizeRecords(array $records): array
    {
        $normalized = array();
        foreach ($records as $record) {
            if (!is_array($record) || array('content_hash', 'entity') !== array_keys($record) || !is_string($record['content_hash'] ?? null) || !preg_match('/^[a-f0-9]{64}$/', $record['content_hash']) || !is_array($record['entity'] ?? null) || $record['content_hash'] !== RuntimeDeclarations::hash($record['entity']) || strlen(RuntimeDeclarations::canonicalJson($record)) > RuntimeDeclarations::MAX_PAYLOAD_BYTES) throw new InvalidArgumentException('Runtime entity manifest record is invalid or exceeds the 5 MiB payload limit.');
            if (isset($normalized[$record['content_hash']])) throw new InvalidArgumentException('Runtime entity manifest record hashes must be unique.');
            $normalized[$record['content_hash']] = array('content_hash' => $record['content_hash'], 'entity' => RuntimeDeclarations::canonical($record['entity']));
        }
        ksort($normalized, SORT_STRING);
        return array_values($normalized);
    }

    /** @param array<string,mixed> $payload @param array<int,mixed> $records @return array<int,array<string,mixed>> */
    public static function resolve(array $payload, array $records): array
    {
        if (array('entities', 'entity_schema', 'schema') !== array_keys($payload) || self::SCHEMA !== ($payload['schema'] ?? null) || !is_string($payload['entity_schema'] ?? null) || !is_array($payload['entities'] ?? null) || !array_is_list($payload['entities'])) throw new InvalidArgumentException('Runtime entity manifest payload is invalid.');
        $byHash = array_column(self::normalizeRecords($records), 'entity', 'content_hash');
        $entities = array();
        foreach ($payload['entities'] as $reference) {
            $hash = is_array($reference) ? ($reference['content_hash'] ?? null) : null;
            if (!is_array($reference) || array('content_hash') !== array_keys($reference) || !is_string($hash) || !isset($byHash[$hash])) throw new InvalidArgumentException('Runtime entity manifest references an undeclared record.');
            $entities[] = $byHash[$hash];
        }
        return $entities;
    }

    /**
     * Project inline and manifest entities together, retaining their storage form.
     * The callback retains declaration identities and returns unnormalized payloads:
     * expanded manifests may exceed the inline declaration byte budget.
     * @param array<int,array<string,mixed>> $declarations
     * @param array<int,array<string,mixed>> $records
     * @param callable(array):array $project
     * @return array{declarations:array<int,array<string,mixed>>,records:array<int,array<string,mixed>>}
     */
    public static function project(array $declarations, array $records, callable $project): array
    {
        $records = self::normalizeRecords($records);
        $manifests = array();
        foreach ($declarations as &$declaration) {
            if (self::SCHEMA !== ($declaration['payload']['schema'] ?? null)) continue;
            $schema = $declaration['payload']['entity_schema'];
            $manifests[$declaration['reconciliation_identity']] = $schema;
            $declaration['payload'] = array('schema' => $schema, 'entities' => self::resolve($declaration['payload'], $records));
        }
        unset($declaration);
        $declarations = $project($declarations);
        $projectedRecords = array();
        foreach ($declarations as &$declaration) {
            $schema = $manifests[$declaration['reconciliation_identity']] ?? null;
            if (null === $schema) continue;
            $manifest = self::fromEntities($schema, $declaration['payload']['entities']);
            $declaration['payload'] = $manifest['payload'];
            foreach ($manifest['records'] as $record) $projectedRecords[$record['content_hash']] = $record;
        }
        unset($declaration);
        foreach ($declarations as &$declaration) unset($declaration['payload_hash'], $declaration['content_hash']);
        unset($declaration);
        return array('declarations' => RuntimeDeclarations::normalizeList($declarations), 'records' => self::normalizeRecords(array_values($projectedRecords)));
    }
}
