<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan;

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\RuntimeDeclarations;
use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\RuntimeEntityManifest;
use InvalidArgumentException;

/**
 * Where a runtime entity binding is anchored in the documents that render it.
 *
 * A binding names its source document, the block markup it binds, the
 * occurrence of that markup, and the exact block position
 * (`blocks-engine/runtime-binding-position/v1`). The plan proves each anchor
 * against the document's block index whenever it builds or validates
 * documents, along with every script an entity supersedes.
 */
final class BindingAnchors
{
    /**
     * Declarations with every entity whose bindings the plan's documents must
     * anchor. Record references are materialized. A manifest's entities are its
     * resolved records; once the plan is resolved, their anchors are the ones
     * resolution projected onto the resolved documents.
     *
     * @param array<string,mixed> $plan @param array<int,array<string,mixed>> $runtimeRecords @param array<int,array<string,mixed>> $entityRecords
     * @return array<int,array<string,mixed>>
     */
    public static function anchoredDeclarations(array $plan, array $runtimeRecords, array $entityRecords): array
    {
        $resolved = isset($plan['resolution']) && is_array($plan['runtime_entity_resolution'] ?? null) ? array_column($plan['runtime_entity_resolution'], 'entities', 'reconciliation_identity') : array();
        $declarations = RuntimeDeclarations::materialize($plan['runtime_declarations'], $runtimeRecords);
        foreach ($declarations as &$declaration) {
            if (RuntimeEntityManifest::SCHEMA !== ($declaration['payload']['schema'] ?? null)) continue;
            $entities = isset($plan['resolution']) ? ($resolved[$declaration['reconciliation_identity']] ?? null) : RuntimeEntityManifest::resolve($declaration['payload'], $entityRecords);
            if (!is_array($entities)) throw new InvalidArgumentException('A resolved WordPress site plan does not resolve every runtime entity manifest.');
            $declaration['payload'] = array('schema' => $declaration['payload']['entity_schema'], 'entities' => $entities);
        }
        unset($declaration);
        return $declarations;
    }

    /**
     * Every entity binding names the document that renders its block: a page,
     * or a shared template part that chrome containing the entity moved into.
     *
     * @param array<int,array<string,mixed>> $declarations @param array<int,array<string,mixed>> $pages @param array<int,array<string,mixed>> $parts @param array<int,array<string,mixed>> $assets
     */
    public static function assertAnchored(array $declarations, array $pages, array $parts, array $assets): void
    {
        $markupBySource = array();
        foreach (array_merge($pages, $parts) as $page) if (is_string($page['source_path'] ?? null) && is_string($page['canonical_block_markup'] ?? null)) $markupBySource[$page['source_path']] = is_string($page['resolved_block_markup'] ?? null) ? $page['resolved_block_markup'] : $page['canonical_block_markup'];
        $assetsBySource = array_column($assets, null, 'source_path');
        $scriptsBySource = array();
        foreach ( $pages as $page ) foreach ( $page['document_metadata']['scripts'] ?? array() as $script ) if ( is_array($script) && is_string($script['selector'] ?? null) ) $scriptsBySource[$page['source_path'] . "\n" . $script['selector']] = $script;
        foreach ( $declarations as $declaration ) {
            foreach ( $declaration['payload']['entities'] ?? array() as $entity ) {
                $bindings = is_array($entity) && is_array($entity['bindings'] ?? null) ? $entity['bindings'] : array();
                $bindingSources = array_fill_keys(array_filter(array_column($bindings, 'source_path'), 'is_string'), true);
                foreach ( $bindings as $binding ) {
                    $source = $binding['source_path'] ?? null; $search = $binding['search_block_markup'] ?? null; $occurrence = $binding['occurrence'] ?? null;
                    $ownedMarkup = $markupBySource[$source] ?? null;
                    $position = $binding['position'] ?? null;
                    $offset = is_string($ownedMarkup) && is_string($search) && is_int($occurrence) ? self::occurrenceOffset($ownedMarkup, $search, $occurrence) : null;
                    if ( !is_string($source) || !is_string($search) || '' === $search || !is_int($occurrence) || $occurrence < 1 || !is_string($ownedMarkup) || null === $offset || (null !== $position && (!self::positionMatches($position, new \Automattic\BlocksEngine\PhpTransformer\Support\BlockMarkupIndex($ownedMarkup), $search) || $position['offset'] !== $offset)) ) throw new InvalidArgumentException('A runtime entity binding no longer has its declared source document block anchor after shell extraction: ' . (is_string($source) ? $source : 'unknown') . ' (' . (is_string($binding['role'] ?? null) ? $binding['role'] : 'unknown') . ').');
                }
                $formId = is_array($entity) && is_array($entity['form'] ?? null) && is_string($entity['form']['id'] ?? null) ? $entity['form']['id'] : '';
                foreach ( is_array($entity) && is_array($entity['superseded_scripts'] ?? null) ? $entity['superseded_scripts'] : array() as $supersession ) {
                    if ( !is_array($supersession) || array('asset_source_path','body_hash','reason','schema','selector','source_path','target_selector') !== array_keys($supersession) || 'blocks-engine/provider-script-supersession/v1' !== $supersession['schema'] || !isset($bindingSources[$supersession['source_path']]) || !preg_match('/^script:nth-of-type\([1-9][0-9]*\)$/', $supersession['selector']) || !WordPressSitePlan::safePath($supersession['asset_source_path']) || !WordPressSitePlan::hash($supersession['body_hash']) || '#' . $formId !== $supersession['target_selector'] || 'provider_binding_replaces_form_behavior' !== $supersession['reason'] ) throw new InvalidArgumentException('A provider script supersession proof is malformed or detached from its bound form.');
                    $script = $scriptsBySource[$supersession['source_path'] . "\n" . $supersession['selector']] ?? null;
                    $asset = $assetsBySource[$supersession['asset_source_path']] ?? null;
                    $assetReference = is_array($asset) && is_string($asset['token'] ?? null) ? '{{wordpress-site-plan:asset:' . $asset['token'] . '}}' : null;
                    if ( !is_array($script) || ('inline' !== ($script['source_kind'] ?? null) && $assetReference !== ($script['asset_reference'] ?? null)) || $supersession['body_hash'] !== ($script['body_hash'] ?? null) || $supersession['target_selector'] !== ($script['superseded_by'] ?? null) || !is_array($asset) || 'inline-script' !== ($asset['source'] ?? null) || !is_string($asset['content'] ?? null) || $supersession['body_hash'] !== hash('sha256', trim($asset['content'])) || $supersession['body_hash'] !== ($asset['hash'] ?? null) ) throw new InvalidArgumentException('A provider script supersession proof does not match its source inline-script asset and document metadata.');
                }
            }
        }
    }

    /** @param array<string,mixed>|mixed $position */
    public static function positionMatches(mixed $position, \Automattic\BlocksEngine\PhpTransformer\Support\BlockMarkupIndex $documentIndex, string $search): bool
    {
        $markup = $documentIndex->markup;
        if (!is_array($position) || 'blocks-engine/runtime-binding-position/v1' !== ($position['schema'] ?? null) || !is_int($position['block_index'] ?? null) || $position['block_index'] < 0 || !is_int($position['offset'] ?? null) || $position['offset'] < 0 || !is_int($position['length'] ?? null) || $position['length'] < 1) return false;
        $range = $documentIndex->ranges()[$position['block_index']] ?? null;
        return null !== $range && $range['offset'] === $position['offset'] && $range['length'] === $position['length'] && $search === substr($markup, $range['offset'], $range['length']);
    }

    private static function occurrenceOffset(string $markup, string $search, int $occurrence): ?int
    {
        if ('' === $search || $occurrence < 1) return null;
        $cursor = 0;
        for ($index = 0; $index < $occurrence; ++$index) { $cursor = strpos($markup, $search, $cursor); if (false === $cursor) return null; if ($index + 1 < $occurrence) $cursor += strlen($search); }
        return $cursor;
    }
}
