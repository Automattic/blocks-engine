<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler;

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
