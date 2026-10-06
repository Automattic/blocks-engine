<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler;

use Automattic\BlocksEngine\PhpTransformer\Path\ArtifactPath;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\FormLayoutGraphBuilder;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\FormPresentationGraphBuilder;
use InvalidArgumentException;
use JsonException;

/** Validates caller-declared, destination-independent runtime requirements. */
final class RuntimeDeclarations
{
    private const MAX_DECLARATIONS = 100;
    public const MAX_PROVENANCE_BYTES = ArtifactNormalizer::DEFAULT_MAX_FILE_BYTES;
    public const MAX_PROVENANCE_KEYS = ArtifactNormalizer::DEFAULT_MAX_FILES;
    public const MAX_PROVENANCE_SCALAR_BYTES = ArtifactNormalizer::DEFAULT_MAX_FILE_BYTES;
    public const MAX_PROVENANCE_DEPTH = 32;
    // Declarations are metadata, so keep their aggregate below one artifact file.
    public const MAX_TOTAL_DECLARATION_BYTES = ArtifactNormalizer::DEFAULT_MAX_FILE_BYTES;
    public const MAX_PAYLOAD_BYTES = self::MAX_TOTAL_DECLARATION_BYTES;
    private const MAX_CANONICAL_DEPTH = self::MAX_PROVENANCE_DEPTH + 1;
    public const RECORD_MANIFEST_SCHEMA = 'blocks-engine/runtime-record-references/v1';
    public const RECORD_SCHEMA = 'blocks-engine/runtime-record/v1';

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
        $totalBytes = 0;
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
                $manifest = RuntimeEntityManifest::SCHEMA === ($payload['schema'] ?? null);
                if ($manifest && (!is_string($payload['entity_schema'] ?? null) || !is_array($payload['entities'] ?? null) || !array_is_list($payload['entities']))) throw new InvalidArgumentException("Runtime declaration {$index} entity manifest is invalid.");
                if ('entity_collection' === $kind && 'forms' === $name && 'generic/forms/v1' === ($payload['schema'] ?? null)) foreach ($payload['entities'] ?? array() as $entity) if (is_array($entity) && isset($entity['layout_graph'])) { if (!is_array($entity['layout_graph'])) throw new InvalidArgumentException("Runtime declaration {$index} form layout graph must be an object."); FormLayoutGraphBuilder::assertValid($entity['layout_graph']); }
                if ('entity_collection' === $kind && 'forms' === $name && 'generic/forms/v1' === ($payload['schema'] ?? null)) foreach ($payload['entities'] ?? array() as $entity) if (is_array($entity) && isset($entity['presentation_graph'])) { if (!is_array($entity['presentation_graph'])) throw new InvalidArgumentException("Runtime declaration {$index} form presentation graph must be an object."); FormPresentationGraphBuilder::assertValid($entity['presentation_graph']); }
                if ('entity_collection' === $kind && 'external_metrics' === $name && 'generic/external-metric/v1' === ($payload['schema'] ?? null)) self::assertExternalMetricPayload($payload, $index);
            }
            if ('entity_collection' === $kind && !isset($normalized['type'])) throw new InvalidArgumentException("Runtime declaration {$index} entity collections require a typed entities payload.");
            if ('entity_collection' === $kind && !isset($normalized['payload']['entities']) && self::RECORD_MANIFEST_SCHEMA !== ($normalized['payload']['schema'] ?? null)) throw new InvalidArgumentException("Runtime declaration {$index} entity collections require a typed entities payload.");
            if ('entity_collection' === $kind && isset($normalized['payload']['entities']) && !array_is_list($normalized['payload']['entities'])) throw new InvalidArgumentException("Runtime declaration {$index} entity collections require a typed entities payload.");
            if (self::RECORD_MANIFEST_SCHEMA === ($normalized['payload']['schema'] ?? null)) self::assertRecordManifest($normalized['payload'], $index);
            if (isset($declaration['required_for'])) {
                if (!is_array($declaration['required_for']) || !array_is_list($declaration['required_for']) || array_filter($declaration['required_for'], static fn(mixed $value): bool => !is_string($value) || '' === $value)) throw new InvalidArgumentException("Runtime declaration {$index} required_for must be a list of declaration keys.");
                if (count($declaration['required_for']) !== count(array_unique($declaration['required_for']))) throw new InvalidArgumentException("Runtime declaration {$index} required_for must not contain duplicates.");
                $normalized['required_for'] = array_values($declaration['required_for']);
                sort($normalized['required_for'], SORT_STRING);
            }
            if ('asset_publication' === $kind) {
                $normalized = array_merge($normalized, self::assetPublication($declaration, $index));
            }
            $totalBytes += strlen(self::canonicalJson($normalized));
            if ($totalBytes > self::MAX_TOTAL_DECLARATION_BYTES) throw new InvalidArgumentException("Runtime declarations exceed the aggregate canonical byte limit of " . self::MAX_TOTAL_DECLARATION_BYTES . " at declaration {$index}.");
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

    /** @param array<string,mixed> $declaration @return array<string,mixed> */
    private static function assetPublication(array $declaration, int $index): array
    {
        if ('asset' !== ($declaration['type'] ?? null) || !is_array($declaration['destination'] ?? null) || !is_string($declaration['destination']['capability'] ?? null) || !preg_match('/^[a-z][a-z0-9_-]{0,127}$/', $declaration['destination']['capability']) || !is_bool($declaration['destination']['required'] ?? null) || !is_string($declaration['source_role'] ?? null) || !preg_match('/^[a-z][a-z0-9_-]{0,127}$/', $declaration['source_role']) || !is_string($declaration['mime_type'] ?? null) || !preg_match('#^[a-z0-9.+-]+/[a-z0-9.+-]+$#', $declaration['mime_type']) || !self::isHash($declaration['source_hash'] ?? null) || !self::isHash($declaration['expected_content_hash'] ?? null) || !is_array($declaration['provenance'] ?? null) || ($declaration['provenance']['source_path'] ?? null) !== ($declaration['source_path'] ?? null) || !is_array($declaration['sanitization'] ?? null) || !is_string($declaration['sanitization']['schema'] ?? null) || !self::isHash($declaration['sanitization']['input_hash'] ?? null) || $declaration['sanitization']['input_hash'] !== $declaration['source_hash']) throw new InvalidArgumentException("Runtime declaration {$index} asset publication lacks explicit source, destination, or sanitization proof.");
        if (!is_array($declaration['reference_targets'] ?? null) || !array_is_list($declaration['reference_targets']) || count($declaration['reference_targets']) > self::MAX_DECLARATIONS) throw new InvalidArgumentException("Runtime declaration {$index} asset publication reference targets must be bounded.");
        $targets = array(); $seen = array();
        foreach ($declaration['reference_targets'] as $target) {
            if (!is_array($target) || !is_string($target['target_path'] ?? null) || '' === ArtifactPath::safeRelativePath($target['target_path']) || ArtifactPath::safeRelativePath($target['target_path']) !== $target['target_path'] || !self::isHash($target['write_reconciliation_identity'] ?? null) || !preg_match('/^asset-[a-f0-9]{16}$/', $target['token'] ?? '') || !is_int($target['count'] ?? null) || $target['count'] < 1 || $target['count'] > self::MAX_DECLARATIONS || 'css_url' !== ($target['context'] ?? null)) throw new InvalidArgumentException("Runtime declaration {$index} asset publication has an invalid reference target.");
            $key = strtolower($target['target_path']) . ':' . $target['token'] . ':' . $target['context']; if (isset($seen[$key])) throw new InvalidArgumentException("Runtime declaration {$index} asset publication has a duplicate reference target.");
            $seen[$key] = true; $targets[] = array('target_path' => $target['target_path'], 'write_reconciliation_identity' => $target['write_reconciliation_identity'], 'token' => $target['token'], 'count' => $target['count'], 'context' => $target['context']);
        }
        sort($targets, SORT_STRING);
        $normalized = array('destination' => array('capability' => $declaration['destination']['capability'], 'required' => $declaration['destination']['required']), 'source_role' => $declaration['source_role'], 'mime_type' => strtolower($declaration['mime_type']), 'source_hash' => $declaration['source_hash'], 'expected_content_hash' => $declaration['expected_content_hash'], 'sanitization' => array('schema' => $declaration['sanitization']['schema'], 'input_hash' => $declaration['sanitization']['input_hash']), 'reference_targets' => $targets);
        if (isset($declaration['transformation'])) $normalized['transformation'] = self::assetTransformation($declaration['transformation'], $index);
        return $normalized;
    }

    /** @return array<string,mixed> */
    private static function assetTransformation(mixed $transformation, int $index): array
    {
        if (!is_array($transformation) || 'svg_font_enrichment' !== ($transformation['kind'] ?? null) || !self::isHash($transformation['input_hash'] ?? null) || !self::isHash($transformation['expected_content_hash'] ?? null)) throw new InvalidArgumentException("Runtime declaration {$index} asset transformation is invalid.");
        $paths = array();
        foreach (array('css_source_paths', 'font_source_paths') as $field) {
            if (!is_array($transformation[$field] ?? null) || !array_is_list($transformation[$field]) || count($transformation[$field]) > self::MAX_DECLARATIONS) throw new InvalidArgumentException("Runtime declaration {$index} asset transformation inputs must be bounded lists.");
            $seen = array(); $values = array(); foreach ($transformation[$field] as $path) { if (!is_string($path) || '' === ArtifactPath::safeRelativePath($path) || ArtifactPath::safeRelativePath($path) !== $path || isset($seen[strtolower($path)])) throw new InvalidArgumentException("Runtime declaration {$index} asset transformation has an unsafe input path."); $seen[strtolower($path)] = true; $values[] = $path; } sort($values, SORT_STRING); $paths[$field] = $values;
        }
        if (array_key_exists('font_faces', $transformation) || array() === $paths['css_source_paths'] || array() === $paths['font_source_paths']) throw new InvalidArgumentException("Runtime declaration {$index} asset transformation requires declared local CSS and font inputs.");
        return array_merge(array('kind' => 'svg_font_enrichment', 'input_hash' => $transformation['input_hash'], 'expected_content_hash' => $transformation['expected_content_hash']), $paths);
    }

    private static function isHash(mixed $value): bool { return is_string($value) && 1 === preg_match('/^[a-f0-9]{64}$/', $value); }

    /** @param array<string,mixed> $payload */
    private static function assertExternalMetricPayload(array $payload, int $index): void
    {
        $entities = $payload['entities'] ?? null;
        if (!is_array($entities) || !array_is_list($entities) || array() === $entities || count($entities) > self::MAX_DECLARATIONS) throw new InvalidArgumentException("Runtime declaration {$index} external metrics require a bounded non-empty entity list.");
        $seen = array();
        foreach ($entities as $entity) {
            if (!is_array($entity) || !is_string($entity['id'] ?? null) || !preg_match('/^[a-zA-Z0-9][a-zA-Z0-9._-]{0,127}$/', $entity['id']) || isset($seen[$entity['id']])) throw new InvalidArgumentException("Runtime declaration {$index} external metric entity identity is invalid or duplicated.");
            $seen[$entity['id']] = true;
            if (!is_array($entity['provider'] ?? null) || 'wordpress.org' !== ($entity['provider']['id'] ?? null) || 'generic/external-metric-provider/v1' !== ($entity['provider']['schema'] ?? null) || !is_string($entity['provider']['source'] ?? null) || !in_array($entity['provider']['source'], array('plugin_information', 'plugin_download_history'), true)) throw new InvalidArgumentException("Runtime declaration {$index} external metric provider is unsupported.");
            $source = $entity['provider']['source'];
            $slugs = $entity['provider']['slugs'] ?? null;
            if (!is_array($slugs) || !array_is_list($slugs) || array() === $slugs || count($slugs) > 100) throw new InvalidArgumentException("Runtime declaration {$index} external metric slugs must be a bounded non-empty list.");
            foreach ($slugs as $slug) if (!is_string($slug) || !preg_match('/^[a-z0-9][a-z0-9-]{0,99}$/', $slug)) throw new InvalidArgumentException("Runtime declaration {$index} external metric slug is invalid.");
            if (count($slugs) !== count(array_unique($slugs))) throw new InvalidArgumentException("Runtime declaration {$index} external metric slugs must be unique.");
            $metric = $entity['metric'] ?? null; $aggregation = $entity['aggregation'] ?? null;
            $allowed = 'plugin_information' === $source
                ? array('plugin_response_count' => array('success_count'), 'active_installs' => array('sum'), 'version' => array('identity'), 'num_ratings' => array('identity'))
                : array('downloads_all_time' => array('sum'));
            if (!is_string($metric) || !in_array($aggregation, $allowed[$metric] ?? array(), true) || (in_array($metric, array('version', 'num_ratings'), true) && 1 !== count($slugs))) throw new InvalidArgumentException("Runtime declaration {$index} external metric or aggregation is unsupported for its provider.");
            $format = $entity['format'] ?? null;
            if (!is_array($format) || !is_string($format['locale'] ?? null) || !preg_match('/^[a-zA-Z]{2,3}(?:[-_][a-zA-Z0-9]{2,8})*$/', $format['locale']) || !is_bool($format['grouping'] ?? null) || !in_array($format['prefix'] ?? null, array('', 'v'), true) || !in_array($format['suffix'] ?? null, array('', '+'), true) || !is_int($format['decimals'] ?? null) || $format['decimals'] < 0 || $format['decimals'] > 4) throw new InvalidArgumentException("Runtime declaration {$index} external metric formatting is invalid.");
            if (('version' === $metric) !== ('v' === $format['prefix']) || (in_array($metric, array('active_installs', 'downloads_all_time'), true) !== ('+' === $format['suffix'])) || ('version' === $metric && (true === $format['grouping'] || 0 !== $format['decimals'])) || ('num_ratings' === $metric && '+' === $format['suffix'])) throw new InvalidArgumentException("Runtime declaration {$index} external metric formatting contradicts its metric.");
            $provenance = $entity['provenance'] ?? null;
            if (!is_array($provenance) || !in_array($provenance['kind'] ?? null, array('source_corroboration', 'operator_mapping'), true)) throw new InvalidArgumentException("Runtime declaration {$index} external metric requires explicit source provenance.");
            if ('source_corroboration' === $provenance['kind']) {
                if (!is_string($provenance['repository'] ?? null) || !preg_match('#^[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+$#', $provenance['repository']) || !is_string($provenance['revision'] ?? null) || !preg_match('/^(?:[a-f0-9]{40}|[a-f0-9]{64})$/', $provenance['revision']) || !is_string($provenance['source_path'] ?? null) || !preg_match('#^[A-Za-z0-9_./-]{1,255}$#', $provenance['source_path'])) throw new InvalidArgumentException("Runtime declaration {$index} external metric source corroboration is incomplete.");
            } elseif (!is_string($provenance['author'] ?? null) || '' === trim($provenance['author']) || strlen($provenance['author']) > 255 || !is_string($provenance['source_relationship'] ?? null) || '' === trim($provenance['source_relationship']) || strlen($provenance['source_relationship']) > 1000) throw new InvalidArgumentException("Runtime declaration {$index} external metric operator mapping is incomplete.");
            if (!is_array($entity['fallback'] ?? null) || !is_string($entity['fallback']['text'] ?? null) || strlen($entity['fallback']['text']) > 4096 || !self::isHash($entity['fallback']['hash'] ?? null) || hash('sha256', $entity['fallback']['text']) !== $entity['fallback']['hash']) throw new InvalidArgumentException("Runtime declaration {$index} external metric captured fallback is invalid.");
            $bindings = $entity['bindings'] ?? null;
            if (!is_array($bindings) || !array_is_list($bindings) || 1 !== count($bindings)) throw new InvalidArgumentException("Runtime declaration {$index} external metric requires one native text-leaf binding.");
            $binding = $bindings[0];
            if (!is_array($binding)) throw new InvalidArgumentException("Runtime declaration {$index} external metric native text-leaf binding is invalid.");
            $bindingPath = $binding['source_path'] ?? null;
            if ('generic/block-binding/v1' !== ($binding['schema'] ?? null) || !in_array($binding['role'] ?? null, array('paragraph', 'heading'), true) || !is_string($bindingPath) || '' === ArtifactPath::safeRelativePath($bindingPath) || ArtifactPath::safeRelativePath($bindingPath) !== $bindingPath || !is_string($binding['search_block_markup'] ?? null) || '' === $binding['search_block_markup'] || !is_int($binding['occurrence'] ?? null) || $binding['occurrence'] < 1 || !is_array($binding['leaf'] ?? null) || !in_array($binding['leaf']['block'] ?? null, array('core/paragraph', 'core/heading'), true) || !in_array($binding['leaf']['attribute'] ?? null, array('content'), true) || (($binding['role'] === 'paragraph') !== ($binding['leaf']['block'] === 'core/paragraph'))) throw new InvalidArgumentException("Runtime declaration {$index} external metric native text-leaf binding is invalid.");
            self::assertExternalMetricLeafAnchor($binding['search_block_markup'], $binding['leaf']['block'], $entity['fallback']['text'], $index);
        }
    }

    private static function assertExternalMetricLeafAnchor(string $markup, string $block, string $fallback, int $index): void
    {
        if (strlen($markup) > 65536) throw new InvalidArgumentException("Runtime declaration {$index} external metric native leaf anchor exceeds its byte limit.");
        $name = 'core/paragraph' === $block ? 'paragraph' : 'heading';
        $pattern = '/\A<!--\s*wp:' . preg_quote($name, '/') . '(?:\s+\{.*?\})?\s*-->(.*?)<!--\s*\/wp:' . preg_quote($name, '/') . '\s*-->\z/s';
        if (1 !== preg_match($pattern, $markup, $matches) || str_contains($matches[1], '<!-- wp:')) throw new InvalidArgumentException("Runtime declaration {$index} external metric anchor must be exactly one native {$block} text block.");
        $text = html_entity_decode(strip_tags($matches[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        if ($text !== $fallback) throw new InvalidArgumentException("Runtime declaration {$index} external metric fallback must match its anchored native leaf text.");
    }

    /** @param array<int,array<string,mixed>> $declarations @param array<int,array<string,mixed>> $files @return array<int,array<string,mixed>> */
    public static function bindAssetPublications(array $declarations, array $files): array
    {
        $byPath = array(); foreach ($files as $file) if (is_array($file) && is_string($file['path'] ?? null)) $byPath[$file['path']] = $file;
        $bound = array();
        foreach ($declarations as $declaration) {
            if ('asset_publication' !== ($declaration['kind'] ?? null)) { $bound[] = $declaration; continue; }
            $file = $byPath[$declaration['source_path']] ?? null;
            if (!is_array($file)) throw new InvalidArgumentException('Asset publication provenance references an undeclared normalized artifact file.');
            $provenance = array('source_path' => $file['path'], 'source' => $file['source'], 'hash' => $file['provenance']['hash'] ?? '', 'mime_type' => $file['mime_type'], 'role' => $file['role'], 'bytes' => $file['bytes']);
            if (!is_array($declaration['provenance'] ?? null) || self::canonicalJson($declaration['provenance']) !== self::canonicalJson($provenance)) throw new InvalidArgumentException('Asset publication provenance must exactly match normalized artifact file metadata.');
            unset($declaration['reconciliation_identity'], $declaration['payload_hash'], $declaration['content_hash']); $bound[] = $declaration;
        }
        return self::normalizeList($bound);
    }

    /** @param array<int,mixed> $declarations */
    public static function assertNormalized(array $declarations): void
    {
        if ($declarations !== self::normalizeList($declarations)) throw new InvalidArgumentException('Runtime declarations are not canonically normalized or have stale hashes.');
    }

    /** @param array<int,array<string,mixed>> $declarations @return array{declarations:array<int,array<string,mixed>>,records:array<int,array<string,mixed>>} */
    public static function factor(array $declarations): array
    {
        try { return array('declarations' => self::normalizeList($declarations), 'records' => array()); }
        catch (InvalidArgumentException $error) { if (!str_contains($error->getMessage(), 'payload exceeds the byte limit') && !str_contains($error->getMessage(), 'aggregate canonical byte limit')) throw $error; }
        $records = array();
        foreach ($declarations as $index => &$declaration) {
            $payload = $declaration['payload'] ?? null;
            if (!is_array($payload) || !is_array($payload['entities'] ?? null) || !array_is_list($payload['entities'])) continue;
            $chunks = array(); $chunk = array();
            foreach ($payload['entities'] as $entity) { $candidate = array_merge($chunk, array($entity)); if (strlen(self::canonicalJson(array('schema' => $payload['schema'], 'entities' => $candidate))) > self::MAX_PAYLOAD_BYTES) { if (array() === $chunk) throw new InvalidArgumentException("Runtime declaration {$index} contains an entity that exceeds the byte limit."); $chunks[] = $chunk; $chunk = array($entity); } else $chunk = $candidate; }
            if (array() !== $chunk) $chunks[] = $chunk;
            $references = array(); foreach ($chunks as $chunk) { $recordPayload = array('schema' => $payload['schema'], 'entities' => $chunk); $encoded = self::canonicalJson($recordPayload); $hash = self::hash($recordPayload); $id = 'runtime-record-' . $hash; $records[$id] = array('schema' => self::RECORD_SCHEMA, 'id' => $id, 'content_hash' => $hash, 'bytes' => strlen($encoded), 'payload' => $recordPayload); $references[] = array('id' => $id, 'content_hash' => $hash, 'bytes' => strlen($encoded)); }
            $declaration['payload'] = array('schema' => self::RECORD_MANIFEST_SCHEMA, 'record_schema' => $payload['schema'], 'records' => $references); unset($declaration['reconciliation_identity'], $declaration['payload_hash'], $declaration['content_hash']);
        }
        unset($declaration); ksort($records, SORT_STRING); return array('declarations' => self::normalizeList($declarations), 'records' => array_values($records));
    }

    /**
     * Validate declarations while composition is still editing entity bindings.
     * The bounded record representation is temporary: shell extraction must see
     * every entity, including those whose combined payload exceeds 5 MiB.
     *
     * @param array<int,array<string,mixed>> $declarations
     * @return array<int,array<string,mixed>>
     */
    public static function normalizeForComposition(array $declarations): array
    {
        $factored = self::factor($declarations);
        return self::materialize($factored['declarations'], $factored['records']);
    }

    /** @param array<int,array<string,mixed>> $records @return array<int,array<string,mixed>> */
    public static function normalizeRecords(array $records): array
    {
        if (!array_is_list($records) || count($records) > self::MAX_DECLARATIONS) throw new InvalidArgumentException('Runtime records must be a bounded ordered collection.');
        $normalized = array(); foreach ($records as $index => $record) { if (!is_array($record) || self::RECORD_SCHEMA !== ($record['schema'] ?? null) || !is_string($record['id'] ?? null) || !preg_match('/^runtime-record-[a-f0-9]{64}$/', $record['id']) || !self::isHash($record['content_hash'] ?? null) || !is_int($record['bytes'] ?? null) || $record['bytes'] < 1 || $record['bytes'] > self::MAX_PAYLOAD_BYTES || !is_array($record['payload'] ?? null) || !is_string($record['payload']['schema'] ?? null) || !is_array($record['payload']['entities'] ?? null) || !array_is_list($record['payload']['entities'])) throw new InvalidArgumentException("Runtime record {$index} is invalid."); $payload = self::canonical($record['payload']); $encoded = self::canonicalJson($payload); $hash = self::hash($payload); if ($record['id'] !== 'runtime-record-' . $hash || $record['content_hash'] !== $hash || $record['bytes'] !== strlen($encoded)) throw new InvalidArgumentException("Runtime record {$index} has a stale identity or content hash."); $normalized[] = array('schema' => self::RECORD_SCHEMA, 'id' => $record['id'], 'content_hash' => $hash, 'bytes' => strlen($encoded), 'payload' => $payload); }
        usort($normalized, static fn(array $left, array $right): int => strcmp($left['id'], $right['id'])); if (count(array_unique(array_column($normalized, 'id'))) !== count($normalized)) throw new InvalidArgumentException('Runtime records must have unique content-addressed ids.'); return $normalized;
    }

    /** @param array<int,array<string,mixed>> $declarations @param array<int,array<string,mixed>> $records @return array<int,array<string,mixed>> */
    public static function materialize(array $declarations, array $records): array
    {
        $recordsById = array_column(self::normalizeRecords($records), null, 'id'); foreach ($declarations as &$declaration) { $payload = $declaration['payload'] ?? array(); if (self::RECORD_MANIFEST_SCHEMA !== ($payload['schema'] ?? null)) continue; $entities = array(); foreach ($payload['records'] as $reference) { $record = $recordsById[$reference['id']] ?? null; if (!is_array($record) || $record['content_hash'] !== $reference['content_hash'] || $record['bytes'] !== $reference['bytes'] || $record['payload']['schema'] !== $payload['record_schema']) throw new InvalidArgumentException('Runtime declaration record reference is unresolved or stale.'); array_push($entities, ...$record['payload']['entities']); } $declaration['payload'] = array('schema' => $payload['record_schema'], 'entities' => $entities); } unset($declaration); return $declarations;
    }

    /** @param array<string,mixed> $payload */
    private static function assertRecordManifest(array $payload, int $index): void
    {
        if (!is_string($payload['record_schema'] ?? null) || !is_array($payload['records'] ?? null) || !array_is_list($payload['records']) || array() === $payload['records'] || count($payload['records']) > self::MAX_DECLARATIONS) throw new InvalidArgumentException("Runtime declaration {$index} record manifest is invalid.");
        $seen = array(); foreach ($payload['records'] as $reference) { if (!is_array($reference) || !is_string($reference['id'] ?? null) || !preg_match('/^runtime-record-[a-f0-9]{64}$/', $reference['id']) || !self::isHash($reference['content_hash'] ?? null) || !is_int($reference['bytes'] ?? null) || $reference['bytes'] < 1 || $reference['bytes'] > self::MAX_PAYLOAD_BYTES || $reference['id'] !== 'runtime-record-' . $reference['content_hash'] || isset($seen[$reference['id']])) throw new InvalidArgumentException("Runtime declaration {$index} record manifest has an invalid reference."); $seen[$reference['id']] = true; }
    }

    public static function canonicalJson(mixed $value): string
    {
        try { return json_encode(self::canonical($value), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE); } catch (JsonException) { throw new InvalidArgumentException('Runtime declaration payload is not serializable.'); }
    }

    public static function hash(mixed $value, int $maxDepth = self::MAX_CANONICAL_DEPTH): string
    {
        $context = hash_init('sha256');
        self::updateCanonicalHash($context, $value, $maxDepth);
        return hash_final($context);
    }

    /** @param resource $context */
    private static function updateCanonicalHash($context, mixed $value, int $maxDepth, int $depth = 0): void
    {
        if ($depth > $maxDepth || is_resource($value) || is_object($value)) throw new InvalidArgumentException('Runtime declaration payload contains an unsupported value.');
        if (!is_array($value)) {
            if (is_string($value)) {
                self::updateCanonicalStringHash($context, $value);
                return;
            }
            try { hash_update($context, json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)); } catch (JsonException) { throw new InvalidArgumentException('Runtime declaration payload is not serializable.'); }
            return;
        }
        foreach ($value as $key => $_item) if (!is_int($key) && !is_string($key)) throw new InvalidArgumentException('Runtime declaration payload has an unsupported key.');
        $keys = array_keys($value);
        if (!array_is_list($value)) usort($keys, static fn(int|string $left, int|string $right): int => strcmp((string) $left, (string) $right));
        $list = true;
        foreach ($keys as $index => $key) {
            if ($index !== $key) {
                $list = false;
                break;
            }
        }
        hash_update($context, $list ? '[' : '{');
        foreach ($keys as $index => $key) {
            if (0 < $index) hash_update($context, ',');
            if (!$list) {
                self::updateCanonicalStringHash($context, (string) $key);
                hash_update($context, ':');
            }
            self::updateCanonicalHash($context, $value[$key], $maxDepth, $depth + 1);
        }
        hash_update($context, $list ? ']' : '}');
    }

    /** @param resource $context */
    private static function updateCanonicalStringHash($context, string $value): void
    {
        hash_update($context, '"');
        $length = strlen($value);
        $offset = 0;
        while ($offset < $length) {
            $available = min(65536, $length - $offset);
            $chunkLength = $available;
            while ($offset + $chunkLength < $length && 0x80 === (ord($value[$offset + $chunkLength]) & 0xC0)) --$chunkLength;
            if (0 === $chunkLength) $chunkLength = $available;
            try {
                $encoded = json_encode(substr($value, $offset, $chunkLength), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            } catch (JsonException) {
                throw new InvalidArgumentException('Runtime declaration payload is not serializable.');
            }
            hash_update($context, substr($encoded, 1, -1));
            $offset += $chunkLength;
        }
        hash_update($context, '"');
    }

    public static function canonical(mixed $value, int $depth = 0): mixed
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
