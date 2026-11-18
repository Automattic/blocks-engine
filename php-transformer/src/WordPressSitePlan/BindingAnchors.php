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

    /**
     * Bindings re-anchored after `$rewrite` changed their documents without
     * touching a bound block's bytes: each block moves to where the rewritten
     * prefix before it ends. Null when a bound block did not survive byte for
     * byte, so the caller keeps the documents it had.
     *
     * @param array<int,array<string,mixed>> $declarations @param array<int,array<string,mixed>> $records
     * @param array<string,string> $before @param array<string,string> $after Document markup by source path.
     * @param callable(string):string $rewrite
     * @return array{declarations:array<int,array<string,mixed>>,records:array<int,array<string,mixed>>}|null
     */
    public static function rebased(array $declarations, array $records, array $before, array $after, callable $rewrite): ?array
    {
        $indexes = array();
        $detached = false;
        $rebase = static function (array $binding) use ($before, $after, $rewrite, &$indexes, &$detached): array {
            $source = $binding['source_path'] ?? null; $search = $binding['search_block_markup'] ?? null; $occurrence = $binding['occurrence'] ?? null;
            if (!is_string($source) || !isset($before[$source], $after[$source]) || $before[$source] === $after[$source] || !is_string($search) || !is_int($occurrence)) return $binding;
            $offset = is_int($binding['position']['offset'] ?? null) ? $binding['position']['offset'] : self::occurrenceOffset($before[$source], $search, $occurrence);
            $moved = null === $offset ? null : strlen($rewrite(substr($before[$source], 0, $offset)));
            $index = $indexes[$source] ??= new \Automattic\BlocksEngine\PhpTransformer\Support\BlockMarkupIndex($after[$source]);
            $blockIndex = null;
            foreach ($index->ranges() as $candidate => $range) if ($range['offset'] === $moved && $search === substr($after[$source], $range['offset'], $range['length'])) { $blockIndex = $candidate; break; }
            if (null === $blockIndex) {
                $detached = true;
                return $binding;
            }
            $binding['occurrence'] = \Automattic\BlocksEngine\PhpTransformer\Support\HtmlTagScanner::occurrenceAtOffset($after[$source], $search, $moved);
            if (isset($binding['position'])) $binding['position'] = array('schema' => 'blocks-engine/runtime-binding-position/v1', 'block_index' => $blockIndex, 'offset' => $moved, 'length' => strlen($search));
            return $binding;
        };
        $projection = RuntimeEntityManifest::project($declarations, $records, static function (array $inline) use ($rebase): array {
            foreach ($inline as &$declaration) foreach ($declaration['payload']['entities'] ?? array() as $entityIndex => $entity) {
                if (!is_array($entity) || !is_array($entity['bindings'] ?? null)) continue;
                foreach ($entity['bindings'] as $bindingIndex => $binding) if (is_array($binding)) $declaration['payload']['entities'][$entityIndex]['bindings'][$bindingIndex] = $rebase($binding);
            }
            unset($declaration);
            return $inline;
        });
        return $detached ? null : $projection;
    }

    /** @param array<string,mixed>|mixed $position */
    public static function positionMatches(mixed $position, \Automattic\BlocksEngine\PhpTransformer\Support\BlockMarkupIndex $documentIndex, string $search): bool
    {
        $markup = $documentIndex->markup;
        if (!is_array($position) || 'blocks-engine/runtime-binding-position/v1' !== ($position['schema'] ?? null) || !is_int($position['block_index'] ?? null) || $position['block_index'] < 0 || !is_int($position['offset'] ?? null) || $position['offset'] < 0 || !is_int($position['length'] ?? null) || $position['length'] < 1) return false;
        $range = $documentIndex->ranges()[$position['block_index']] ?? null;
        return null !== $range && $range['offset'] === $position['offset'] && $range['length'] === $position['length'] && $search === substr($markup, $range['offset'], $range['length']);
    }

    /** Byte offset of the `$occurrence`th (1-based) `$search` in `$markup`, or null when absent. */
    public static function occurrenceOffset(string $markup, string $search, int $occurrence): ?int
    {
        if ('' === $search || $occurrence < 1) return null;
        $cursor = 0;
        for ($index = 0; $index < $occurrence; ++$index) { $cursor = strpos($markup, $search, $cursor); if (false === $cursor) return null; if ($index + 1 < $occurrence) $cursor += strlen($search); }
        return $cursor;
    }
}
