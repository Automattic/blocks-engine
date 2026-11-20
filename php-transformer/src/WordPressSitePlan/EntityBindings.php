<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan;

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\RuntimeDeclarations;
use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\RuntimeEntityManifest;
use Automattic\BlocksEngine\PhpTransformer\Support\ArrayField;
use Automattic\BlocksEngine\PhpTransformer\Support\BlockMarkupIndex;
use Automattic\BlocksEngine\PhpTransformer\Support\HtmlTagScanner;
use Closure;
use InvalidArgumentException;

/**
 * Canonicalizes the runtime entity bindings one site plan run declares.
 *
 * A binding anchors runtime behavior (a collection, a search, a form) to the
 * block markup that renders it. Projection rewrites that markup, so each pass
 * re-reads the binding's source markup through the run's route links,
 * recomputes its anchor on the projected documents, and drops a binding whose
 * anchor no longer exists, recording why as a unit finding.
 */
final class EntityBindings
{
    /** @param Closure(array<string,mixed>):void $recordFinding */
    public function __construct(private readonly RouteLinks $links, private readonly Closure $recordFinding)
    {
    }

    /**
     * Canonicalize every entity binding's search markup through the same asset
     * and route projections used for its source page.
     *
     * @param array<int,array<string,mixed>> $declarations
     * @param array<int,array<string,mixed>> $records
     * @return array<int,array<string,mixed>>
     */
    public function canonical(array $declarations, array &$records, AssetReferenceCanonicalizer $references, array $pages, array $parts = array(), array $paginationOwners = array()): array
    {
        $projection = RuntimeEntityManifest::project($declarations, $records, fn(array $inline): array => $this->canonicalInline($inline, $references, $pages, $parts, $paginationOwners));
        $records = $projection['records'];
        return $projection['declarations'];
    }

    /**
     * The source anchor a plan keeps beside a binding's canonical anchor, so the
     * binding can be found again after its page markup is reprojected.
     *
     * @param array<string,mixed> $binding
     * @return array<string,mixed>
     */
    public static function projectedAnchor(array $binding): array
    {
        // The anchor identifies the binding's source block by digest and length:
        // the markup itself already travels as the binding's
        // `search_block_markup`, and reprojection only tests exact equality.
        $sourceMarkup = is_string($binding['search_block_markup'] ?? null) ? $binding['search_block_markup'] : null;
        return array_filter(array('schema' => 'blocks-engine/projected-binding-anchor/v2', 'source_block_hash' => null === $sourceMarkup ? null : hash('sha256', $sourceMarkup), 'source_block_length' => null === $sourceMarkup ? null : strlen($sourceMarkup), 'source_occurrence' => $binding['occurrence'] ?? null, 'source_position' => $binding['position'] ?? null), static fn(mixed $value): bool => null !== $value);
    }

    private function canonicalInline(array $declarations, AssetReferenceCanonicalizer $references, array $pages, array $parts = array(), array $paginationOwners = array()): array
    {
        [$variantSources, $templateTargets] = self::templateSurfaceSources($parts, $paginationOwners);
        $sourceBindings = $this->declaredBindingKeys($declarations);
        [$pages, $partSources] = self::withPartDocuments($pages, $parts);
        $declarations = $this->projectBindingMarkup($declarations, $references, $templateTargets, $partSources);
        $declarations = self::withSharedCartLinks($declarations, $parts);

        $markupBySource = array_column($pages, 'canonical_block_markup', 'source_path');
        $sourceMarkupBySource = array_column($pages, '_projected_source_block_markup', 'source_path');
        // A page that synced patterns took content from is matched on its markup
        // before that projection; each resolved block is then rebased below.
        $patternSources = array_column($pages, '_synced_pattern_source', 'source_path');
        $projectedMarkupBySource = $markupBySource;
        foreach ($patternSources as $source => $patternSource) $markupBySource[$source] = $patternSource['markup'];

        // A binding whose block is not emitted has nothing to bind: the block
        // stays static. These are dropped below and reported, not thrown.
        [$groups, $unanchored] = self::bindingGroups($declarations, $markupBySource);
        $anchored = array();
        // Groups on one page share its document and projected source markup;
        // index each distinct document once for this pass.
        $indexes = array();
        $indexOf = static function (string $markup) use (&$indexes): BlockMarkupIndex {
            return $indexes[$markup] ??= new BlockMarkupIndex($markup);
        };
        foreach ($groups as $bindings) {
            $claims = self::groupClaims($bindings);
            $markup = $bindings[0]['markup']; $search = $bindings[0]['search'];
            $documentIndex = $indexOf($markup);
            // Claims beyond the emitted matching blocks find no candidate below and are dropped.
            $claimedOffsets = array(); $resolved = array();
            foreach ($claims as $members) {
                $identity = $members[0];
                $anchor = $declarations[$identity['declaration']]['payload']['entities'][$identity['entity']]['bindings'][$identity['binding']]['projected_anchor'] ?? null;
                $sourceMarkup = $sourceMarkupBySource[$identity['source']] ?? null;
                $range = self::claimRange($anchor, $identity, $markup, $search, $documentIndex, is_string($sourceMarkup) ? $indexOf($sourceMarkup) : null, $claimedOffsets);
                if (null === $range) {
                    foreach ($members as $member) $unanchored[] = array('declaration' => $member['declaration'], 'entity' => $member['entity'], 'binding' => $member['binding'], 'reason_code' => 'anchor_not_emitted');
                    continue;
                }
                $claimedOffsets[$range['offset']] = true;
                foreach ($members as $member) $resolved[] = array($member, $range);
            }
            foreach ($resolved as [$identity, $range]) {
                $binding = &$declarations[$identity['declaration']]['payload']['entities'][$identity['entity']]['bindings'][$identity['binding']];
                $patternSource = $patternSources[$identity['source']] ?? null;
                $landing = null === $patternSource ? null : SyncedPatternProjection::rebase($patternSource, $projectedMarkupBySource[$identity['source']], $range);
                $binding = self::boundToRange($binding, $range, $markup, $documentIndex, $landing);
                // A copy of pattern content: the pattern document's own binding replaces it.
                if (null === $landing || !$landing['duplicate']) $anchored[$identity['id']] = true;
                unset($binding);
            }
        }

        [$declarations, $unanchored] = self::withAbsorbedVariants($declarations, $unanchored, $anchored, $variantSources, $sourceBindings, $markupBySource);
        return $this->forComposition(SyncedPatternProjection::collapseDuplicateBindings($this->withoutUnanchored($declarations, $unanchored)));
    }

    /**
     * Where each captured variant of a shared template surface belongs, and the
     * target each surface's own source path writes to.
     *
     * @return array{0: array<string,string>, 1: array<string,string>}
     */
    private static function templateSurfaceSources(array $parts, array $paginationOwners): array
    {
        $variantSources = array(); $templateTargets = array();
        foreach ($parts as $part) if (isset($part['template_surface'])) {
            $templateTargets[$part['source_path']] = $part['target_path'];
            foreach ($part['template_surface']['source_variants'] as $variant) if ($variant['source_path'] !== $part['source_path']) $variantSources[$variant['source_path']] = $part['source_path'];
        }
        // Numbered captures of a listing are absorbed by the listing's own document.
        foreach ($paginationOwners as $source => $owner) $variantSources[$source] ??= $owner;
        return array($variantSources, $templateTargets);
    }

    /**
     * Each binding's declared semantics, keyed by declaration, entity and
     * binding index. Compared before projections can erase differences.
     *
     * @return array<int|string,array<int|string,array<int|string,?array{0:string,1:string,2:mixed}>>>
     */
    private function declaredBindingKeys(array $declarations): array
    {
        $sourceBindings = array();
        foreach ($declarations as $di => $declaration) foreach ($declaration['payload']['entities'] ?? array() as $ei => $entity) {
            $type = (string) ($declaration['type'] ?? '');
            if ('forms' === $type) {
                $source = $entity['source_path'] ?? null;
                $action = $entity['form']['action'] ?? null;
                if (is_string($source) && is_string($action) && $this->isSelfPostAction($action, $source)) $entity['form']['action'] = 'blocks-engine:self-post';
                foreach ($entity['controls'] ?? array() as $ci => $control) unset($entity['controls'][$ci]['selector']);
                // Row selectors locate equivalent controls positionally too; keep the layout facts, not capture-specific locations.
                foreach ($entity['presentation_graph']['control_rows'] ?? array() as $ri => $row) {
                    unset($entity['presentation_graph']['control_rows'][$ri]['source_selector']);
                    foreach ($row['members'] ?? array() as $mi => $member) unset($entity['presentation_graph']['control_rows'][$ri]['members'][$mi]['source_selector']);
                }
                // Layout graph nodes locate the same controls positionally.
                foreach ($entity['layout_graph']['nodes'] ?? array() as $ni => $node) if (is_array($node['source'] ?? null)) unset($entity['layout_graph']['nodes'][$ni]['source']['selector']);
            }
            $key = EntityEquivalence::key($entity, $type);
            foreach ($entity['bindings'] ?? array() as $bi => $binding) $sourceBindings[$di][$ei][$bi] = null === $key ? null : array($type, $key, $binding['selector'] ?? null);
        }
        return $sourceBindings;
    }

    /**
     * Adds every part with final markup as a document bindings can anchor on,
     * and names the non-template parts, whose markup is already projected.
     *
     * @return array{0: array<int,array<string,mixed>>, 1: array<string,true>}
     */
    private static function withPartDocuments(array $pages, array $parts): array
    {
        $partSources = array();
        foreach ($parts as $part) if (is_string($part['source_path'] ?? null) && is_string($part['canonical_block_markup'] ?? null)) {
            if (!isset($part['template_surface'])) $partSources[$part['source_path']] = true;
            $pages[] = array('source_path' => $part['source_path'], 'canonical_block_markup' => $part['canonical_block_markup'], '_projected_source_block_markup' => $part['_projected_source_block_markup'] ?? $part['canonical_block_markup']);
        }
        return array($pages, $partSources);
    }

    /** Projects entity and binding markup through the source page's asset and route projections. */
    private function projectBindingMarkup(array $declarations, AssetReferenceCanonicalizer $references, array $templateTargets, array $partSources): array
    {
        // Many entities quote the same source block: every product of a
        // collection carries the whole grid as its search markup. Projection
        // depends only on the markup and its source page, so each distinct
        // pair is projected once per pass instead of once per binding.
        $projected = array();
        $project = function (string $markup, string $source) use ($references, &$projected): string {
            return $projected[$source][$markup] ??= $this->links->markup($references->content($markup, $source), $source);
        };
        foreach ( $declarations as &$declaration ) {
            if ( ! is_array($declaration) || ! isset($declaration['payload']['entities']) || ! is_array($declaration['payload']['entities']) ) {
                continue;
            }
            foreach ( $declaration['payload']['entities'] as &$entity ) {
                if ( ! is_array($entity) || ! isset($entity['bindings']) || ! is_array($entity['bindings']) ) {
                    continue;
                }
                if (is_string($entity['source_path'] ?? null)) {
                    $entity = EntityMarkup::map($entity, static fn(string $markup): string => $project($markup, $entity['source_path']));
                }
                foreach ( $entity['bindings'] as &$binding ) {
                    if ( is_array($binding) && is_string($binding['search_block_markup'] ?? null) && is_string($binding['source_path'] ?? null) ) {
                        $sourceMarkup = $binding['search_block_markup'];
                        if (!is_array($binding['projected_anchor'] ?? null)) $binding['projected_anchor'] = self::projectedAnchor($binding);
                        if (isset($templateTargets[$binding['source_path']])) $binding['target_path'] = $templateTargets[$binding['source_path']];
                        // A binding owned by a shared part was re-anchored on the part's final
                        // markup when the chrome moved; it needs no source projection, and the
                        // part markup is its own projected source.
                        if (isset($partSources[$binding['source_path']])) continue;
                        $binding['search_block_markup'] = $project($sourceMarkup, $binding['source_path']);
                    }
                }
                unset($binding);
            }
            unset($entity);
        }
        unset($declaration);
        return $declarations;
    }

    /**
     * A shared header may retain a page copy (for example a captured
     * responsive variant). Cart navigation is one capability of that shared
     * control: prefer its exact template-part anchor, not a second entity for
     * every route that carries the same opener.
     */
    private static function withSharedCartLinks(array $declarations, array $parts): array
    {
        foreach ($declarations as &$declaration) {
            if ('cart_links' !== ($declaration['type'] ?? null)) continue;
            $cartLinks = array();
            foreach ($declaration['payload']['entities'] as $entity) {
                foreach ($entity['bindings'] as &$binding) {
                    $matches = array();
                    foreach ($parts as $part) {
                        if ('header' !== ($part['area'] ?? null)) continue;
                        $partMarkup = $part['canonical_block_markup'] ?? '';
                        $search = $binding['search_block_markup'];
                        if (1 === substr_count($partMarkup, $search)) $matches[] = $part['source_path'];
                    }
                    if (1 === count($matches)) {
                        $binding['source_path'] = $matches[0];
                        // The part is its own projected source. Its anchor must
                        // describe the rewritten markup, not a route's asset
                        // paths or links from before canonical projection.
                        $binding['projected_anchor'] = self::projectedAnchor($binding);
                    }
                }
                unset($binding);
                if (isset($entity['bindings'][0])) $entity['source_path'] = $entity['bindings'][0]['source_path'];
                $key = $entity['source_path'] . "\n" . ($entity['bindings'][0]['search_block_markup'] ?? $entity['selector']);
                $cartLinks[$key] = $entity;
            }
            $declaration['payload']['entities'] = array_values($cartLinks);
        }
        unset($declaration);
        return $declarations;
    }

    /**
     * Groups bindings by the document and block markup they search for, and
     * lists the bindings whose document is not emitted.
     *
     * @param array<string,string> $markupBySource
     * @return array{0: array<string,list<array<string,mixed>>>, 1: list<array{declaration:int|string,entity:int|string,binding:int|string,reason_code:string}>}
     */
    private static function bindingGroups(array $declarations, array $markupBySource): array
    {
        $groups = array(); $unanchored = array();
        foreach ($declarations as $declarationIndex => $declaration) foreach ($declaration['payload']['entities'] ?? array() as $entityIndex => $entity) foreach (is_array($entity) ? ($entity['bindings'] ?? array()) : array() as $bindingIndex => $binding) {
            $source = $binding['source_path'] ?? null; $search = $binding['search_block_markup'] ?? null; $position = $binding['position'] ?? null;
            $markup = is_string($source) ? ($markupBySource[$source] ?? null) : null;
            if (!is_string($source) || !is_string($search) || '' === $search || !is_int($binding['occurrence'] ?? null) || $binding['occurrence'] < 1) throw new InvalidArgumentException('A runtime entity binding lacks an exact emitted block anchor.');
            if (!is_string($markup)) { $unanchored[] = array('declaration' => $declarationIndex, 'entity' => $entityIndex, 'binding' => $bindingIndex, 'reason_code' => 'document_not_emitted'); continue; }
            if (is_array($position) && ('blocks-engine/runtime-binding-position/v1' !== ($position['schema'] ?? null) || !is_int($position['block_index'] ?? null) || $position['block_index'] < 0 || !is_int($position['offset'] ?? null) || $position['offset'] < 0 || !is_int($position['length'] ?? null) || $position['length'] < 1)) throw new InvalidArgumentException('A runtime entity binding has an invalid emitted block position.');
            $groups[$source . "\n" . $search][] = array('id' => $declarationIndex . ':' . $entityIndex . ':' . $bindingIndex, 'source' => $source, 'declaration' => $declarationIndex, 'entity' => $entityIndex, 'binding' => $bindingIndex, 'source_offset' => is_array($position) ? $position['offset'] : null, 'source_occurrence' => $binding['occurrence'], 'markup' => $markup, 'search' => $search, 'role' => is_string($binding['role'] ?? null) ? $binding['role'] : null);
        }
        return array($groups, $unanchored);
    }

    /**
     * The claims one group's bindings make, in source order: each claim is the
     * bindings that share one source position.
     *
     * A `commerce_collection` binding is deliberately shared, byte for byte, by
     * every product entity a detected grid covers (see
     * `CommerceFallbackReporter`): one page region, many entities, one
     * canonical claim -- not N entities racing for the same position. Two
     * DIFFERENT claims (any role, or a non-collection role repeating a
     * position) fail closed. A `commerce_product_grid` binding names that same
     * whole-grid region for a provider product loop, so it joins the grid's
     * shared claim.
     *
     * @param list<array<string,mixed>> $bindings
     * @return list<list<array<string,mixed>>>
     */
    private static function groupClaims(array $bindings): array
    {
        usort($bindings, static fn(array $left, array $right): int => array($left['source_offset'] ?? PHP_INT_MAX, $left['source_occurrence'], $left['declaration'], $left['entity'], $left['binding']) <=> array($right['source_offset'] ?? PHP_INT_MAX, $right['source_occurrence'], $right['declaration'], $right['entity'], $right['binding']));
        $clusters = array();
        foreach ($bindings as $identity) {
            $key = is_int($identity['source_offset']) ? 'offset:' . $identity['source_offset'] : 'occurrence:' . $identity['source_occurrence'];
            $clusters[$key][] = $identity;
        }
        foreach ($clusters as $members) {
            if (1 < count($members) && array() !== array_filter($members, static fn(array $member): bool => ! in_array($member['role'], array('commerce_collection', 'commerce_product_grid'), true))) throw new InvalidArgumentException('A runtime entity binding has ambiguous canonical source-page anchors.');
        }
        return array_values($clusters);
    }

    /**
     * The unclaimed canonical block a claim's projected anchor lands on, or
     * null when no unclaimed emitted block carries its markup at all (the
     * anchor is gone). An unclaimed match that cannot be told apart is
     * ambiguous and fails closed.
     *
     * @param array<int,true> $claimedOffsets
     * @return array{offset:int,length:int}|null
     */
    private static function claimRange(mixed $anchor, array $identity, string $markup, string $search, BlockMarkupIndex $documentIndex, ?BlockMarkupIndex $sourceIndex, array $claimedOffsets): ?array
    {
        if (!is_array($anchor) || 'blocks-engine/projected-binding-anchor/v2' !== ($anchor['schema'] ?? null) || !is_string($anchor['source_block_hash'] ?? null) || !is_int($anchor['source_block_length'] ?? null)) throw new InvalidArgumentException('A runtime entity binding no longer identifies one exact emitted canonical block.');
        $isAnchorSource = static fn(string $markup, array $range): bool => $anchor['source_block_length'] === $range['length'] && hash_equals($anchor['source_block_hash'], hash('sha256', substr($markup, $range['offset'], $range['length'])));
        if (null === $sourceIndex) throw new InvalidArgumentException('A runtime entity binding no longer identifies one exact emitted canonical block.');
        $sourceMarkup = $sourceIndex->markup;
        $sourceRanges = $sourceIndex->ranges(); $canonicalRanges = $documentIndex->ranges();
        $sourceMatches = array_values(array_filter($sourceRanges, static fn(array $range): bool => $isAnchorSource($sourceMarkup, $range)));
        if (isset($anchor['source_occurrence_count']) && $anchor['source_occurrence_count'] !== count($sourceMatches)) throw new InvalidArgumentException('A runtime entity binding source anchor is ambiguous after reprojection.');
        $canonicalMatches = array_values(array_filter($canonicalRanges, static fn(array $range): bool => $search === substr($markup, $range['offset'], $range['length'])));
        $candidates = array();
        // Shell extraction changes raw block indexes, not the occurrence
        // order of equal blocks. Prefer equal-sized match sets by ordinal.
        if (count($sourceMatches) === count($canonicalMatches) && array() !== $canonicalMatches) {
            $occurrence = is_int($anchor['source_occurrence'] ?? null) ? $anchor['source_occurrence'] : (is_int($identity['source_occurrence'] ?? null) ? $identity['source_occurrence'] : null);
            $index = is_int($occurrence) ? $occurrence - 1 : null;
            if (is_int($index) && isset($sourceMatches[$index], $canonicalMatches[$index]) && !isset($claimedOffsets[$canonicalMatches[$index]['offset']])) {
                $candidates[] = array('source' => $sourceMatches[$index], 'canonical' => $canonicalMatches[$index]);
            }
        } else {
            // Distinct source blocks can canonicalize to the same markup
            // (e.g. route aliases). Preserve their positional disambiguation.
            foreach ($sourceRanges as $index => $sourceRange) {
                $canonicalRange = $canonicalRanges[$index] ?? null;
                if ($isAnchorSource($sourceMarkup, $sourceRange) && $anchor['source_occurrence'] === HtmlTagScanner::occurrenceAtOffset($sourceMarkup, substr($sourceMarkup, $sourceRange['offset'], $sourceRange['length']), $sourceRange['offset']) && is_array($canonicalRange) && $search === substr($markup, $canonicalRange['offset'], $canonicalRange['length']) && !isset($claimedOffsets[$canonicalRange['offset']])) $candidates[] = array('source' => $sourceRange, 'canonical' => $canonicalRange);
            }
            $unclaimedCanonical = array_values(array_filter($canonicalMatches, static fn(array $range): bool => !isset($claimedOffsets[$range['offset']])));
            if (array() === $candidates && 1 === count($sourceMatches) && 1 === count($unclaimedCanonical)) $candidates[] = array('source' => $sourceMatches[0], 'canonical' => $unclaimedCanonical[0]);
        }
        if (array() === $candidates && array() === array_filter($canonicalMatches, static fn(array $range): bool => !isset($claimedOffsets[$range['offset']]))) return null;
        if (1 !== count($candidates)) throw new InvalidArgumentException('A runtime entity binding no longer identifies one exact emitted canonical block.');
        return $candidates[0]['canonical'];
    }

    /**
     * A binding re-anchored on its resolved canonical block. A block that synced
     * pattern content moved is rebased onto the pattern document; a copy of
     * pattern content is only marked as the duplicate its pattern replaces.
     *
     * @param array{offset:int,length:int} $range
     * @param array{source_path:?string,markup:string,range:array{offset:int,length:int},duplicate:bool,claim:?string}|null $landing
     */
    private static function boundToRange(array $binding, array $range, string $markup, BlockMarkupIndex $documentIndex, ?array $landing): array
    {
        if (null !== $landing) {
            if ($landing['duplicate']) { $binding['_synced_pattern_duplicate'] = $landing['claim']; return $binding; }
            $markup = $landing['markup'];
            $documentIndex = new BlockMarkupIndex($markup);
            $range = $landing['range'];
        }
        $canonical = substr($markup, $range['offset'], $range['length']);
        $blockIndex = array_search($range, $documentIndex->ranges(), true);
        if (!is_string($canonical) || '' === $canonical || !is_int($blockIndex)) throw new InvalidArgumentException('A runtime entity binding resolved to empty canonical block markup.');
        $binding['search_block_markup'] = $canonical;
        $binding['occurrence'] = HtmlTagScanner::occurrenceAtOffset($markup, $canonical, $range['offset']);
        $binding['position'] = array('schema' => 'blocks-engine/runtime-binding-position/v1', 'block_index' => $blockIndex, 'offset' => $range['offset'], 'length' => $range['length']);
        if (is_string($landing['source_path'] ?? null)) {
            $binding['source_path'] = $landing['source_path'];
            $binding['projected_anchor'] = self::projectedAnchor($binding);
            $binding['_synced_pattern_claim'] = $landing['claim'];
        }
        return $binding;
    }

    /**
     * Absorbs a binding on an unemitted variant document into the semantically
     * equivalent entity of the document that replaced it, only when that
     * selected binding anchored. Missing or different forms keep their
     * unanchored diagnostic.
     *
     * @return array{0: array<int,array<string,mixed>>, 1: list<array<string,mixed>>}
     */
    private static function withAbsorbedVariants(array $declarations, array $unanchored, array $anchored, array $variantSources, array $sourceBindings, array $markupBySource): array
    {
        foreach ($unanchored as $index => $drop) {
            if ('document_not_emitted' !== $drop['reason_code']) continue;
            $variantEntity = $declarations[$drop['declaration']]['payload']['entities'][$drop['entity']];
            $variantBinding = $variantEntity['bindings'][$drop['binding']];
            $selected = $variantSources[$variantBinding['source_path']] ?? null;
            $fallback = $variantEntity['fallback_identity'] ?? null;
            if (null === $selected || !is_string($fallback) || '' === $fallback) continue;
            $matches = array();
            foreach ($declarations as $di => $declaration) foreach ($declaration['payload']['entities'] ?? array() as $ei => $entity) foreach ($entity['bindings'] ?? array() as $bi => $binding) {
                if (!isset($anchored[$di . ':' . $ei . ':' . $bi]) || $selected !== ($binding['source_path'] ?? null) || null === $sourceBindings[$di][$ei][$bi] || $sourceBindings[$di][$ei][$bi] !== $sourceBindings[$drop['declaration']][$drop['entity']][$drop['binding']] || !BindingAnchors::positionMatches($binding['position'] ?? null, new BlockMarkupIndex($markupBySource[$selected]), $binding['search_block_markup'])) continue;
                $matches[] = array($di, $ei);
            }
            if (1 !== count($matches)) continue;
            [$di, $ei] = $matches[0];
            $canonical = &$declarations[$di]['payload']['entities'][$ei];
            $identities = array_values(array_unique(array_merge($canonical['replaced_fallback_identities'] ?? array(), array_filter(array($canonical['fallback_identity'] ?? null, $fallback)), $variantEntity['replaced_fallback_identities'] ?? array())));
            sort($identities, SORT_STRING);
            $canonical['replaced_fallback_identities'] = $identities;
            unset($canonical);
            unset($declarations[$drop['declaration']]['payload']['entities'][$drop['entity']]['bindings'][$drop['binding']], $unanchored[$index]);
        }
        return array($declarations, $unanchored);
    }

    /**
     * Reindexes bindings, drops the hashes their rewritten markup invalidated,
     * and screens each collection's final projected entities before the
     * declarations are normalized for composition.
     */
    private function forComposition(array $declarations): array
    {
        foreach ($declarations as &$declaration) {
            foreach ($declaration['payload']['entities'] ?? array() as $ei => $entity) if (isset($entity['bindings'])) $declaration['payload']['entities'][$ei]['bindings'] = array_values($entity['bindings']);
        }
        unset($declaration);

        // Rewriting binding markup changes the payload, so drop the derived
        // hashes and re-normalize to recompute canonical identity and content
        // hashes; the reconciliation identity (source path + kind) is stable.
        foreach ( $declarations as &$declaration ) {
            if ( is_array($declaration) ) {
                unset($declaration['payload_hash'], $declaration['content_hash']);
            }
        }
        unset($declaration);

        // Composition can grow a previously representable entity's bindings.
        // Screen the final projected form before factoring or rebuilding manifests.
        foreach ($declarations as &$declaration) {
            if ('entity_collection' !== ($declaration['kind'] ?? null) || !is_array($declaration['payload']['entities'] ?? null)) continue;
            $screened = RuntimeEntityManifest::screenEntities($declaration['payload']['entities'], $declaration['payload']['schema'], $declaration['source_path'] ?? '');
            $declaration['payload']['entities'] = $screened['entities'];
            foreach ($screened['diagnostics'] as $finding) ($this->recordFinding)($finding);
        }
        unset($declaration);
        return RuntimeDeclarations::normalizeForComposition($declarations);
    }

    /** Resolve against source documents, including surfaces absent from page routes. */
    private function isSelfPostAction(string $action, string $source): bool
    {
        if ('' === $action) return true;
        if (preg_match('~^(?://|#|\?)~', $action) || strpbrk($action, '?#') !== false) return false;
        if (preg_match('~^[a-z][a-z0-9+.-]*:~i', $action)) {
            if (!$this->links->isSameOrigin($action)) return false;
            $action = (string) (parse_url($action, PHP_URL_PATH) ?: '/');
        }
        $root = $this->links->entryRoot();
        $path = str_starts_with($action, '/') ? ('' === $root ? ltrim($action, '/') : $root . '/' . ltrim($action, '/')) : RouteLinks::resolveSource($source, $action);
        if (null === $path) return false;
        $document = static fn(string $path): string => preg_replace('~(?:^|/)index\.html?$~', '', rtrim($path, '/'));
        return rtrim($document($source), '/') === rtrim($document($path), '/');
    }

    /**
     * Drops entity bindings whose block is not emitted and reports each one as
     * a `runtime_entity_binding_unanchored` finding. The entity keeps its other
     * bindings; the unbound block stays static.
     *
     * @param array<int,array<string,mixed>> $declarations
     * @param list<array{declaration:int|string,entity:int|string,binding:int|string,reason_code:string}> $unanchored
     * @return array<int,array<string,mixed>>
     */
    private function withoutUnanchored(array $declarations, array $unanchored): array
    {
        if (array() === $unanchored) return $declarations;
        foreach ($unanchored as $drop) {
            $declaration = $declarations[$drop['declaration']];
            $entity = $declaration['payload']['entities'][$drop['entity']];
            $binding = $entity['bindings'][$drop['binding']];
            ($this->recordFinding)(array_filter(array(
                'code' => 'runtime_entity_binding_unanchored',
                'unit' => 'binding',
                'message' => self::unanchoredMessage($drop['reason_code']),
                'source_path' => (string) $binding['source_path'],
                'reason_code' => $drop['reason_code'],
                'declaration_source_path' => ArrayField::string($declaration, 'source_path'),
                'entity_id' => ArrayField::string($entity, 'id'),
                'role' => ArrayField::string($binding, 'role'),
            ), static fn(string $value): bool => '' !== $value));
            unset($declarations[$drop['declaration']]['payload']['entities'][$drop['entity']]['bindings'][$drop['binding']]);
        }
        foreach ($unanchored as $drop) {
            $bindings = &$declarations[$drop['declaration']]['payload']['entities'][$drop['entity']]['bindings'];
            $bindings = array_values($bindings);
            unset($bindings);
        }
        return $declarations;
    }

    private static function unanchoredMessage(string $reason): string
    {
        return 'document_not_emitted' === $reason
            ? 'A runtime entity binding names a document the plan does not emit; the binding was dropped.'
            : 'A runtime entity binding names a block the page does not emit; the binding was dropped and the block stays static.';
    }
}
