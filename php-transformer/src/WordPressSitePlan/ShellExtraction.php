<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan;

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\RuntimeDeclarations;
use Automattic\BlocksEngine\PhpTransformer\Support\EngineMarker;
use Automattic\BlocksEngine\PhpTransformer\Support\RenderEquivalentMarkup;
use Automattic\BlocksEngine\PhpTransformer\Support\ShellLandmarkPolicy;

/**
 * Sole owner of header/footer shell-candidate location and removal.
 *
 * Candidates are identified once against the block tree (comment-token
 * ancestry, not a serialized-string search), and that same structural
 * position is threaded through to removal. A byte-identical fragment
 * appearing more than once in a page's canonical markup therefore no
 * longer manufactures false ambiguity: the candidate already knows where
 * it lives. When no structural position is available (a declared
 * top-level shell artifact carries only markup, not an offset), removal
 * falls back to the previous string-search behavior, preserving output
 * for the existing corpus.
 */
final class ShellExtraction
{
    public function __construct(private readonly WordPressSitePlan $plan)
    {
    }

    /** @param array<string,mixed> $document @return array<int,array<string,mixed>> */
    public function shellCandidates(array $document, AssetReferenceCanonicalizer $references, array $routes, string $canonical): array
    {
        $candidates = array();
        foreach ($document['shell_artifacts'] ?? array() as $candidate) {
            if (!is_array($candidate) || !in_array($candidate['area'] ?? null, array('header', 'footer'), true) || !is_string($candidate['block_markup'] ?? null) || '' === trim($candidate['block_markup'])) continue;
            $sourcePath = WordPressSitePlan::value($document, 'source_path');
            $markup = $this->plan->routeLinks($references->content($candidate['block_markup'], $sourcePath), $sourcePath, $routes);
            $classes = array_values(array_filter($candidate['source_classes'] ?? array(), 'is_string'));
            sort($classes, SORT_STRING);
            $innerMarkup = is_string($candidate['inner_block_markup'] ?? null) ? $this->plan->routeLinks($references->content($candidate['inner_block_markup'], $sourcePath), $sourcePath, $routes) : $markup;
            $templatePartMarkup = is_string($candidate['template_part_block_markup'] ?? null) ? $this->plan->routeLinks($references->content($candidate['template_part_block_markup'], $sourcePath), $sourcePath, $routes) : $innerMarkup;
            // One rendered part serves every route; a page's own current item
            // is restored at render time, never frozen into the shared part.
            $templatePartMarkup = self::withoutCurrentNavigationState($templatePartMarkup);
            // Resolve this declared candidate's block-tree position now, while it is
            // still guaranteed unique for its area. Later removal reuses this
            // position directly instead of re-deriving it by searching for the
            // candidate's bytes, which a coincidental duplicate elsewhere in the
            // page could otherwise make ambiguous.
            $range = $this->topLevelShellRange($canonical, (string) $candidate['area'], $markup);
            $identity = self::normalizeNestedChromeMarkup($markup);
            $row = array('area' => $candidate['area'], 'markup' => $markup, 'inner_markup' => $innerMarkup, 'template_part_markup' => $templatePartMarkup, 'identity_markup' => $identity, 'classes' => $classes, 'source_path' => $sourcePath, 'source_hash' => is_string($candidate['source_hash'] ?? null) ? $candidate['source_hash'] : '');
            if (is_array($range)) { $row['offset'] = $range['offset']; $row['length'] = $range['length']; }
            $candidates[] = $row;
        }
        $sourcePath = WordPressSitePlan::value($document, 'source_path');
        $occupiedAreas = array_column($candidates, 'area');
        $nestedLandmarks = $this->nestedLandmarkShellCandidates($canonical, $sourcePath, $occupiedAreas);
        if (array() !== $nestedLandmarks) {
            return array_merge($candidates, $nestedLandmarks);
        }
        return array_merge($candidates, $this->nestedChromeCandidates($canonical, $sourcePath, $occupiedAreas));
    }

    /**
     * Nested unlabeled chrome: a leading sibling that contains navigation and
     * is not the main content, plus an optional trailing colophon group.
     *
     * The last-wrapper two-child shape (checkbox toggle + group of navigation
     * and page content) is one instance of that model and keeps its legacy
     * container fields so existing theme reconstruction stays intact.
     *
     * @param array<int,string> $occupiedAreas
     * @return array<int,array<string,mixed>>
     */
    private function nestedChromeCandidates(string $markup, string $sourcePath, array $occupiedAreas = array()): array
    {
        $legacy = $this->legacyTwoChildChromeCandidate($markup, $sourcePath);
        if (array() !== $legacy) {
            return $legacy;
        }
        return $this->unlabeledChromeCandidates($markup, $sourcePath, $occupiedAreas);
    }

    /** @return array<int,array<string,mixed>> */
    private function legacyTwoChildChromeCandidate(string $markup, string $sourcePath): array
    {
        $topLevel = self::topLevelBlockRanges($markup);
        foreach ($topLevel as $index => $wrapperRange) {
            $wrapper = substr($markup, $wrapperRange['offset'], $wrapperRange['length']);
            $preceding = $topLevel[$index - 1] ?? null;
            $toggle = is_array($preceding) ? substr($markup, $preceding['offset'], $preceding['length']) : '';
            // The responsive core/navigation overlay supersedes the only allowed
            // sibling: its legacy authored checkbox toggle.
            if (count($topLevel) !== $index + 1 || (0 < $index && (1 !== $index || !self::isCheckboxBlock($toggle)))) {
                continue;
            }
            $children = self::directChildBlockRanges($wrapper);
            if (2 !== count($children) || !self::isGroupBlock($wrapper)) {
                continue;
            }
            $navigationChildren = array_values(array_filter(
                $children,
                static fn(array $range): bool => str_contains(substr($wrapper, $range['offset'], $range['length']), '<!-- wp:navigation ')
            ));
            if (1 !== count($navigationChildren)) {
                continue;
            }
            $chromeRange = $navigationChildren[0];
            $contentRange = current(array_filter($children, static fn(array $range): bool => $range !== $chromeRange));
            if (!is_array($contentRange) || !self::isGroupBlock(substr($wrapper, $contentRange['offset'], $contentRange['length']))) {
                continue;
            }
            $chrome = substr($wrapper, $chromeRange['offset'], $chromeRange['length']);
            $content = substr($wrapper, $contentRange['offset'], $contentRange['length']);
            $opening = self::blockOpeningMarkup($wrapper);
            if (null === $opening) {
                continue;
            }
            $contentOffset = $wrapperRange['offset'] + $contentRange['offset'];
            $identity = self::normalizeNestedChromeMarkup($chrome);
            if ('' === $identity) {
                continue;
            }
            return array(array(
                'area' => 'header',
                'markup' => $chrome,
                'inner_markup' => $chrome,
                'template_part_markup' => self::withoutCurrentNavigationState($chrome),
                'identity_markup' => $identity,
                'classes' => array(),
                'source_path' => $sourcePath,
                'source_hash' => hash('sha256', $chrome),
                'legacy_container_opening' => $opening,
                'legacy_container_closing' => '<!-- /wp:group -->',
                'legacy_content_markup' => $content,
                'legacy_content_range' => array('offset' => $contentOffset, 'length' => $contentRange['length']),
                'legacy_page_markup' => $markup,
            ));
        }
        return array();
    }

    /**
     * @param array<int,string> $occupiedAreas
     * @return array<int,array<string,mixed>>
     */
    private function unlabeledChromeCandidates(string $markup, string $sourcePath, array $occupiedAreas): array
    {
        $headers = array();
        $footers = array();
        $this->collectNestedChrome($markup, 0, $headers, $footers);
        $candidates = array();
        foreach (array('header' => $headers, 'footer' => $footers) as $area => $rows) {
            if (in_array($area, $occupiedAreas, true) || array() === $rows) {
                continue;
            }
            $identities = array_column($rows, 'identity_markup');
            if (1 !== count(array_unique($identities))) {
                continue;
            }
            $row = $rows[0];
            if ('' === ($row['identity_markup'] ?? '')) {
                continue;
            }
            $additional = array();
            foreach (array_slice($rows, 1) as $extra) {
                $additional[] = array(
                    'offset' => $extra['offset'],
                    'length' => $extra['length'],
                    'markup' => $extra['markup'],
                );
            }
            $partMarkup = self::withoutLandmarkTagName(self::withoutCurrentNavigationState($row['markup']));
            $candidates[] = array(
                'area' => $area,
                'markup' => $row['markup'],
                'inner_markup' => $row['markup'],
                'template_part_markup' => $partMarkup,
                'identity_markup' => $row['identity_markup'],
                'classes' => array(),
                'source_path' => $sourcePath,
                'source_hash' => $row['source_hash'],
                'nested_shell' => true,
                'shared_only' => true,
                'offset' => $row['offset'],
                'length' => $row['length'],
                'additional_ranges' => $additional,
            );
        }
        return $candidates;
    }

    /**
     * @param array<int,array<string,mixed>> $headers
     * @param array<int,array<string,mixed>> $footers
     */
    private function collectNestedChrome(string $markup, int $baseOffset, array &$headers, array &$footers): void
    {
        $ranges = self::topLevelBlockRanges($markup);
        if (2 <= count($ranges)) {
            $first = substr($markup, $ranges[0]['offset'], $ranges[0]['length']);
            if (self::containsNavigation($first) && !self::containsMainLandmark($first) && !self::chromeSplitIsInside($first)) {
                $restHasContent = false;
                foreach (array_slice($ranges, 1) as $range) {
                    $sibling = substr($markup, $range['offset'], $range['length']);
                    if (!self::isEmptyVisualGroup($sibling)) {
                        $restHasContent = true;
                        break;
                    }
                }
                if ($restHasContent) {
                    $headers[] = $this->nestedChromeRow($first, $baseOffset + $ranges[0]['offset'], $ranges[0]['length']);
                    $last = $ranges[count($ranges) - 1];
                    $lastMarkup = substr($markup, $last['offset'], $last['length']);
                    if ($last !== $ranges[0] && self::isFooterChrome($lastMarkup)) {
                        $footers[] = $this->nestedChromeRow($lastMarkup, $baseOffset + $last['offset'], $last['length']);
                    }
                    return;
                }
            }
        }
        foreach ($ranges as $range) {
            $block = substr($markup, $range['offset'], $range['length']);
            $children = self::directChildBlockRanges($block);
            if (array() === $children) {
                continue;
            }
            $innerStart = $children[0]['offset'];
            $innerEnd = $children[count($children) - 1]['offset'] + $children[count($children) - 1]['length'];
            $this->collectNestedChrome(
                substr($block, $innerStart, $innerEnd - $innerStart),
                $baseOffset + $range['offset'] + $innerStart,
                $headers,
                $footers
            );
        }
    }

    /** @return array<string,mixed> */
    private function nestedChromeRow(string $candidateMarkup, int $offset, int $length): array
    {
        return array(
            'markup' => $candidateMarkup,
            'identity_markup' => self::nestedChromeIdentity($candidateMarkup),
            'source_hash' => hash('sha256', $candidateMarkup),
            'offset' => $offset,
            'length' => $length,
        );
    }

    private static function nestedChromeIdentity(string $markup): string
    {
        return self::normalizeNestedChromeMarkup(self::unwrapChromeContainers($markup));
    }

    /**
     * Peel engine-introduced layout-transparent carriers and extra single-child
     * unlabeled wrappers so chrome that sits at different wrapper depths still
     * shares one identity. Layout-transparent wrappers are marked by a
     * `wrappers` attribute or `display:contents`, not by generated block names.
     */
    private static function unwrapChromeContainers(string $markup): string
    {
        $markup = trim($markup);
        while (preg_match('/^<!--\s*wp:\S+/', $markup)) {
            $children = self::directChildBlockRanges($markup);
            if (array() === $children) {
                break;
            }
            if (!self::isLayoutTransparentBlock($markup) && !self::isExtraDepthWrapper($markup, $children)) {
                break;
            }
            $inner = '';
            foreach ($children as $range) {
                $inner .= substr($markup, $range['offset'], $range['length']);
            }
            if ('' === $inner || $inner === $markup) {
                break;
            }
            $markup = trim($inner);
        }
        return $markup;
    }

    private static function isLayoutTransparentBlock(string $markup): bool
    {
        if (!preg_match('/^<!--\s*wp:\S+\s+(\{.*?\})\s*-->/s', ltrim($markup), $match)) {
            return false;
        }
        $attrs = json_decode($match[1], true);
        if (!is_array($attrs)) {
            return false;
        }
        if (isset($attrs['wrappers']) && is_array($attrs['wrappers'])) {
            return true;
        }
        if (isset($attrs['config'])) {
            return true;
        }
        $style = $attrs['style'] ?? null;
        if (is_array($style) && 'contents' === ($style['display'] ?? null)) {
            return true;
        }
        return is_string($style) && 1 === preg_match('/display\s*:\s*contents/i', $style);
    }

    /** @param array<int,array{offset:int,length:int}> $children */
    private static function isExtraDepthWrapper(string $markup, array $children): bool
    {
        if (1 !== count($children) || !self::isGroupBlock($markup)) {
            return false;
        }
        if (!preg_match('/^<!--\s*wp:group(?:\s+(\{.*?\}))?\s*-->/s', ltrim($markup), $match)) {
            return false;
        }
        $attrs = isset($match[1]) && '' !== $match[1] ? json_decode($match[1], true) : array();
        $tag = is_array($attrs) ? strtolower((string) ($attrs['tagName'] ?? 'div')) : 'div';
        return !in_array($tag, array('header', 'footer', 'main', 'nav'), true);
    }

    private static function containsNavigation(string $markup): bool
    {
        return str_contains($markup, '<!-- wp:navigation ') || str_contains($markup, '<!-- wp:navigation{');
    }

    /**
     * True when the chrome/content split lives among this block's children, so
     * the walker should enter it instead of treating the whole block as header.
     * Extra wrappers around header-only chrome have no later content child and
     * stay the header candidate.
     */
    private static function chromeSplitIsInside(string $markup): bool
    {
        $children = self::directChildBlockRanges($markup);
        if (count($children) < 2) {
            return false;
        }
        $first = substr($markup, $children[0]['offset'], $children[0]['length']);
        if (!self::containsNavigation($first) || self::containsMainLandmark($first)) {
            return false;
        }
        foreach (array_slice($children, 1) as $range) {
            $sibling = substr($markup, $range['offset'], $range['length']);
            if (self::containsMainLandmark($sibling) || self::isHeadingBlock($sibling)) {
                return true;
            }
            if (!self::isEmptyVisualGroup($sibling) && !self::isFooterChrome($sibling) && !self::containsNavigation($sibling)) {
                return true;
            }
        }
        return false;
    }

    private static function containsMainLandmark(string $markup): bool
    {
        return str_contains($markup, '"tagName":"main"')
            || str_contains($markup, '<main ')
            || str_contains($markup, '<main>')
            || str_contains($markup, '<!-- wp:post-content');
    }

    private static function isEmptyVisualGroup(string $markup): bool
    {
        if (!preg_match('/^<!--\s*wp:group(?:\s+(\{.*?\}))?\s*-->/s', ltrim($markup), $match)) {
            return false;
        }
        $attrs = isset($match[1]) && '' !== $match[1] ? json_decode($match[1], true) : array();
        $className = is_array($attrs) ? (string) ($attrs['className'] ?? '') : '';
        if (str_contains($className, 'blocks-engine-empty-visual-group')) {
            return true;
        }
        return 1 >= substr_count($markup, '<!-- wp:');
    }

    private static function isFooterChrome(string $markup): bool
    {
        if (self::containsMainLandmark($markup) || self::isEmptyVisualGroup($markup) || self::containsNavigation($markup) || self::isHeadingBlock($markup)) {
            return false;
        }
        if (str_contains($markup, '<!-- wp:heading') || str_contains($markup, '<!-- wp:post-content')) {
            return false;
        }
        return 1 === preg_match('/^<!--\s*wp:group(?:\s|\{)/', ltrim($markup));
    }

    private static function isHeadingBlock(string $markup): bool
    {
        return 1 === preg_match('/^<!--\s*wp:heading(?:\s|\{)/', ltrim($markup));
    }

    /** @param array<int,string> $occupiedAreas @return array<int,array<string,mixed>> */
    private function nestedLandmarkShellCandidates(string $markup, string $sourcePath, array $occupiedAreas): array
    {
        $candidates = array();
        foreach (array('header', 'footer') as $area) {
            if (in_array($area, $occupiedAreas, true)) continue;
            $rows = $this->nestedLandmarkCandidates($markup, $sourcePath, $area);
            if (array() === $rows) continue;
            $identities = array_column($rows, 'identity_markup');
            if (1 !== count(array_unique($identities))) continue;
            $row = $rows[0];
            $identity = $row['identity_markup'];
            if ('' === $identity) continue;
            $partMarkup = self::withoutLandmarkTagName(self::withoutCurrentNavigationState($row['markup']));
            $additional = array();
            foreach (array_slice($rows, 1) as $extra) $additional[] = array('offset' => $extra['offset'], 'length' => $extra['length'], 'markup' => $extra['markup']);
            // The candidate's own block-tree offset is carried forward so removal
            // never needs to re-derive its position by searching for its bytes.
            $candidates[] = array('area' => $area, 'markup' => $row['markup'], 'inner_markup' => $row['markup'], 'template_part_markup' => $partMarkup, 'identity_markup' => $identity, 'classes' => array(), 'source_path' => $sourcePath, 'source_hash' => $row['source_hash'], 'nested_shell' => true, 'wrapper_contract_shell' => !empty($row['wrapper_contract_shell']), 'offset' => $row['offset'], 'length' => $row['length'], 'additional_ranges' => $additional, 'ancestor_context' => $row['ancestor_context'] ?? null);
        }
        return $candidates;
    }

    /** @param array<int,array<string,mixed>> $pages @param array<string,true> $reservedSlugs @param array<int,array<string,mixed>> $runtimeDeclarations @return array{pages:array<int,array<string,mixed>>,parts:array<int,array<string,mixed>>,runtime_declarations:array<int,array<string,mixed>>,diagnostics:array<int,array<string,mixed>>} */
    public function inlineSharedShells(array $pages, array $reservedSlugs, array $runtimeDeclarations, array $canonicalArtifacts = array()): array
    {
        if (count(array_filter($pages, static fn(array $page): bool => empty($page['synthetic']))) < 2) return array('pages' => $pages, 'parts' => array(), 'runtime_declarations' => $runtimeDeclarations, 'diagnostics' => array());
        $parts = array(); $diagnostics = array();
        foreach (array('header', 'footer') as $area) {
            $applicable = array_filter($pages, static fn(array $page): bool => empty($page['synthetic']));
            $sourcePaths = array_values(array_map(static fn(array $page): string => $page['source_path'], $applicable));
            $areaArtifacts = array_values(array_filter($canonicalArtifacts, static fn(array $artifact): bool => $area === ($artifact['area'] ?? null)));
            usort($areaArtifacts, static fn(array $left, array $right): int => ($left['variant'] ?? 0) <=> ($right['variant'] ?? 0));
            $candidates = array(); $variantCount = null; $rejected = false;
            foreach ($applicable as $index => $page) {
                $rows = $this->nestedLandmarkCandidates($page['canonical_block_markup'], $page['source_path'], $area);
                foreach ($rows as $row) if (null !== self::responsiveVariantClass($row)) continue 3;
                if (array() === $rows) { $rejected = true; break; }
                $count = self::logicalNestedVariantCount($rows);
                if (null !== $variantCount && $variantCount !== $count) { $rejected = true; break; }
                $variantCount = $count; $candidates[$index] = $rows;
                foreach ($rows as $candidate) if ($this->shellContainsRuntimeBinding($runtimeDeclarations, $page, $candidate['offset'], $candidate['length'])) { $rejected = true; break 2; }
            }
            if ($rejected || null === $variantCount || 1 === $variantCount) continue;
            $expectedSources = $sourcePaths; sort($expectedSources, SORT_STRING);
            $canonical = count($areaArtifacts) === $variantCount;
            foreach ($areaArtifacts as $artifact) {
                $artifactSources = $artifact['placement']['source_paths'] ?? array();
                if (!is_array($artifactSources)) { $canonical = false; break; }
                sort($artifactSources, SORT_STRING);
                if ($artifactSources !== $expectedSources) { $canonical = false; break; }
            }
            $variants = array();
            for ($variant = 0; $variant < $variantCount; ++$variant) {
                if ($canonical) {
                    $variants[] = (string) ($areaArtifacts[$variant]['content_hash'] ?? '');
                    continue;
                }
                $identities = array(); foreach ($candidates as $rows) $identities[] = $rows[$variant]['identity_markup'];
                if (1 !== count(array_unique($identities))) { $rejected = true; break; }
                $variants[] = $identities[0];
            }
            if ($rejected) continue;
            $slugs = array();
            for ($variant = 0; $variant < $variantCount; ++$variant) {
                $slug = 1 === $variantCount ? $area : $area . '-' . ($variant + 1);
                if (isset($reservedSlugs[$slug])) { $rejected = true; break; }
                $slugs[] = $slug;
            }
            if ($rejected) continue;
            foreach ($candidates as $index => $rows) foreach ($rows as $candidate) {
                if ($candidate['markup'] !== substr($pages[$index]['canonical_block_markup'], $candidate['offset'], $candidate['length'])) { $rejected = true; break 2; }
            }
            if ($rejected) continue;
            foreach ($candidates as $index => $rows) {
                usort($rows, static fn(array $left, array $right): int => $right['offset'] <=> $left['offset']);
                $markup = $pages[$index]['canonical_block_markup'];
                foreach ($rows as $candidate) {
                    $variant = $candidate['variant']; $slug = $slugs[$variant];
                    $reference = '<!-- wp:template-part {"slug":"' . $slug . '","area":"' . $area . '","tagName":"div"} /-->';
                    $markup = substr($markup, 0, $candidate['offset']) . $reference . substr($markup, $candidate['offset'] + $candidate['length']);
                }
                $pages[$index]['canonical_block_markup'] = $markup;
                $pages[$index]['content_hash'] = WordPressSitePlan::contentHash($markup);
            }
            foreach ($slugs as $variant => $slug) {
                $first = $candidates[array_key_first($candidates)][$variant];
                $artifact = $canonical ? $areaArtifacts[$variant] : array();
                $sourcePath = 'wordpress-site-plan/shared/' . $slug;
                $candidateRows = array(); foreach ($candidates as $index => $rows) $candidateRows[$index] = array($rows[$variant]);
                $title = ucfirst($area) . (1 === $variantCount ? '' : ' Variant ' . ($variant + 1));
                $partMarkup = $canonical ? (string) ($artifact['canonical_block_markup'] ?? '') : $first['markup'];
                $partMarkup = self::withoutCurrentNavigationState($partMarkup);
                $parts[] = array('source_path' => $sourcePath . '#' . $area, 'slug' => $slug, 'title' => $title, 'post_type' => 'wp_template_part', 'parent_source_path' => '', 'entrypoint' => false, 'area' => $area, 'tag_name' => ShellLandmarkPolicy::templatePartAreaTagName($area), 'placement' => array('kind' => 'inline_shared_shell', 'source_path' => $sourcePath, 'source_paths' => $sourcePaths, 'variant' => $variant + 1), 'canonical_block_markup' => $partMarkup, 'metadata' => array(), 'document_metadata' => array('source_context' => array('source_path' => $sourcePath . '#' . $area, 'kind' => 'template_part'), 'title' => $title, 'title_declaration' => array('order' => 0, 'placement' => 'head'), 'meta' => array(), 'links' => array(), 'scripts' => array()), 'provenance' => $this->shellProvenance($area, 'extracted', 'inline_responsive_variant', $candidateRows, hash('sha256', $variants[$variant])), 'reconciliation_identity' => WordPressSitePlan::identity('template-part', $sourcePath . '#' . $area, 'parts/' . $slug . '.html'), 'content_hash' => WordPressSitePlan::contentHash($partMarkup));
            }
            $diagnostics[] = array('code' => 'wordpress_site_plan_shell_inline_extracted', 'severity' => 'info', 'message' => "Extracted nested responsive {$area} variants at their authored page positions.", 'area' => $area, 'variant_count' => $variantCount, 'page_count' => count($applicable), 'source_paths' => $sourcePaths);
        }
        return array('pages' => $pages, 'parts' => $parts, 'runtime_declarations' => $runtimeDeclarations, 'diagnostics' => $diagnostics);
    }

    /**
     * Byte ranges of a document's blocks that runtime entity bindings anchor on.
     *
     * @param array<int,array<string,mixed>> $declarations
     * @param array<string,mixed> $document
     * @return list<array{offset:int,length:int}>
     */
    private function boundBlockRanges(array $declarations, array $document): array
    {
        $markup = (string) ($document['canonical_block_markup'] ?? '');
        $ranges = array();
        foreach ($declarations as $declaration) foreach ($declaration['payload']['entities'] ?? array() as $entity) foreach (is_array($entity) ? ($entity['bindings'] ?? array()) : array() as $binding) {
            if (($binding['source_path'] ?? null) !== ($document['source_path'] ?? null)) continue;
            $position = $binding['position'] ?? null;
            $search = $binding['search_block_markup'] ?? null;
            if (is_string($search) && WordPressSitePlan::bindingPosition($position, $markup, $search)) $ranges[] = array('offset' => $position['offset'], 'length' => $position['length']);
        }
        return $ranges;
    }

    /**
     * Factor footer copy that is identical across otherwise distinct footer
     * wrappers. The original wrappers stay where they were authored, while one
     * editable template part owns the shared paragraph.
     *
     * @param array<int,array<string,mixed>> $pages
     * @param array<int,array<string,mixed>> $parts
     * @return array{pages:array<int,array<string,mixed>>,parts:array<int,array<string,mixed>>,diagnostics:array<int,array<string,mixed>>}
     */
    public function factorSharedFooterContent(array $pages, array $parts, array $runtimeDeclarations = array()): array
    {
        // A block a runtime entity binding anchors on is replaced whole by its
        // provider (a form's labels live inside it), so its copy never moves.
        $bound = array();
        foreach (array('page' => $pages, 'part' => $parts) as $kind => $rows) foreach ($rows as $index => $row) {
            $ranges = $this->boundBlockRanges($runtimeDeclarations, $row);
            if (array() !== $ranges) $bound[$kind . ':' . $index] = $ranges;
        }
        $documents = array(); $pageRegionCounts = array();
        foreach ($pages as $index => $page) {
            $markup = (string) ($page['canonical_block_markup'] ?? '');
            $regions = $this->footerContentRegions($markup, (string) ($page['source_path'] ?? ''));
            $pageRegionCounts[$index] = count($regions);
            foreach ($regions as $regionIndex => $region) $documents[] = array('kind' => 'page', 'index' => $index, 'region_index' => $regionIndex, 'source_path' => (string) ($page['source_path'] ?? ''), 'markup' => $markup, 'region' => $region);
        }
        foreach ($parts as $index => $part) {
            if ('footer' !== ($part['area'] ?? null) || 'inline_shared_shell' === ($part['placement']['kind'] ?? null) || 'responsive_variant_partition' === ($part['provenance']['reason'] ?? null)) continue;
            $markup = (string) ($part['canonical_block_markup'] ?? '');
            $regions = $this->footerContentRegions($markup, (string) ($part['source_path'] ?? ''), true);
            if (array() === $regions) continue;
            $sourcePaths = array();
            if ('shared_shell' === ($part['placement']['kind'] ?? null) && is_array($part['provenance']['sources'] ?? null)) {
                $excluded = array_fill_keys($part['placement']['excluded_template_slugs'] ?? array(), true);
                foreach ($pages as $page) {
                    if (!empty($page['synthetic']) || !isset($part['provenance']['sources'][$page['source_path'] ?? ''])) continue;
                    if (!empty($page['entrypoint'])) $selected = in_array('front-page', $part['placement']['template_slugs'] ?? array(), true);
                    elseif ('post' === ($page['post_type'] ?? null)) $selected = in_array('single', $part['placement']['template_slugs'] ?? array(), true) && !isset($excluded['single-' . ($page['slug'] ?? '')]);
                    else $selected = in_array('page', $part['placement']['template_slugs'] ?? array(), true) && !isset($excluded['page-' . ($page['slug'] ?? '')]);
                    if ($selected) $sourcePaths[] = (string) $page['source_path'];
                }
            }
            if (array() === $sourcePaths) $sourcePaths[] = (string) ($part['source_path'] ?? '');
            foreach ($sourcePaths as $sourcePath) foreach ($regions as $regionIndex => $region) $documents[] = array('kind' => 'part', 'index' => $index, 'region_index' => $regionIndex, 'source_path' => $sourcePath, 'markup' => $markup, 'region' => $region);
        }
        if (2 > count($documents)) return array('pages' => $pages, 'parts' => $parts, 'diagnostics' => array());

        $clusters = array();
        foreach ($documents as $documentIndex => $document) {
            $regionMarkup = substr($document['markup'], $document['region']['offset'], $document['region']['length']);
            foreach (WordPressSitePlan::blockRanges($regionMarkup) as $range) {
                $block = substr($regionMarkup, $range['offset'], $range['length']);
                if (!preg_match('/^<!--\s*wp:paragraph\b/', ltrim($block))) continue;
                $absolute = $document['region']['offset'] + $range['offset'];
                foreach ($bound[$document['kind'] . ':' . $document['index']] ?? array() as $protected) {
                    if ($absolute >= $protected['offset'] && $absolute + $range['length'] <= $protected['offset'] + $protected['length']) continue 2;
                }
                $text = preg_replace('/<!--.*?-->/s', ' ', $block) ?? $block;
                $text = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5);
                $text = trim(preg_replace('/\s+/u', ' ', $text) ?? $text);
                if ('' === $text) continue;
                $identity = self::identityMarkup($block);
                $key = hash('sha256', $identity);
                $clusters[$key]['identity'] = $identity;
                $clusters[$key]['text_length'] = strlen($text);
                $clusters[$key]['source_paths'][$document['source_path']] = true;
                if ('page' === $document['kind']) $clusters[$key]['page_regions'][$document['index']][$document['region_index']] = true;
                $clusters[$key]['documents'][$documentIndex][] = array(
                    'kind' => $document['kind'],
                    'index' => $document['index'],
                    'source_path' => $document['source_path'],
                    'offset' => $document['region']['offset'] + $range['offset'],
                    'length' => $range['length'],
                    'markup' => $block,
                );
            }
        }
        foreach ($clusters as &$candidateCluster) {
            foreach ($candidateCluster['page_regions'] ?? array() as $pageIndex => $coveredRegions) {
                if (count($coveredRegions) !== ($pageRegionCounts[$pageIndex] ?? 0)) $candidateCluster['incomplete_responsive_regions'] = true;
            }
        }
        unset($candidateCluster);
        $clusters = array_filter($clusters, static fn(array $cluster): bool => empty($cluster['incomplete_responsive_regions']));
        uasort($clusters, static fn(array $left, array $right): int => count($right['source_paths'] ?? array()) <=> count($left['source_paths'] ?? array()) ?: ($right['text_length'] ?? 0) <=> ($left['text_length'] ?? 0));
        $cluster = reset($clusters);
        if (!is_array($cluster) || count($cluster['source_paths'] ?? array()) < 2) return array('pages' => $pages, 'parts' => $parts, 'diagnostics' => array());

        $baseSlug = 'footer-content';
        $slug = $baseSlug;
        $existingSlugs = array_fill_keys(array_column($parts, 'slug'), true);
        for ($suffix = 2; isset($existingSlugs[$slug]); ++$suffix) $slug = $baseSlug . '-' . $suffix;
        $excludedTemplateSlugs = array();
        foreach ($parts as $existingPart) foreach ($existingPart['placement']['excluded_template_slugs'] ?? array() as $templateSlug) if (is_string($templateSlug)) $excludedTemplateSlugs[$templateSlug] = true;
        $reference = '<!-- wp:template-part {"slug":"' . $slug . '","area":"footer","tagName":"div"} /-->';
        $pageReplacements = array();
        $partReplacements = array();
        $sources = array();
        foreach ($cluster['documents'] as $occurrences) foreach ($occurrences as $occurrence) {
            $target = 'page' === $occurrence['kind'] ? 'page' : 'part';
            $key = $occurrence['index'];
            if ('page' === $target) $pageReplacements[$key][] = $occurrence; else $partReplacements[$key][] = $occurrence;
            if ('' !== $occurrence['source_path']) $sources[$occurrence['source_path']] = hash('sha256', $occurrence['markup']);
        }
        foreach ($pageReplacements as $index => $replacements) {
            usort($replacements, static fn(array $left, array $right): int => $right['offset'] <=> $left['offset']);
            $markup = (string) $pages[$index]['canonical_block_markup'];
            foreach ($replacements as $replacement) if (substr($markup, $replacement['offset'], $replacement['length']) === $replacement['markup']) $markup = substr($markup, 0, $replacement['offset']) . $reference . substr($markup, $replacement['offset'] + $replacement['length']);
            $pages[$index]['canonical_block_markup'] = $markup;
            $pages[$index]['content_hash'] = WordPressSitePlan::contentHash($markup);
        }
        foreach ($partReplacements as $index => $replacements) {
            $unique = array();
            foreach ($replacements as $replacement) $unique[$replacement['offset'] . ':' . $replacement['length']] = $replacement;
            $replacements = array_values($unique);
            usort($replacements, static fn(array $left, array $right): int => $right['offset'] <=> $left['offset']);
            $markup = (string) $parts[$index]['canonical_block_markup'];
            foreach ($replacements as $replacement) if (substr($markup, $replacement['offset'], $replacement['length']) === $replacement['markup']) $markup = substr($markup, 0, $replacement['offset']) . $reference . substr($markup, $replacement['offset'] + $replacement['length']);
            $parts[$index]['canonical_block_markup'] = $markup;
            $parts[$index]['content_hash'] = WordPressSitePlan::contentHash($markup);
        }

        ksort($sources, SORT_STRING);
        $sourcePath = 'wordpress-site-plan/shared/footer-content';
        $partMarkup = (string) $cluster['identity'];
        $part = array(
            'source_path' => $sourcePath . '#footer',
            'slug' => $slug,
            'title' => 'Footer Content',
            'post_type' => 'wp_template_part',
            'parent_source_path' => '',
            'entrypoint' => false,
            'area' => 'footer',
            'tag_name' => 'div',
            'placement' => array('kind' => 'inline_shared_shell', 'source_path' => $sourcePath, 'source_paths' => array_keys($sources), 'variant' => 1, 'excluded_template_slugs' => array_keys($excludedTemplateSlugs)),
            'canonical_block_markup' => $partMarkup,
            'metadata' => array(),
            'document_metadata' => array('source_context' => array('source_path' => $sourcePath . '#footer', 'kind' => 'template_part'), 'title' => 'Footer Content', 'title_declaration' => array('order' => 0, 'placement' => 'head'), 'meta' => array(), 'links' => array(), 'scripts' => array()),
            'provenance' => array('schema' => 'blocks-engine/shell-extraction/v1', 'area' => 'footer', 'decision' => 'extracted', 'reason' => 'shared_inner_content', 'sources' => $sources, 'shell_identity' => hash('sha256', $partMarkup)),
            'reconciliation_identity' => WordPressSitePlan::identity('template-part', $sourcePath . '#footer', 'parts/' . $slug . '.html'),
            'content_hash' => WordPressSitePlan::contentHash($partMarkup),
        );
        return array(
            'pages' => $pages,
            'parts' => array_merge($parts, array($part)),
            'diagnostics' => array(array('code' => 'wordpress_site_plan_footer_content_shared', 'severity' => 'info', 'message' => 'Extracted identical footer copy into one editable inline template part while retaining each route wrapper.', 'area' => 'footer', 'source_count' => count($sources), 'slug' => $slug)),
        );
    }

    /** @return array<int,array{offset:int,length:int}> */
    private function footerContentRegions(string $markup, string $sourcePath, bool $wholePart = false): array
    {
        if ($wholePart) return array(array('offset' => 0, 'length' => strlen($markup)));
        return array_values(array_map(static fn(array $candidate): array => array('offset' => $candidate['offset'], 'length' => $candidate['length']), $this->nestedLandmarkCandidates($markup, $sourcePath, 'footer')));
    }

    /** @return array<int,array{token:string,offset:int,closing:bool,name:string,attributes:string,self_closing:bool}> */
    private static function blockCommentTokens(string $markup): array
    {
        $tokens = array();
        $cursor = 0;
        $length = strlen($markup);
        while ($cursor < $length) {
            $start = strpos($markup, '<!--', $cursor);
            if (false === $start) break;
            $end = strpos($markup, '-->', $start + 4);
            if (false === $end) break;
            $token = substr($markup, $start, $end + 3 - $start);
            $cursor = $end + 3;
            if (!preg_match('/^<!--\s*(\/?)wp:([^\s]+)(?:\s+(.*?))?\s*(\/?)-->$/s', $token, $match)) continue;
            $tokens[] = array(
                'token' => $token,
                'offset' => $start,
                'closing' => '' !== $match[1],
                'name' => $match[2],
                'attributes' => trim($match[3] ?? ''),
                'self_closing' => '' !== ($match[4] ?? '') || str_ends_with(rtrim($token), '/-->'),
            );
        }
        return $tokens;
    }

    /** @return array<int,array<string,mixed>> */
    private function nestedLandmarkCandidates(string $markup, string $sourcePath, string $area): array
    {
        $rows = array(); $stack = array();
        foreach (self::blockCommentTokens($markup) as $token) {
            $offset = $token['offset']; $closing = $token['closing']; $selfClosing = $token['self_closing'];
            if ($closing) {
                $open = array_pop($stack);
                if (!is_array($open) || empty($open['candidate'])) continue;
                $length = $offset + strlen($token['token']) - $open['offset']; $candidateMarkup = substr($markup, $open['offset'], $length);
                $rows[] = array('area' => $area, 'markup' => $candidateMarkup, 'identity_markup' => self::normalizeNestedChromeMarkup($candidateMarkup), 'source_path' => $sourcePath, 'source_hash' => hash('sha256', $candidateMarkup), 'offset' => $open['offset'], 'length' => $length, 'wrapper_contract_shell' => !empty($open['wrapper_contract_shell']), 'ancestor_context' => self::ancestorContext($stack) + array('preceded' => !empty($open['preceded'])));
                continue;
            }
            $name = $token['name']; $attributes = $token['attributes']; $attrs = '' === $attributes ? array() : json_decode($attributes, true);
            $disallowedAncestor = false;
            foreach ($stack as $ancestor) if (in_array($ancestor['tag_name'] ?? null, array('main', 'article', 'section', 'aside'), true)) { $disallowedAncestor = true; break; }
            $tagName = is_array($attrs) ? ($attrs['tagName'] ?? null) : null;
            $className = is_array($attrs) && is_string($attrs['className'] ?? null) ? $attrs['className'] : '';
            $anchor = is_array($attrs) && is_string($attrs['anchor'] ?? null) ? $attrs['anchor'] : '';
            $hasShellWrapperContract = false;
            if (is_array($attrs) && is_array($attrs['wrappers'] ?? null)) {
                $hasShellWrapperContract = true;
                foreach ($attrs['wrappers'] as $wrapper) {
                    if (!is_array($wrapper)) continue;
                    $wrapperAttributes = is_array($wrapper['attributes'] ?? null) ? $wrapper['attributes'] : array();
                    if ('' === $anchor && is_string($wrapperAttributes['id'] ?? null)) $anchor = $wrapperAttributes['id'];
                    $wrapperClass = trim((string) ($wrapperAttributes['class'] ?? ''));
                    if ('' !== $wrapperClass) $className = trim($className . ' ' . $wrapperClass);
                    if (null === $tagName && is_string($wrapper['tagName'] ?? null) && in_array($wrapper['tagName'], array('header', 'footer', 'main', 'article', 'section', 'aside'), true)) $tagName = $wrapper['tagName'];
                }
            }
            $footerClass = $hasShellWrapperContract ? self::footerAreaFromClassName($className) : null;
            if ('footer' !== $tagName) $tagName = $footerClass ?? $tagName;
            $candidate = 0 < count($stack) && !$disallowedAncestor && $area === $tagName && (self::isShellLandmarkBlock($name) || ('footer' === $area && $hasShellWrapperContract && 'footer' === $footerClass));
            // Whether page content precedes the landmark inside its ancestors: a
            // block other than the enclosing openings started or ended before it.
            $preceded = false;
            if ($candidate) {
                $between = substr($markup, $stack[0]['offset'], $offset - $stack[0]['offset']);
                $preceded = preg_match_all('/<!--\s*wp:/', $between) > count($stack) || 0 < preg_match_all('/<!--\s*\/wp:/', $between);
            }
            if (!$selfClosing) $stack[] = array('offset' => $offset, 'tag_name' => $tagName, 'candidate' => $candidate, 'wrapper_contract_shell' => $candidate && $hasShellWrapperContract && 'footer' === $area && 'footer' === $footerClass, 'preceded' => $preceded, 'anchor' => $anchor, 'class_name' => $className);
        }
        usort($rows, static fn(array $left, array $right): int => $left['offset'] <=> $right['offset']);
        foreach ($rows as $variant => &$row) $row['variant'] = $variant; unset($row);
        return $rows;
    }

    /**
     * The ids and classes of the blocks enclosing a nested landmark. Hoisted into
     * a template part, the landmark leaves them behind, so author rules that
     * reached it through them need re-anchoring (see WordPressSitePlan).
     *
     * @param array<int,array<string,mixed>> $stack
     * @return array{ids:array<int,string>,classes:array<int,string>}
     */
    private static function ancestorContext(array $stack): array
    {
        $ids = array(); $classes = array();
        foreach ($stack as $ancestor) {
            if (empty($ancestor['candidate']) && '' !== ($ancestor['anchor'] ?? '')) $ids[] = (string) $ancestor['anchor'];
            if (!empty($ancestor['candidate'])) continue;
            foreach (preg_split('/\s+/', trim((string) ($ancestor['class_name'] ?? ''))) ?: array() as $class) if ('' !== $class) $classes[] = $class;
        }
        return array('ids' => array_values(array_unique($ids)), 'classes' => array_values(array_unique($classes)));
    }

    /** @param array<int,array<string,mixed>> $rows */
    private static function logicalNestedVariantCount(array $rows): int
    {
        $identities = array_column($rows, 'identity_markup');
        if (array() !== $identities && 1 === count(array_unique($identities))) return 1;
        return count($rows);
    }

    /** @param array<int,array<string,mixed>> $pages @param array<string,true> $reservedSlugs @param array<int,array<string,mixed>> $runtimeDeclarations @return array{pages:array<int,array<string,mixed>>,parts:array<int,array<string,mixed>>,runtime_declarations:array<int,array<string,mixed>>,diagnostics:array<int,array<string,mixed>>} */
    public function sharedShells(array $pages, array $reservedSlugs = array(), array $runtimeDeclarations = array()): array
    {
        $parts = array(); $diagnostics = array();
        foreach (array('footer', 'header') as $area) {
            $candidates = array(); $clusters = array(); $excluded = array(); $overrides = array();
            $templateSlugs = array(); $excludedTemplateSlugs = array();
            if ('footer' === $area && array_reduce($pages, static fn(bool $found, array $page): bool => $found || (bool) array_filter($page['shell_candidates'] ?? array(), static fn(array $candidate): bool => 'footer' === ($candidate['area'] ?? null) && !empty($candidate['wrapper_contract_shell'])), false)) {
                $diagnostics[] = array('code' => 'wordpress_site_plan_shell_wrapper_variant_retained', 'severity' => 'info', 'message' => 'The footer wrapper contract remains route-owned while identical footer copy is factored into a shared inner template part.', 'area' => 'footer');
                continue;
            }
            if (isset($reservedSlugs[$area])) {
                $diagnostics[] = array('code' => 'wordpress_site_plan_shell_retained_ambiguous', 'severity' => 'info', 'message' => "{$area} shell conflicts with an existing template part.", 'area' => $area, 'provenance' => $this->shellProvenance($area, 'retained', 'existing_template_part'));
                continue;
            }
            $applicable = array_filter($pages, static fn(array $page): bool => empty($page['synthetic']));
            if (array() === $applicable) continue;
            foreach ($applicable as $index => $page) foreach ($page['shell_candidates'] ?? array() as $candidate) if ($area === ($candidate['area'] ?? null)) $candidates[$index][] = $candidate;
            foreach ($applicable as $index => $page) {
                $rows = $candidates[$index] ?? array();
                if (1 !== count($rows)) { $excluded[$index] = count($rows) > 1 ? 'multiple' : 'missing'; continue; }
                $candidate = $rows[0];
                $candidateIdentity = hash('sha256', $area . "\0" . json_encode($candidate['classes']) . "\0" . ($candidate['identity_markup'] ?? $candidate['markup']));
                if (!isset($clusters[$candidateIdentity]['candidate']) || self::prefersScrollStateCarrier($candidate, $clusters[$candidateIdentity]['candidate'])) $clusters[$candidateIdentity]['candidate'] = $candidate;
                $clusters[$candidateIdentity]['indexes'][] = $index;
            }
            uasort($clusters, static fn(array $left, array $right): int => count($right['indexes']) <=> count($left['indexes']) ?: strcmp($left['candidate']['source_path'], $right['candidate']['source_path']));
            $identity = array_key_first($clusters);
            $cluster = null === $identity ? null : $clusters[$identity];
            $runnerUp = array_values($clusters)[1] ?? null;
            if (!is_array($cluster) || (count($cluster['indexes']) < count($applicable) && (count($cluster['indexes']) < 2 || (is_array($runnerUp) && count($cluster['indexes']) === count($runnerUp['indexes']))))) {
                $partitioned = $this->viewportPartitionWithoutCandidate($pages, array_keys($applicable), $area, $runtimeDeclarations);
                if (null === $partitioned) {
                    $reason = array() === $clusters ? 'incomplete' : 'non_equivalent';
                    $diagnostics[] = array('code' => 'wordpress_site_plan_shell_retained_' . ('incomplete' === $reason ? 'incomplete' : 'ambiguous'), 'severity' => 'info', 'message' => "{$area} shell candidates do not establish a dominant semantic cluster.", 'area' => $area, 'provenance' => $this->shellProvenance($area, 'retained', $reason, $candidates));
                    continue;
                }
                foreach ($partitioned['pages'] as $index => $withoutShell) {
                    $pages[$index]['canonical_block_markup'] = $withoutShell;
                    $pages[$index]['content_hash'] = WordPressSitePlan::contentHash($withoutShell);
                }
                $runtimeDeclarations = $partitioned['runtime_declarations'];
                $parts[] = $partitioned['part'];
                $diagnostics[] = $partitioned['diagnostic'];
                continue;
            }
            $first = $cluster['candidate'];
            if (1 === count($applicable) && !empty($first['shared_only'])) continue;
            // A CSS-owned ancestor cannot be moved into the template without
            // also moving the page's other children. Keep its shell reference
            // at the authored position inside the page-owned layout instead.
            $inlineEntryShell = 1 === count($applicable)
                && !empty($first['nested_shell'])
                && in_array('blocks-engine-css-owned-layout', $first['ancestor_context']['classes'] ?? array(), true);
            foreach ($applicable as $index => $page) if (!in_array($index, $cluster['indexes'], true)) $excluded[$index] = isset($candidates[$index]) ? 'non_equivalent' : 'missing';
            // 'search' is never an applicable page in its own right (WordPress
            // synthesizes it), so it rides along wherever 'index' is bound: both
            // are the site's generic, non-singular fallback templates.
            $templateSlugs = count($cluster['indexes']) === count($applicable) ? array('index', 'page', 'front-page', 'single', 'search') : array('index', 'search');
            if (count($cluster['indexes']) !== count($applicable)) foreach ($applicable as $index => $page) {
                $selected = in_array($index, $cluster['indexes'], true);
                if (!empty($page['entrypoint'])) { if ($selected) $templateSlugs[] = 'front-page'; continue; }
                if ('post' === ($page['post_type'] ?? null)) { if ($selected) $templateSlugs[] = 'single'; } elseif ($selected) $templateSlugs[] = 'page';
                if (!$selected) {
                    $slug = 'page' === ($page['post_type'] ?? null) ? 'page-' . $page['slug'] : 'single-' . $page['post_type'] . '-' . $page['slug'];
                    if (isset($overrides[$slug])) {
                        $diagnostics[] = array('code' => 'wordpress_site_plan_shell_retained_ambiguous', 'severity' => 'info', 'message' => "{$area} shell exclusions cannot be assigned distinct route templates.", 'area' => $area, 'provenance' => $this->shellProvenance($area, 'retained', 'route_template_ambiguous', $candidates));
                        continue 2;
                    }
                    $overrides[$slug] = $index;
                }
            }
            $templateSlugs = array_values(array_unique($templateSlugs));
            $excludedTemplateSlugs = array_keys($overrides ?? array());
            $withoutShells = array();
            $retainedForRuntimeBinding = false;
            foreach ($cluster['indexes'] as $index) {
                $page = $pages[$index];
                $candidate = $candidates[$index][0];
                $withoutShell = isset($candidate['legacy_content_markup'])
                    ? (($candidate['legacy_page_markup'] ?? null) === $page['canonical_block_markup'] ? $candidate['legacy_content_markup'] : null)
                    : (!empty($candidate['nested_shell'])
                        ? $this->withoutNestedShell($page['canonical_block_markup'], $candidate, $inlineEntryShell
                            ? '<!-- wp:template-part {"slug":"' . $area . '","area":"' . $area . '","tagName":"div"} /-->'
                            : '')
                        : $this->withoutTopLevelShell($page['canonical_block_markup'], $area, $candidate['markup'], $candidate['offset'] ?? null));
                if (null === $withoutShell) {
                    $diagnostics[] = array('code' => 'wordpress_site_plan_shell_retained_ambiguous', 'severity' => 'warning', 'message' => "{$area} shell candidate cannot be removed unambiguously from {$page['source_path']}.", 'area' => $area, 'source_path' => $page['source_path'], 'provenance' => $this->shellProvenance($area, 'retained', 'removal_ambiguous', $candidates));
                    continue 2;
                }
                if ( '' === trim($withoutShell) ) {
                    $diagnostics[] = array('code' => 'wordpress_site_plan_shell_retained_only_content', 'severity' => 'info', 'message' => "{$area} shell remains page-owned because removing it would leave the page empty.", 'area' => $area, 'source_path' => $page['source_path'], 'provenance' => $this->shellProvenance($area, 'retained', 'only_content', $candidates));
                    continue 2;
                }
                $withoutShells[$index] = $withoutShell;
            }
            // The entry page can hold its chrome in a wrapper no other page has
            // (a pinned layer that keeps the header fixed while the page
            // scrolls). A wrapper whose only content is the chrome belongs to it:
            // it moves with the chrome into the front-page template, around the
            // shared part, instead of staying behind empty in the page.
            $templateWrappers = array();
            foreach ($cluster['indexes'] as $index) {
                if (empty($pages[$index]['entrypoint']) || empty($candidates[$index][0]['nested_shell']) || array() !== ($candidates[$index][0]['additional_ranges'] ?? array())) continue;
                $ranges = $this->nestedShellRanges($pages[$index]['canonical_block_markup'], $candidates[$index][0], $area);
                if (1 !== count($ranges)) continue;
                $wrapper = self::soleChromeWrapper($pages[$index]['canonical_block_markup'], $ranges[0]);
                if (null === $wrapper) continue;
                $templateWrappers['front-page'] = array('opening' => $wrapper['opening'], 'closing' => $wrapper['closing']);
                $withoutShells[$index] = $wrapper['page'];
            }
            $shellBindings = array();
            foreach ($cluster['indexes'] as $index) {
                $page = $pages[$index];
                $candidate = $candidates[$index][0];
                $legacyContentRange = $candidate['legacy_content_range'] ?? null;
                if (is_array($legacyContentRange)) {
                    if ($this->shellContainsRuntimeBindingOutsideRange($runtimeDeclarations, $page, $legacyContentRange['offset'], $legacyContentRange['length'])) {
                        $retainedForRuntimeBinding = true;
                        break;
                    }
                    continue;
                }
                $found = $this->runtimeBindingsInRanges($runtimeDeclarations, $page, $this->nestedShellRanges($page['canonical_block_markup'], $candidate, $area));
                if ($found['blocked']) {
                    $retainedForRuntimeBinding = true;
                    break;
                }
                if (array() !== $found['refs']) $shellBindings[$index] = $found['refs'];
            }
            $bindingHoist = array() === $shellBindings || $retainedForRuntimeBinding ? null : $this->sharedShellBindingHoist($runtimeDeclarations, $pages, $cluster['indexes'], $shellBindings, $first, $area);
            if ($retainedForRuntimeBinding || (array() !== $shellBindings && null === $bindingHoist)) {
                $retainedSource = array() !== $shellBindings ? $pages[array_key_first($shellBindings)]['source_path'] : $pages[$cluster['indexes'][0]]['source_path'];
                $diagnostics[] = array('code' => 'wordpress_site_plan_shell_retained_runtime_binding', 'severity' => 'info', 'message' => "{$area} shell remains page-owned because it contains a runtime entity binding anchor that cannot move into a shared part.", 'area' => $area, 'source_path' => $retainedSource, 'provenance' => $this->shellProvenance($area, 'retained', 'runtime_binding', $candidates));
                continue;
            }
            $absorbed = null;
            foreach ($withoutShells as $withoutShell) {
                if ($this->retainsResponsiveVariantLandmark($withoutShell, $area)) {
                    $absorbed = $this->absorbResponsiveVariantLandmarks($pages, $cluster['indexes'], $area, $candidates, $withoutShells, $runtimeDeclarations);
                    break;
                }
            }
            if (null !== $bindingHoist && null !== $absorbed) {
                $diagnostics[] = array('code' => 'wordpress_site_plan_shell_retained_runtime_binding', 'severity' => 'info', 'message' => "{$area} shell remains page-owned because its runtime entity binding sits in a responsive variant.", 'area' => $area, 'source_path' => $pages[$cluster['indexes'][0]]['source_path'], 'provenance' => $this->shellProvenance($area, 'retained', 'runtime_binding', $candidates));
                continue;
            }
            if (false === $absorbed) {
                $diagnostics[] = array('code' => 'wordpress_site_plan_shell_retained_ambiguous', 'severity' => 'info', 'message' => "{$area} shell remains page-owned because a responsive document variant still contains that landmark.", 'area' => $area, 'source_path' => $pages[$cluster['indexes'][0]]['source_path'], 'provenance' => $this->shellProvenance($area, 'retained', 'responsive_variant_retained', $candidates));
                continue;
            }
            if (is_array($absorbed)) {
                $withoutShells = $absorbed['pages'];
                foreach (array_keys($excluded) as $index) {
                    if ($this->variantLandmarksContainRuntimeBinding($pages[$index], $area, $runtimeDeclarations)) continue;
                    $adopted = $this->stripMatchingVariantLandmarks($pages[$index]['canonical_block_markup'], $area, $absorbed['identities'], true);
                    if (null === $adopted || $this->retainsResponsiveVariantLandmark($adopted, $area)) continue;
                    $withoutShells[$index] = $adopted;
                    unset($excluded[$index]);
                }
                $templateSlugs = array();
                $excludedTemplateSlugs = array();
                $overrides = array();
                if (count($withoutShells) === count($applicable)) {
                    $templateSlugs = array('index', 'page', 'front-page', 'single', 'search');
                } else {
                    $templateSlugs = array('index', 'search');
                    foreach ($applicable as $index => $page) {
                        $selected = isset($withoutShells[$index]);
                        if (!empty($page['entrypoint'])) { if ($selected) $templateSlugs[] = 'front-page'; continue; }
                        if ('post' === ($page['post_type'] ?? null)) { if ($selected) $templateSlugs[] = 'single'; } elseif ($selected) $templateSlugs[] = 'page';
                        if (!$selected) {
                            $slug = 'page' === ($page['post_type'] ?? null) ? 'page-' . $page['slug'] : 'single-' . $page['post_type'] . '-' . $page['slug'];
                            if (isset($overrides[$slug])) {
                                $diagnostics[] = array('code' => 'wordpress_site_plan_shell_retained_ambiguous', 'severity' => 'info', 'message' => "{$area} shell exclusions cannot be assigned distinct route templates.", 'area' => $area, 'provenance' => $this->shellProvenance($area, 'retained', 'route_template_ambiguous', $candidates));
                                continue 2;
                            }
                            $overrides[$slug] = $index;
                        }
                    }
                    $templateSlugs = array_values(array_unique($templateSlugs));
                    $excludedTemplateSlugs = array_keys($overrides);
                }
            }
            $singlePage = 1 === count($applicable) && 1 === count($cluster['indexes']);
            if (null !== $bindingHoist && $singlePage) {
                $diagnostics[] = array('code' => 'wordpress_site_plan_shell_retained_runtime_binding', 'severity' => 'info', 'message' => "{$area} shell remains page-owned because it contains a runtime entity binding anchor.", 'area' => $area, 'source_path' => $pages[$cluster['indexes'][0]]['source_path'], 'provenance' => $this->shellProvenance($area, 'retained', 'runtime_binding', $candidates));
                continue;
            }
            foreach ($withoutShells as $index => $withoutShell) {
                $pages[$index]['canonical_block_markup'] = $withoutShell;
                $pages[$index]['content_hash'] = WordPressSitePlan::contentHash($withoutShell);
            }
            if (null !== $bindingHoist) $runtimeDeclarations = self::applySharedShellBindingHoist($runtimeDeclarations, $bindingHoist, 'wordpress-site-plan/shared/' . $area . '#' . $area);
            foreach ($runtimeDeclarations as &$declaration) unset($declaration['reconciliation_identity'], $declaration['payload_hash'], $declaration['content_hash']); unset($declaration);
            $runtimeDeclarations = RuntimeDeclarations::normalizeList($runtimeDeclarations);
            $sourcePath = $singlePage ? $pages[array_key_first($applicable)]['source_path'] : 'wordpress-site-plan/shared/' . $area;
            $placement = $inlineEntryShell ? 'inline_shared_shell' : ($singlePage ? 'entry_shell' : 'shared_shell');
            if ($singlePage) $templateSlugs = $inlineEntryShell ? array() : array('front-page');
            $partMarkup = is_array($absorbed) ? $absorbed['markup'] : ($inlineEntryShell ? $first['markup'] : $first['template_part_markup']);
            $tagName = is_array($absorbed) ? 'div' : ShellLandmarkPolicy::templatePartAreaTagName($area);
            $ancestorContext = is_array($absorbed) ? ($absorbed['ancestor_context'] ?? null) : ($first['ancestor_context'] ?? null);
            $container = isset($first['legacy_container_opening']) ? array('opening' => $first['legacy_container_opening'], 'closing' => $first['legacy_container_closing']) : null;
            $parts[] = array('source_path' => $sourcePath . '#' . $area, 'slug' => $area, 'title' => ucfirst($area), 'post_type' => 'wp_template_part', 'parent_source_path' => '', 'entrypoint' => false, 'area' => $area, 'tag_name' => $tagName, 'placement' => array_filter(array('kind' => $placement, 'source_path' => $sourcePath, 'source_paths' => $inlineEntryShell ? array($sourcePath) : null, 'template_slugs' => $templateSlugs, 'excluded_template_slugs' => $excludedTemplateSlugs, 'container' => $container, 'template_wrappers' => in_array('front-page', $templateSlugs, true) && !$singlePage ? $templateWrappers : array()), static fn(mixed $value): bool => array() !== $value && null !== $value), 'canonical_block_markup' => $partMarkup, 'metadata' => array(), 'document_metadata' => array('source_context' => array('source_path' => $sourcePath . '#' . $area, 'kind' => 'template_part'), 'title' => ucfirst($area), 'title_declaration' => array('order' => 0, 'placement' => 'head'), 'meta' => array(), 'links' => array(), 'scripts' => array()), 'provenance' => $this->shellProvenance($area, 'extracted', is_array($absorbed) ? 'responsive_variant_partition' : 'canonical', $candidates, $identity), 'reconciliation_identity' => WordPressSitePlan::identity('template-part', $sourcePath . '#' . $area, 'parts/' . $area . '.html'), 'content_hash' => WordPressSitePlan::contentHash($partMarkup)) + (is_array($ancestorContext) ? array('ancestor_context' => $ancestorContext) : array());
            $diagnostics[] = array('code' => $singlePage ? 'wordpress_site_plan_shell_entry_extracted' : 'wordpress_site_plan_shell_extracted', 'severity' => 'info', 'message' => $singlePage ? "Extracted the entry {$area} shell for the front-page template." : "Extracted the dominant semantically equivalent {$area} shell cluster.", 'area' => $area, 'page_count' => count($cluster['indexes']), 'applicable_page_count' => count($applicable), 'exclusions' => array_map(static fn(int $index, string $reason): array => array('source_path' => $pages[$index]['source_path'], 'reason' => $reason), array_keys($excluded), $excluded));
        }
        foreach ($pages as &$page) unset($page['shell_candidates']); unset($page);
        return array('pages' => $pages, 'parts' => $parts, 'runtime_declarations' => $runtimeDeclarations, 'diagnostics' => $diagnostics);
    }

    /** @param array<int,array<string,mixed>> $declarations @param array<string,mixed> $page */
    private function shellContainsRuntimeBinding(array $declarations, array $page, int $offset, int $length): bool
    {
        $found = $this->runtimeBindingsInRanges($declarations, $page, array(array('offset' => $offset, 'length' => $length)));
        return $found['blocked'] || array() !== $found['refs'];
    }

    /**
     * Entity bindings of a page whose anchored block lies inside one of the
     * ranges, and whether any other binding touches those ranges.
     *
     * @param array<int,array<string,mixed>> $declarations
     * @param array<string,mixed> $page
     * @param array<int,array{offset:int,length:int}> $ranges
     * @return array{refs:list<array{declaration:int|string,entity:int|string,binding:int|string,offset:int,range:array{offset:int,length:int}}>,blocked:bool}
     */
    private function runtimeBindingsInRanges(array $declarations, array $page, array $ranges): array
    {
        $refs = array();
        $blocked = false;
        if (array() === $ranges) return array('refs' => $refs, 'blocked' => false);
        $blockRanges = null;
        foreach ($declarations as $declarationIndex => $declaration) foreach ($declaration['payload']['entities'] ?? array() as $entityIndex => $entity) foreach (is_array($entity) ? ($entity['bindings'] ?? array()) : array() as $bindingIndex => $binding) {
            $position = $binding['position'] ?? null;
            if (($binding['source_path'] ?? null) !== ($page['source_path'] ?? null)) continue;
            $search = $binding['search_block_markup'] ?? null;
            if (!is_string($search) || '' === $search) continue;
            $blockRanges ??= WordPressSitePlan::blockRanges($page['canonical_block_markup']);
            $block = WordPressSitePlan::bindingPosition($position, $page['canonical_block_markup'], $search) ? ($blockRanges[$position['block_index']] ?? null) : null;
            foreach ($ranges as $range) {
                if (is_array($block) && $block['offset'] >= $range['offset'] && $block['offset'] + $block['length'] <= $range['offset'] + $range['length']) {
                    $refs[] = array('declaration' => $declarationIndex, 'entity' => $entityIndex, 'binding' => $bindingIndex, 'offset' => $block['offset'], 'range' => $range);
                    continue 2;
                }
            }
            // A binding whose block only partly overlaps the shell, or whose
            // exact anchor sits inside the shell without a positioned block (a
            // converter can anchor on a projected block that is not a direct
            // descendant range, such as a form in a responsive shell), cannot
            // move with the chrome and keeps it page-owned.
            foreach ($ranges as $range) {
                $overlaps = is_array($block) && $block['offset'] < $range['offset'] + $range['length'] && $block['offset'] + $block['length'] > $range['offset'];
                if ($overlaps || str_contains(substr($page['canonical_block_markup'], $range['offset'], $range['length']), $search)) $blocked = true;
            }
        }
        usort($refs, static fn(array $left, array $right): int => $left['offset'] <=> $right['offset']);
        return array('refs' => $refs, 'blocked' => $blocked);
    }

    /**
     * Decide whether the entity bindings inside a shared shell can move into
     * the shared part. Every page in the cluster must bind the same entities,
     * in the same order, each owning exactly one binding: identical chrome then
     * carries one entity, not a copy per page. The entity kept is the one bound
     * in the page whose markup becomes the part, so its document markers match
     * the part; the other pages' copies are dropped, and the kept entity lists
     * every source fallback its one replacement stands for in
     * `replaced_fallback_identities`. An entity is compared
     * without its binding, source, identities and document marker seeds.
     *
     * @param array<int,array<string,mixed>> $declarations
     * @param array<int,array<string,mixed>> $pages
     * @param array<int,int> $indexes
     * @param array<int,list<array<string,mixed>>> $shellBindings
     * @param array<string,mixed> $first
     * @return array{keep:list<array<string,mixed>>,drop:list<array{declaration:int|string,entity:int|string}>}|null
     */
    private function sharedShellBindingHoist(array $declarations, array $pages, array $indexes, array $shellBindings, array $first, string $area): ?array
    {
        $canonical = null;
        foreach ($indexes as $index) if (($pages[$index]['source_path'] ?? null) === ($first['source_path'] ?? null)) $canonical = $index;
        if (null === $canonical || !isset($shellBindings[$canonical]) || !empty($first['nested_shell']) && array() !== ($first['additional_ranges'] ?? array())) return null;
        $partMarkup = (string) ($first['template_part_markup'] ?? '');
        $keys = null;
        foreach ($indexes as $index) {
            $pageKeys = array();
            foreach ($shellBindings[$index] ?? array() as $ref) {
                $key = self::hoistableEntityKey($declarations[$ref['declaration']]['payload']['entities'][$ref['entity']]);
                if (null === $key) return null;
                $pageKeys[] = $key;
            }
            if (null !== $keys && $keys !== $pageKeys) return null;
            $keys = $pageKeys;
        }
        $keep = array();
        $partRanges = WordPressSitePlan::blockRanges($partMarkup);
        $pageMarkup = $pages[$canonical]['canonical_block_markup'];
        $pageRanges = WordPressSitePlan::blockRanges($pageMarkup);
        foreach ($shellBindings[$canonical] as $ref) {
            $search = $declarations[$ref['declaration']]['payload']['entities'][$ref['entity']]['bindings'][$ref['binding']]['search_block_markup'];
            $rank = count(array_filter($pageRanges, static fn(array $range): bool => $range['offset'] >= $ref['range']['offset'] && $range['offset'] <= $ref['offset'] && $search === substr($pageMarkup, $range['offset'], $range['length'])));
            $matches = array();
            foreach ($partRanges as $blockIndex => $range) if ($search === substr($partMarkup, $range['offset'], $range['length'])) $matches[] = array('block_index' => $blockIndex) + $range;
            $match = $matches[$rank - 1] ?? null;
            if (null === $match) return null;
            $keep[] = $ref + array('position' => array('schema' => 'blocks-engine/runtime-binding-position/v1', 'block_index' => $match['block_index'], 'offset' => $match['offset'], 'length' => $match['length']), 'occurrence' => substr_count(substr($partMarkup, 0, $match['offset']), $search) + 1);
        }
        // Each kept entity replaces its own source fallback and the matching
        // fallbacks of the pages whose duplicates are dropped.
        $drop = array();
        foreach ($keep as $position => $ref) $keep[$position]['replaced_fallback_identities'] = array();
        foreach ($indexes as $index) foreach ($shellBindings[$index] ?? array() as $position => $ref) {
            $identity = $declarations[$ref['declaration']]['payload']['entities'][$ref['entity']]['fallback_identity'] ?? null;
            if (is_string($identity) && '' !== $identity) $keep[$position]['replaced_fallback_identities'][] = $identity;
            if ($index !== $canonical) $drop[] = array('declaration' => $ref['declaration'], 'entity' => $ref['entity']);
        }
        foreach ($keep as $position => $ref) { $identities = array_values(array_unique($ref['replaced_fallback_identities'])); sort($identities, SORT_STRING); $keep[$position]['replaced_fallback_identities'] = $identities; }
        return array('keep' => $keep, 'drop' => $drop);
    }

    /** @param array<string,mixed> $entity */
    private static function hoistableEntityKey(array $entity): ?string
    {
        $bindings = $entity['bindings'] ?? null;
        if (!is_array($bindings) || 1 !== count($bindings) || !empty($entity['superseded_scripts'])) return null;
        $role = (string) ($bindings[array_key_first($bindings)]['role'] ?? '');
        unset($entity['bindings'], $entity['reconciliation_identity'], $entity['fallback_identity'], $entity['replaced_fallback_identities']);
        return $role . "\0" . EngineMarker::withoutDocumentSeeds(RuntimeDeclarations::canonicalJson(self::withoutSourcePaths($entity)));
    }

    /**
     * Where an entity was read from, and in what cascade order, is provenance,
     * not identity; a grid placement written as the `area` shorthand is the
     * same placement as its `row` and `column` longhands.
     */
    private static function withoutSourcePaths(array $value): array
    {
        unset($value['source_path'], $value['provenance'], $value['source_order']);
        if (is_string($value['area'] ?? null) && 4 === count($lines = array_map('trim', explode('/', $value['area'])))) {
            unset($value['area']);
            $value['row'] = $lines[0] . ' / ' . $lines[2];
            $value['column'] = $lines[1] . ' / ' . $lines[3];
        }
        foreach (array('row', 'column') as $line) if (is_string($value[$line] ?? null)) $value[$line] = preg_replace('/\s*\/\s*/', ' / ', trim($value[$line]));
        foreach ($value as $key => $child) if (is_array($child)) $value[$key] = self::withoutSourcePaths($child);
        if (!array_is_list($value)) ksort($value, SORT_STRING);
        return $value;
    }

    /**
     * Re-anchor the kept bindings on the shared part and drop the duplicate
     * entities the other pages carried for the same chrome.
     *
     * @param array<int,array<string,mixed>> $declarations
     * @param array{keep:list<array<string,mixed>>,drop:list<array{declaration:int|string,entity:int|string}>} $hoist
     * @return array<int,array<string,mixed>>
     */
    private static function applySharedShellBindingHoist(array $declarations, array $hoist, string $partSourcePath): array
    {
        foreach ($hoist['keep'] as $ref) {
            $entity = &$declarations[$ref['declaration']]['payload']['entities'][$ref['entity']];
            $binding = &$entity['bindings'][$ref['binding']];
            $binding['source_path'] = $partSourcePath;
            $binding['occurrence'] = $ref['occurrence'];
            $binding['position'] = $ref['position'];
            unset($binding['projected_anchor']);
            $entity['source_path'] = $partSourcePath;
            if (1 < count($ref['replaced_fallback_identities'])) $entity['replaced_fallback_identities'] = $ref['replaced_fallback_identities'];
            unset($binding, $entity);
        }
        $touched = array();
        foreach ($hoist['drop'] as $ref) {
            unset($declarations[$ref['declaration']]['payload']['entities'][$ref['entity']]);
            $touched[$ref['declaration']] = true;
        }
        foreach (array_keys($touched) as $declarationIndex) $declarations[$declarationIndex]['payload']['entities'] = array_values($declarations[$declarationIndex]['payload']['entities']);
        return $declarations;
    }

    private function shellContainsRuntimeBindingOutsideRange(array $declarations, array $page, int $offset, int $length): bool
    {
        foreach ($declarations as $declaration) foreach ($declaration['payload']['entities'] ?? array() as $entity) foreach (is_array($entity) ? ($entity['bindings'] ?? array()) : array() as $binding) {
            $position = $binding['position'] ?? null;
            if (($binding['source_path'] ?? null) !== ($page['source_path'] ?? null)) continue;
            $search = $binding['search_block_markup'] ?? null;
            if (!is_string($search) || !WordPressSitePlan::bindingPosition($position, $page['canonical_block_markup'], $search)) continue;
            $range = WordPressSitePlan::blockRanges($page['canonical_block_markup'])[$position['block_index']] ?? null;
            if (is_array($range) && ($range['offset'] < $offset || $range['offset'] + $range['length'] > $offset + $length)) return true;
        }
        return false;
    }

    /** @param array<int,array<int,array<string,mixed>>> $candidates @return array<string,mixed> */
    private function shellProvenance(string $area, string $decision, string $reason, array $candidates = array(), ?string $identity = null): array
    {
        $sources = array();
        foreach ($candidates as $rows) foreach ($rows as $candidate) if (is_array($candidate) && is_string($candidate['source_path'] ?? null)) $sources[$candidate['source_path']] = is_string($candidate['source_hash'] ?? null) ? $candidate['source_hash'] : '';
        ksort($sources, SORT_STRING);
        return array_filter(array('schema' => 'blocks-engine/shell-extraction/v1', 'area' => $area, 'decision' => $decision, 'reason' => $reason, 'sources' => $sources, 'shell_identity' => $identity), static fn(mixed $value): bool => null !== $value);
    }

    private function withoutTopLevelShell(string $markup, string $area, string $candidateMarkup = '', ?int $offset = null): ?string
    {
        return $this->replaceTopLevelShell($markup, $area, '', $candidateMarkup, $offset);
    }

    /** @param array<int,array<string,mixed>> $pages @param array<int,int> $indexes @param array<int,array<string,mixed>> $runtimeDeclarations @return array{pages:array<int,string>,part:array<string,mixed>,diagnostic:array<string,mixed>,runtime_declarations:array<int,array<string,mixed>>}|null */
    private function viewportPartitionWithoutCandidate(array $pages, array $indexes, string $area, array $runtimeDeclarations): ?array
    {
        $applicable = array();
        foreach ($indexes as $index) $applicable[$index] = $pages[$index];
        $absorbed = $this->absorbResponsiveVariantLandmarks($pages, $indexes, $area, array(), array(), $runtimeDeclarations);
        if (!is_array($absorbed)) return null;
        $withoutShells = $absorbed['pages'];
        $excluded = array();
        foreach ($applicable as $index => $page) if (!isset($withoutShells[$index])) $excluded[$index] = 'non_equivalent';
        foreach (array_keys($excluded) as $index) {
            if ($this->variantLandmarksContainRuntimeBinding($pages[$index], $area, $runtimeDeclarations)) continue;
            $adopted = $this->stripMatchingVariantLandmarks($pages[$index]['canonical_block_markup'], $area, $absorbed['identities'], true);
            if (null === $adopted || $this->retainsResponsiveVariantLandmark($adopted, $area)) continue;
            $withoutShells[$index] = $adopted;
            unset($excluded[$index]);
        }
        $templateSlugs = array();
        $excludedTemplateSlugs = array();
        $overrides = array();
        if (count($withoutShells) === count($applicable)) {
            $templateSlugs = array('index', 'page', 'front-page', 'single', 'search');
        } else {
            $templateSlugs = array('index', 'search');
            foreach ($applicable as $index => $page) {
                $selected = isset($withoutShells[$index]);
                if (!empty($page['entrypoint'])) { if ($selected) $templateSlugs[] = 'front-page'; continue; }
                if ('post' === ($page['post_type'] ?? null)) { if ($selected) $templateSlugs[] = 'single'; } elseif ($selected) $templateSlugs[] = 'page';
                if (!$selected) {
                    $slug = 'page' === ($page['post_type'] ?? null) ? 'page-' . $page['slug'] : 'single-' . $page['post_type'] . '-' . $page['slug'];
                    if (isset($overrides[$slug])) return null;
                    $overrides[$slug] = $index;
                }
            }
            $templateSlugs = array_values(array_unique($templateSlugs));
            $excludedTemplateSlugs = array_keys($overrides);
        }
        foreach ($runtimeDeclarations as &$declaration) unset($declaration['reconciliation_identity'], $declaration['payload_hash'], $declaration['content_hash']);
        unset($declaration);
        $runtimeDeclarations = RuntimeDeclarations::normalizeList($runtimeDeclarations);
        $sourcePath = 'wordpress-site-plan/shared/' . $area;
        $partMarkup = $absorbed['markup'];
        $ancestorContext = is_array($absorbed['ancestor_context'] ?? null) ? $absorbed['ancestor_context'] : null;
        $provenanceCandidates = array();
        foreach (array_keys($withoutShells) as $index) $provenanceCandidates[$index] = array(array('source_path' => (string) $pages[$index]['source_path'], 'source_hash' => ''));
        $part = array('source_path' => $sourcePath . '#' . $area, 'slug' => $area, 'title' => ucfirst($area), 'post_type' => 'wp_template_part', 'parent_source_path' => '', 'entrypoint' => false, 'area' => $area, 'tag_name' => 'div', 'placement' => array_filter(array('kind' => 'shared_shell', 'source_path' => $sourcePath, 'template_slugs' => $templateSlugs, 'excluded_template_slugs' => $excludedTemplateSlugs), static fn(mixed $value): bool => array() !== $value && null !== $value), 'canonical_block_markup' => $partMarkup, 'metadata' => array(), 'document_metadata' => array('source_context' => array('source_path' => $sourcePath . '#' . $area, 'kind' => 'template_part'), 'title' => ucfirst($area), 'title_declaration' => array('order' => 0, 'placement' => 'head'), 'meta' => array(), 'links' => array(), 'scripts' => array()), 'provenance' => $this->shellProvenance($area, 'extracted', 'responsive_variant_partition', $provenanceCandidates, hash('sha256', implode("\0", $absorbed['identities']))), 'reconciliation_identity' => WordPressSitePlan::identity('template-part', $sourcePath . '#' . $area, 'parts/' . $area . '.html'), 'content_hash' => WordPressSitePlan::contentHash($partMarkup)) + (is_array($ancestorContext) ? array('ancestor_context' => $ancestorContext) : array());
        $diagnostic = array('code' => 'wordpress_site_plan_shell_extracted', 'severity' => 'info', 'message' => "Extracted the dominant semantically equivalent {$area} shell cluster.", 'area' => $area, 'page_count' => count($withoutShells), 'applicable_page_count' => count($applicable), 'exclusions' => array_map(static fn(int $index, string $reason): array => array('source_path' => $pages[$index]['source_path'], 'reason' => $reason), array_keys($excluded), $excluded));
        return array('pages' => $withoutShells, 'part' => $part, 'diagnostic' => $diagnostic, 'runtime_declarations' => $runtimeDeclarations);
    }

    private function retainsResponsiveVariantLandmark(string $markup, string $area): bool
    {
        if (1 !== preg_match('/(?:data-liberation-(?:desktop|mobile)-document|site-document-variant-[a-z][a-z0-9_-]{0,31})/', $markup)) {
            return false;
        }
        return array() !== $this->nestedLandmarkCandidates($markup, '', $area);
    }

    /**
     * Hoist viewport-partitioned chrome into one part instead of leaving the
     * other variant page-owned. Returns false when the landmarks are not a
     * consistent per-viewport set, so the caller keeps the page-owned guard.
     *
     * @param array<int,array<string,mixed>> $pages
     * @param array<int,int> $indexes
     * @param array<int,array<int,array<string,mixed>>> $candidates
     * @param array<int,string> $withoutShells
     * @param array<int,array<string,mixed>> $runtimeDeclarations
     * @return array{pages:array<int,string>,markup:string,identities:array<string,string>,ancestor_context:array<string,mixed>}|false|null
     */
    private function absorbResponsiveVariantLandmarks(array $pages, array $indexes, string $area, array $candidates, array $withoutShells, array $runtimeDeclarations): array|false|null
    {
        $byPage = array();
        $cleanOriginal = array();
        foreach ($indexes as $index) {
            $scoped = $this->scopedVariantLandmarks($pages[$index]['canonical_block_markup'], $area);
            if (null === $scoped || count($scoped) < 2) continue;
            $blocked = false;
            foreach ($scoped as $rows) {
                if (1 !== count(array_unique(array_column($rows, 'identity_markup')))) $blocked = true;
                foreach ($rows as $row) {
                    if ($this->shellContainsRuntimeBinding($runtimeDeclarations, $pages[$index], $row['offset'], $row['length'])) $blocked = true;
                }
            }
            if ($blocked) continue;
            $candidate = $candidates[$index][0] ?? null;
            $byPage[$index] = $scoped;
            $cleanOriginal[$index] = !is_array($candidate) || !$this->candidateOverlapsVariantLandmark((string) ($candidate['markup'] ?? ''), $candidate, $scoped);
        }
        if (array() === $byPage) return false;
        $variantClasses = array_keys($byPage[array_key_first($byPage)]);
        usort($variantClasses, static fn(string $left, string $right): int => self::responsiveVariantRank($left) <=> self::responsiveVariantRank($right) ?: strcmp($left, $right));
        $signatures = array();
        foreach ($byPage as $index => $scoped) {
            $sig = array();
            foreach ($variantClasses as $class) {
                if (!isset($scoped[$class]) || !is_array($scoped[$class])) continue 2;
                $sig[$class] = $scoped[$class][0]['identity_markup'];
            }
            $key = hash('sha256', implode("\0", $sig));
            $signatures[$key]['indexes'][] = $index;
            $signatures[$key]['sig'] = $sig;
        }
        uasort($signatures, static fn(array $left, array $right): int => count($right['indexes']) <=> count($left['indexes']));
        $dominant = reset($signatures);
        if (!is_array($dominant) || count($dominant['indexes']) < 2) return false;
        $identities = $dominant['sig'];
        $kept = array_fill_keys($dominant['indexes'], true);
        $pieces = array();
        $primaryContext = null;
        $sourceIndex = $dominant['indexes'][0];
        foreach ($variantClasses as $class) {
            $scoped = $byPage[$sourceIndex][$class];
            $candidate = $candidates[$sourceIndex][0] ?? null;
            $owned = is_array($candidate) && $this->candidateOwnsVariant($candidate, $scoped);
            $inner = self::withoutCurrentNavigationState($owned ? (string) $candidate['markup'] : (string) $scoped[0]['markup']);
            if ('' === trim($inner)) return false;
            if (null === $primaryContext && 0 === self::responsiveVariantRank($class)) $primaryContext = $scoped[0]['ancestor_context'] ?? null;
            $pieces[$class] = self::variantVisibilityGroup($class, $inner);
        }
        if (null === $primaryContext) {
            $firstClass = $variantClasses[0];
            $primaryContext = $byPage[array_key_first($byPage)][$firstClass][0]['ancestor_context'] ?? null;
        }
        $cleaned = array();
        foreach (array_keys($kept) as $index) {
            $fromOriginal = !empty($cleanOriginal[$index]);
            $markup = $this->stripMatchingVariantLandmarks($fromOriginal ? $pages[$index]['canonical_block_markup'] : $withoutShells[$index], $area, $identities, $fromOriginal);
            if (null === $markup || $this->retainsResponsiveVariantLandmark($markup, $area)) return false;
            $cleaned[$index] = $markup;
        }
        return array('pages' => $cleaned, 'markup' => implode("\n", $pieces), 'identities' => $identities, 'ancestor_context' => is_array($primaryContext) ? $primaryContext : array());
    }

    /**
     * @param array<string,string> $identities
     */
    private function stripMatchingVariantLandmarks(string $markup, string $area, array $identities, bool $requireAll): ?string
    {
        $rows = $this->nestedLandmarkCandidates($markup, '', $area);
        $remove = array();
        $present = array();
        foreach ($rows as $row) {
            $class = self::responsiveVariantClass($row);
            if (null === $class) return null;
            if (!isset($identities[$class]) || $identities[$class] !== $row['identity_markup']) return null;
            $present[$class] = true;
            $remove[] = $row;
        }
        if ($requireAll) foreach (array_keys($identities) as $class) if (!isset($present[$class])) return null;
        usort($remove, static fn(array $left, array $right): int => $right['offset'] <=> $left['offset']);
        foreach ($remove as $row) {
            if ($row['markup'] !== substr($markup, $row['offset'], $row['length'])) return null;
            $markup = substr($markup, 0, $row['offset']) . substr($markup, $row['offset'] + $row['length']);
        }
        return '' === trim($markup) ? null : $markup;
    }

    /**
     * The group block whose only content is the chrome at `$range`, and the
     * page with that group and the chrome both removed.
     *
     * @param array{offset:int,length:int} $range
     * @return array{opening:string,closing:string,page:string}|null
     */
    private static function soleChromeWrapper(string $markup, array $range): ?array
    {
        $before = substr($markup, 0, $range['offset']);
        $after = substr($markup, $range['offset'] + $range['length']);
        if (!preg_match('/(<!--\s*wp:group\s+\{[^>]*?\}\s*-->\s*<(div|section)\b[^>]*>)\s*$/s', $before, $open)) return null;
        if (!preg_match('/^\s*(<\/' . $open[2] . '>\s*<!--\s*\/wp:group\s*-->)/s', $after, $close)) return null;
        // A wrapper nested in another group's opening is still only a wrapper;
        // its comment must open exactly one block.
        if (1 !== preg_match_all('/<!--\s*wp:/', $open[1])) return null;
        return array(
            'opening' => trim($open[1]),
            'closing' => trim($close[1]),
            'page' => substr($before, 0, strlen($before) - strlen($open[0])) . substr($after, strlen($close[0])),
        );
    }

    /** @param array<string,mixed> $page @param array<int,array<string,mixed>> $runtimeDeclarations */
    private function variantLandmarksContainRuntimeBinding(array $page, string $area, array $runtimeDeclarations): bool
    {
        foreach ($this->nestedLandmarkCandidates($page['canonical_block_markup'], '', $area) as $row) {
            if (null === self::responsiveVariantClass($row)) continue;
            if ($this->shellContainsRuntimeBinding($runtimeDeclarations, $page, $row['offset'], $row['length'])) return true;
        }
        return false;
    }

    /** @return array<string,array<int,array<string,mixed>>>|null */
    private function scopedVariantLandmarks(string $markup, string $area): ?array
    {
        $scoped = array();
        foreach ($this->nestedLandmarkCandidates($markup, '', $area) as $row) {
            $class = self::responsiveVariantClass($row);
            if (null === $class) return null;
            $scoped[$class][] = $row;
        }
        return $scoped;
    }

    /** @param array<string,array<int,array<string,mixed>>> $scoped */
    private function candidateOverlapsVariantLandmark(string $candidateMarkup, array $candidate, array $scoped): bool
    {
        $offset = isset($candidate['offset']) && is_int($candidate['offset']) ? $candidate['offset'] : null;
        $length = isset($candidate['length']) && is_int($candidate['length']) ? $candidate['length'] : strlen($candidateMarkup);
        foreach ($scoped as $rows) foreach ($rows as $row) {
            if ('' !== $candidateMarkup && (str_contains($candidateMarkup, $row['markup']) || str_contains($row['markup'], $candidateMarkup))) return true;
            if (null !== $offset && $row['offset'] >= $offset && $row['offset'] < $offset + $length) return true;
        }
        return false;
    }

    /** @param array<string,mixed> $candidate @param array<int,array<string,mixed>> $rows */
    private function candidateOwnsVariant(array $candidate, array $rows): bool
    {
        $candidateMarkup = (string) ($candidate['markup'] ?? '');
        $offset = isset($candidate['offset']) && is_int($candidate['offset']) ? $candidate['offset'] : null;
        $length = isset($candidate['length']) && is_int($candidate['length']) ? $candidate['length'] : strlen($candidateMarkup);
        foreach ($rows as $row) {
            if ('' !== $candidateMarkup && (str_contains($candidateMarkup, $row['markup']) || $candidateMarkup === $row['markup'])) return true;
            if (null !== $offset && $row['offset'] >= $offset && $row['offset'] < $offset + $length) return true;
        }
        return false;
    }

    /** @param array<string,mixed> $row */
    private static function responsiveVariantClass(array $row): ?string
    {
        $classes = $row['ancestor_context']['classes'] ?? array();
        if (preg_match('/^<!--\s*wp:group\s+(\{.*?\})\s*-->/s', (string) ($row['markup'] ?? ''), $match)) {
            $attrs = json_decode($match[1], true);
            if (is_array($attrs) && is_string($attrs['className'] ?? null)) $classes = array_merge($classes, preg_split('/\s+/', $attrs['className']) ?: array());
        }
        foreach ($classes as $class) if (self::isResponsiveVariantClass((string) $class)) return (string) $class;
        return null;
    }

    private static function isResponsiveVariantClass(string $class): bool
    {
        return in_array($class, array('data-liberation-desktop-document', 'data-liberation-mobile-document'), true)
            || 1 === preg_match('/^site-document-variant-[a-z][a-z0-9_-]{0,31}$/', $class);
    }

    private static function responsiveVariantRank(string $class): int
    {
        if (in_array($class, array('data-liberation-desktop-document', 'site-document-variant-default'), true)) return 0;
        if (str_contains($class, 'mobile')) return 1;
        return 2;
    }

    private static function variantVisibilityGroup(string $variantClass, string $innerMarkup): string
    {
        $class = htmlspecialchars($variantClass, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $attrs = json_encode(array('className' => $variantClass), JSON_UNESCAPED_SLASHES);
        return '<!-- wp:group ' . $attrs . ' -->' . "\n" . '<div class="wp-block-group ' . $class . '">' . $innerMarkup . '</div>' . "\n" . '<!-- /wp:group -->';
    }

    /** @param array<string,mixed> $candidate */
    private function withoutNestedShell(string $markup, array $candidate, string $replacement = ''): ?string
    {
        $identity = (string) ($candidate['identity_markup'] ?? '');
        $area = (string) ($candidate['area'] ?? '');
        $matches = array();
        if ('' !== $identity && '' !== $area) {
            foreach ($this->nestedLandmarkCandidates($markup, (string) ($candidate['source_path'] ?? ''), $area) as $row) {
                if ($identity === ($row['identity_markup'] ?? null)) $matches[] = $row;
            }
            if (array() === $matches) {
                $headers = array();
                $footers = array();
                $this->collectNestedChrome($markup, 0, $headers, $footers);
                foreach (('footer' === $area ? $footers : $headers) as $row) {
                    if ($identity === ($row['identity_markup'] ?? null)) {
                        $matches[] = $row;
                    }
                }
            }
        }
        if (array() === $matches) {
            $ranges = $this->nestedShellRanges($markup, $candidate, $area);
            if (array() === $ranges) return null;
            $matches = $ranges;
        }
        usort($matches, static fn(array $left, array $right): int => $right['offset'] <=> $left['offset']);
        foreach ($matches as $row) $markup = substr($markup, 0, $row['offset']) . $replacement . substr($markup, $row['offset'] + $row['length']);
        return $markup;
    }

    /**
     * @param array<string,mixed> $candidate
     * @return array<int,array{offset:int,length:int}>
     */
    private function nestedShellRanges(string $markup, array $candidate, string $area): array
    {
        if (!empty($candidate['nested_shell'])) {
            $ranges = array();
            $primary = $this->nestedShellRange($markup, (string) ($candidate['markup'] ?? ''), isset($candidate['offset']) && is_int($candidate['offset']) ? $candidate['offset'] : null);
            if (is_array($primary)) $ranges[] = $primary;
            foreach ($candidate['additional_ranges'] ?? array() as $extra) {
                if (!is_array($extra) || !is_string($extra['markup'] ?? null)) continue;
                $range = $this->nestedShellRange($markup, $extra['markup'], isset($extra['offset']) && is_int($extra['offset']) ? $extra['offset'] : null);
                if (is_array($range)) $ranges[] = $range;
            }
            return $ranges;
        }
        $range = $this->topLevelShellRange($markup, $area, (string) ($candidate['markup'] ?? ''), isset($candidate['offset']) && is_int($candidate['offset']) ? $candidate['offset'] : null);
        return is_array($range) ? array($range) : array();
    }

    /**
     * @return array{offset:int,length:int}|null
     */
    private function nestedShellRange(string $markup, string $candidateMarkup, ?int $offset = null): ?array
    {
        if ('' === $candidateMarkup) return null;
        // The candidate already knows its block-tree position (computed by
        // ancestry, not by searching for its bytes). Trusting it here — after
        // confirming it still holds this content — is what makes a page with
        // two byte-identical shell fragments extract deterministically instead
        // of an unrelated duplicate elsewhere in the page manufacturing
        // ambiguity for a structurally unique candidate.
        if (null !== $offset && $offset >= 0 && $candidateMarkup === substr($markup, $offset, strlen($candidateMarkup))) {
            return array('offset' => $offset, 'length' => strlen($candidateMarkup));
        }
        $found = strpos($markup, $candidateMarkup);
        if (false === $found) return null;
        if (false !== strpos($markup, $candidateMarkup, $found + 1)) return null;
        return array('offset' => $found, 'length' => strlen($candidateMarkup));
    }

    public function replaceTopLevelShell(string $markup, string $area, string $replacement, string $candidateMarkup = '', ?int $offset = null): ?string
    {
        $range = $this->topLevelShellRange($markup, $area, $candidateMarkup, $offset);
        return is_array($range) ? substr($markup, 0, $range['offset']) . $replacement . substr($markup, $range['offset'] + $range['length']) : null;
    }

    /**
     * @return array{offset:int,length:int}|null
     */
    private function topLevelShellRange(string $markup, string $area, string $candidateMarkup = '', ?int $offset = null): ?array
    {
        if ('' !== $candidateMarkup) {
            // Trust an already-known block-tree position over a fresh search: a
            // byte-identical top-level sibling elsewhere in the page must not
            // manufacture ambiguity for a candidate whose position is already
            // structurally proven.
            if (null !== $offset && $offset >= 0 && $candidateMarkup === substr($markup, $offset, strlen($candidateMarkup))) {
                return array('offset' => $offset, 'length' => strlen($candidateMarkup));
            }
            $matches = array();
            foreach (self::topLevelBlockRanges($markup) as $range) if ($candidateMarkup === substr($markup, $range['offset'], $range['length'])) $matches[] = $range;
            if (1 === count($matches)) return $matches[0];
            if (1 < count($matches)) return null;
        }
        $candidate = null;
        foreach (self::topLevelBlockRanges($markup) as $range) {
            $token = self::blockCommentTokens(substr($markup, $range['offset'], $range['length']))[0] ?? null;
            if (!is_array($token) || ('group' !== $token['name'] && !str_ends_with($token['name'], '/layout-shell'))) continue;
            $decoded = json_decode($token['attributes'], true);
            if (!is_array($decoded)) continue;
            $tagName = 'group' === $token['name'] ? ($decoded['tagName'] ?? null) : ($decoded['wrappers'][0]['tagName'] ?? null);
            if ($area !== $tagName) continue;
            if (null !== $candidate) return null;
            $candidate = array('start' => $range['offset'], 'end' => $range['offset'] + $range['length']);
        }
        if (!is_array($candidate) || !is_int($candidate['end'])) return null;
        return array('offset' => $candidate['start'], 'length' => $candidate['end'] - $candidate['start']);
    }

    /**
     * Ranges of the blocks at one nesting depth, from the single block-comment
     * scan every structural walk in this class shares.
     *
     * @return array<int,array{offset:int,length:int}>
     */
    private static function blockRangesAtDepth(string $markup, int $depth): array
    {
        $ranges = array(); $stack = array();
        foreach (self::blockCommentTokens($markup) as $token) {
            if ($token['closing']) {
                $open = array_pop($stack);
                if (is_int($open) && count($stack) === $depth) $ranges[] = array('offset' => $open, 'length' => $token['offset'] + strlen($token['token']) - $open);
            } elseif ($token['self_closing']) {
                if (count($stack) === $depth) $ranges[] = array('offset' => $token['offset'], 'length' => strlen($token['token']));
            } else {
                $stack[] = $token['offset'];
            }
        }
        usort($ranges, static fn(array $left, array $right): int => $left['offset'] <=> $right['offset']);
        return $ranges;
    }

    /** @return array<int,array{offset:int,length:int}> */
    private static function topLevelBlockRanges(string $markup): array
    {
        return self::blockRangesAtDepth($markup, 0);
    }

    /** @return array<int,array{offset:int,length:int}> The direct children of a markup that is exactly one block. */
    private static function directChildBlockRanges(string $markup): array
    {
        return 1 === count(self::topLevelBlockRanges($markup)) ? self::blockRangesAtDepth($markup, 1) : array();
    }

    private static function isGroupBlock(string $markup): bool { return preg_match('/^<!--\s*wp:group(?:\s|\{)/', $markup) === 1; }

    private static function isCheckboxBlock(string $markup): bool { return preg_match('/^<!--\s*wp:[a-z][a-z0-9-]*\/authored-input\s+\{[^}]*"type":"checkbox"/', $markup) === 1; }

    private static function blockOpeningMarkup(string $markup): ?string
    {
        if (!preg_match('/^(<!--\s*wp:group(?:\s+[^>]*?)?-->)(<div\b[^>]*>)/s', $markup, $match)) return null;
        return $match[1] . $match[2];
    }

    private static function isShellLandmarkBlock(string $name): bool
    {
        return 'group' === $name || str_ends_with($name, '/scroll-state');
    }

    private static function footerAreaFromClassName(string $className): ?string
    {
        foreach (preg_split('/\s+/', trim($className)) ?: array() as $class) {
            if (in_array(strtolower($class), array('footer', 'site-footer', 'widget-footer', 'colophon', 'site-info'), true)) return 'footer';
        }
        return null;
    }

    /** @param array<string,mixed> $candidate @param array<string,mixed> $current */
    private static function prefersScrollStateCarrier(array $candidate, array $current): bool
    {
        return str_contains((string) ($candidate['markup'] ?? ''), '/scroll-state') && !str_contains((string) ($current['markup'] ?? ''), '/scroll-state');
    }

    private static function normalizeNestedChromeMarkup(string $markup): string
    {
        $markup = self::withoutCurrentNavigationState($markup, true);
        $markup = self::withoutScrollStateCarrierIdentity($markup);
        $markup = preg_replace('/\s*' . EngineMarker::patternBody() . '/', '', $markup) ?? $markup;
        $markup = preg_replace('/\s*blocks-engine-(?:specificity-class|disclosure-summary)-[a-f0-9]{6,}(?:-\d+)?/', '', $markup) ?? $markup;
        $markup = preg_replace('/\s*be-inline-geometry-[a-f0-9]{16}(?:-[a-f0-9]{16})?/', '', $markup) ?? $markup;
        $markup = preg_replace('/--blocks-engine-richtext-marker:\s*blocks-engine-richtext-[a-f0-9]+-\d+;?/', '', $markup) ?? $markup;
        $markup = preg_replace('/(?:\.\.\/)+assets\//', 'assets/', $markup) ?? $markup;
        $markup = preg_replace('/\bblock-[a-f0-9]{16,}\b/', 'block', $markup) ?? $markup;
        $markup = preg_replace('/("url":"[^"#\s]+)#(?:\\\\u0022|[^"\\\\])+/', '$1', $markup) ?? $markup;
        $markup = preg_replace_callback('/"className":"([^"]*)"/', static function (array $match): string {
            $classes = preg_split('/\s+/', trim($match[1])) ?: array();
            sort($classes, SORT_STRING);
            return '"className":"' . implode(' ', $classes) . '"';
        }, $markup) ?? $markup;
        $markup = self::withoutMenuSelectionState($markup);
        $markup = preg_replace_callback('/\sclass="([^"]*)"/', static fn (array $match): string => ' class="' . implode(' ', array_filter(preg_split('/\s+/', trim($match[1])) ?: array(), static fn (string $class): bool => !self::isInheritedNavigationLinkColor($class))) . '"', $markup) ?? $markup;
        // Block comments were canonicalized as JSON above; only the rendered HTML
        // between them is read as tags.
        $markup = implode('', array_map(static fn (string $piece): string => str_starts_with($piece, '<!--') ? $piece : RenderEquivalentMarkup::canonical($piece), preg_split('/(<!--.*?-->)/s', $markup, -1, PREG_SPLIT_DELIM_CAPTURE) ?: array($markup)));
        return ShellLandmarkPolicy::withoutResponsiveCorrespondenceMarkup($markup);
    }

    private static function withoutLandmarkTagName(string $markup): string
    {
        if (!preg_match('/^<!--\s*wp:((?:group|[a-z0-9-]+\/scroll-state))\s+(\{[^>]*\})\s*-->/', $markup, $match)) return $markup;
        $attrs = json_decode($match[2], true);
        $tag = is_array($attrs) ? ($attrs['tagName'] ?? null) : null;
        if (!in_array($tag, array('header', 'footer'), true)) return $markup;
        unset($attrs['tagName']);
        $encoded = json_encode($attrs, JSON_UNESCAPED_SLASHES);
        if (!is_string($encoded)) return $markup;
        $rest = substr($markup, strlen($match[0]));
        $closer = preg_quote($match[1], '/');
        $rest = preg_replace('/^<' . preg_quote($tag, '/') . '\b/', '<div', $rest, 1) ?? $rest;
        $rest = preg_replace('/<\/' . preg_quote($tag, '/') . '>(\s*<!--\s*\/wp:' . $closer . '\s*-->)\s*$/', '</div>$1', $rest, 1) ?? $rest;
        return '<!-- wp:' . $match[1] . ' ' . $encoded . ' -->' . $rest;
    }

    private static function withoutScrollStateCarrierIdentity(string $markup): string
    {
        $markup = preg_replace('/<!--\s*(\/?)wp:[a-z0-9-]+\/scroll-state\b/', '<!-- $1wp:group', $markup) ?? $markup;
        $markup = preg_replace('/\s*data-blocks-engine-scroll-state="true"/', '', $markup) ?? $markup;
        $markup = preg_replace('/\s*data-blocks-engine-scroll-state-config="[^"]*"/', '', $markup) ?? $markup;
        $markup = preg_replace('/\s*(?:wp-block-group|blocks-engine-empty-visual-group|blocks-engine-css-owned-layout|' . preg_quote(EngineMarker::EDITOR_ANCHOR_PREFIX, '/') . '[A-Za-z0-9_-]+)\b/', '', $markup) ?? $markup;
        $markup = preg_replace('/class="\s+/', 'class="', $markup) ?? $markup;
        $markup = self::canonicalizeIdentityBlockComments($markup);
        return self::withoutEmptyGroupStyleIdentity($markup);
    }

    /**
     * A resting navigation-link colour of `inherit` asks the link to use its
     * navigation's colour, which is what core navigation renders by default.
     * Whether a page's cascade restated that default does not change the chrome.
     */
    private static function isInheritedNavigationLinkColor(string $class): bool
    {
        static $inherited = null;
        if (null === $inherited) {
            $inherited = array();
            for ($mask = 0; $mask <= 15; ++$mask) $inherited['blocks-engine-navigation-link-color-' . hash('sha256', "inherit\0" . $mask)] = true;
        }
        return isset($inherited[$class]);
    }

    private static function canonicalizeIdentityBlockComments(string $markup): string
    {
        return preg_replace_callback('/<!--\s*wp:(?!\/).*?-->/s', static function (array $match): string {
            if (!preg_match('/^<!--\s*wp:(\S+)\s+(\{.*\})\s*(\/?)-->$/s', $match[0], $parts)) return $match[0];
            $attrs = json_decode($parts[2], true);
            if (!is_array($attrs)) return $match[0];
            unset($attrs['config']);
            if (in_array($attrs['metadata']['name'] ?? null, array('Header', 'Footer'), true) && 1 === count($attrs['metadata'])) unset($attrs['metadata']);
            if (array('typography' => array('lineHeight' => '1')) === ($attrs['style'] ?? null)) unset($attrs['style']);
            foreach (array('margin', 'padding') as $box) {
                if (!is_array($attrs['style']['spacing'][$box] ?? null)) continue;
                foreach ($attrs['style']['spacing'][$box] as $side => $value) if (is_string($value)) $attrs['style']['spacing'][$box][$side] = RenderEquivalentMarkup::canonicalZeroLength($value);
            }
            if (is_string($attrs['content'] ?? null)) $attrs['content'] = RenderEquivalentMarkup::canonical($attrs['content']);
            if (is_string($attrs['className'] ?? null)) {
                $classes = array_values(array_filter(preg_split('/\s+/', trim($attrs['className'])) ?: array(), static fn(string $class): bool => '' !== $class && 'wp-block-group' !== $class && 'blocks-engine-empty-visual-group' !== $class && 'blocks-engine-css-owned-layout' !== $class && null === EngineMarker::editorAnchorId($class) && !self::isInheritedNavigationLinkColor($class)));
                sort($classes, SORT_STRING);
                if (array() === $classes) unset($attrs['className']); else $attrs['className'] = implode(' ', $classes);
            }
            self::ksortRecursive($attrs);
            $encoded = json_encode($attrs, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            return is_string($encoded) ? '<!-- wp:' . $parts[1] . ' ' . $encoded . ' ' . $parts[3] . '-->' : $match[0];
        }, $markup) ?? $markup;
    }

    /** @param array<string,mixed> $value */
    private static function ksortRecursive(array &$value): void
    {
        ksort($value);
        foreach ($value as &$child) if (is_array($child)) self::ksortRecursive($child);
    }

    private static function withoutEmptyGroupStyleIdentity(string $markup): string
    {
        return preg_replace_callback('/<!-- wp:group (\{[^>]*\}) -->(\s*<[a-z0-9]+[^>]*>\s*<\/[a-z0-9]+>\s*)<!-- \/wp:group -->/', static function (array $match): string {
            $attrs = json_decode($match[1], true);
            if (!is_array($attrs) || !isset($attrs['style'])) return $match[0];
            unset($attrs['style']);
            self::ksortRecursive($attrs);
            $encoded = json_encode($attrs, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            $html = preg_replace('/\sstyle="[^"]*"/', '', $match[2]) ?? $match[2];
            return is_string($encoded) ? '<!-- wp:group ' . $encoded . ' -->' . $html . '<!-- /wp:group -->' : $match[0];
        }, $markup) ?? $markup;
    }

    public static function identityMarkup(string $markup): string
    {
        return self::normalizeNestedChromeMarkup($markup);
    }

    public static function withoutCurrentNavigationState(string $markup, bool $semanticIdentity = false): string
    {
        $stateCarrierCounts = array();
        $linkColorCounts = array();
        $linkCount = 0;
        $restingColorCountsBySignature = array();
        preg_match_all('/<!--\s*wp:navigation(?:-link|-submenu)?\s+(\{.*?\})\s*(?:\/)?-->/s', $markup, $navigationMatches);
        foreach ($navigationMatches[0] as $index => $opening) {
            $attributes = $navigationMatches[1][$index];
            $attrs = json_decode($attributes, true);
            if (!is_array($attrs)) continue;
            $isLink = !preg_match('/<!--\s*wp:navigation\s/', $opening);
            if ($isLink) ++$linkCount;
            $classes = preg_split('/\s+/', trim((string) ($attrs['className'] ?? ''))) ?: array();
            $current = in_array('blocks-engine-current-navigation-item', $classes, true);
            $signature = self::navigationClassSignature($classes);
            foreach ($classes as $class) {
                if (preg_match('/^blocks-engine-navigation-link-color-states-\d+$/', $class)) $stateCarrierCounts[$class] = ($stateCarrierCounts[$class] ?? 0) + 1;
                if ($isLink && preg_match('/^blocks-engine-navigation-link-color-[a-f0-9]{64}$/', $class)) {
                    $linkColorCounts[$class] = ($linkColorCounts[$class] ?? 0) + 1;
                    if (!$current) $restingColorCountsBySignature[$signature][$class] = ($restingColorCountsBySignature[$signature][$class] ?? 0) + 1;
                }
            }
        }
        $restingPeers = self::restingNavigationPeers($markup);
        $sharedLinkColors = array_keys(array_filter($linkColorCounts, static fn(int $count): bool => 1 < $linkCount && $linkCount - 1 === $count));
        $restingColorBySignature = array();
        foreach ($restingColorCountsBySignature as $signature => $counts) {
            arsort($counts, SORT_NUMERIC);
            $restingColorBySignature[$signature] = (string) array_key_first($counts);
        }
        $navigationIndex = -1;
        $markup = preg_replace_callback('/<!--\s*wp:(navigation(?:-link|-submenu)?)\s+(\{.*?\})\s*(\/)?-->/s', static function (array $match) use ($semanticIdentity, $stateCarrierCounts, $sharedLinkColors, $restingColorBySignature, $restingPeers, &$navigationIndex): string {
            if ('navigation' === $match[1]) ++$navigationIndex;
            $attrs = json_decode($match[2], true);
            if (!is_array($attrs)) return $match[0];
            $classes = preg_split('/\s+/', trim((string) ($attrs['className'] ?? ''))) ?: array();
            $current = in_array('blocks-engine-current-navigation-item', $classes, true);
            $peer = $restingPeers[$navigationIndex] ?? null;
            if ($current && 'navigation-link' === $match[1] && is_array($peer)) {
                // A client router can paint the current item through its own
                // utility classes instead of an aria-current hook. The shared part
                // must not freeze one page's selection, so the item takes its
                // resting peers' presentation. The current-page state is restored
                // at render time; its color stays on the navigation root marker.
                // Rebuild in the peer's key order so identical presentation
                // serializes identically regardless of which page was current.
                // Stable item classes stay; a homepage marker is not current-page state.
                $own = array_diff_key($attrs, array_flip(array('className', 'style', 'color', 'typography', 'anchor', 'anchorClassName')));
                $attrs = array_merge($peer, $own);
                $peerClasses = preg_split('/\s+/', trim((string) ($attrs['className'] ?? ''))) ?: array();
                $stable = array_values(array_filter($classes, static fn(string $class): bool => '' !== $class && !self::isCurrentPageClass($class, $peerClasses) && !preg_match('/^blocks-engine-navigation-(?:current|link)-color-[a-f0-9]{64}$/', $class) && !preg_match('/^blocks-engine-navigation-link-color-states-\d+$/', $class) && !preg_match('/^be-inline-geometry-[a-f0-9]{16}(?:-[a-f0-9]{16})?$/', $class) && !in_array($class, $peerClasses, true)));
                // The part keeps the item's own link-state carrier, which the
                // navigation-root current-color rule resolves its state against.
                $stateCarriers = $semanticIdentity ? array() : array_values(array_filter($classes, static fn(string $class): bool => 1 === preg_match('/^blocks-engine-navigation-link-color-states-\d+$/', $class)));
                $merged = array_values(array_filter(array_merge($peerClasses, $stable, $stateCarriers), static fn(string $class): bool => '' !== $class));
                if (array() === $merged) unset($attrs['className']); else $attrs['className'] = implode(' ', $merged);
                return '<!-- wp:' . $match[1] . ' ' . json_encode($attrs, JSON_UNESCAPED_SLASHES) . ' ' . (($match[3] ?? '') ? '/' : '') . '-->';
            }
            if (!$current && !$semanticIdentity) return $match[0];
            $isLink = 'navigation' !== $match[1];
            $peerClasses = is_array($peer) ? (preg_split('/\s+/', trim((string) ($peer['className'] ?? ''))) ?: array()) : array();
            $classes = array_values(array_filter($classes, static function (string $class) use ($current, $semanticIdentity, $stateCarrierCounts, $peerClasses): bool {
                if ($current && self::isCurrentPageClass($class, $peerClasses)) return false;
                if (($current || $semanticIdentity) && preg_match('/^blocks-engine-navigation-current-color-[a-f0-9]{64}$/', $class)) return false;
                if ($current && preg_match('/^blocks-engine-navigation-link-color-[a-f0-9]{64}$/', $class)) return false;
                if ($semanticIdentity && $current && 1 === ($stateCarrierCounts[$class] ?? 0)) return false;
                if ($semanticIdentity && $current && preg_match('/^be-inline-geometry-[a-f0-9]{16}(?:-[a-f0-9]{16})?$/', $class)) return false;
                return true;
            }));
            if ($semanticIdentity && $current && $isLink) {
                $resting = $restingColorBySignature[implode(' ', $classes)] ?? null;
                $classes = array_values(array_unique(array_merge($classes, $sharedLinkColors, is_string($resting) ? array($resting) : array())));
            }
            $attrs['className'] = implode(' ', $classes);
            if ('' === $attrs['className']) unset($attrs['className']);
            if ($current) unset($attrs['color'], $attrs['style'], $attrs['typography']);
            if ($current || ($semanticIdentity && $isLink)) unset($attrs['anchor'], $attrs['anchorClassName']);
            return '<!-- wp:' . $match[1] . ' ' . json_encode($attrs, JSON_UNESCAPED_SLASHES) . ' ' . (($match[3] ?? '') ? '/' : '') . '-->';
        }, $markup) ?? $markup;
        // A menu authored as plain links (list items, rich text) marks the
        // served route with aria-current="page" in saved content. One shared
        // part serves every route, so that page-scoped state is neither part of
        // the chrome's identity nor frozen into the part.
        $markup = preg_replace('/(<a\b[^>]*?)\s+aria-current\s*=\s*(["\'])page\2/i', '$1', $markup) ?? $markup;
        return self::withoutMenuSelectionState($markup, true);
    }

    private static function withoutMenuSelectionState(string $markup, bool $resting = false): string
    {
        $replacement = $resting ? 'menu false link' : 'menu link';
        $markup = preg_replace('/(data-state=)(\\\\u0022|"|&quot;)menu (?:selected|false)\s+link\2/', '$1$2' . $replacement . '$2', $markup) ?? $markup;
        return preg_replace('/\s*(aria-current=)(\\\\u0022|"|&quot;)page\2/', '', $markup) ?? $markup;
    }

    /**
     * Per navigation block (document order), the presentation shared by at
     * least two of its non-current links: className plus color/style attrs.
     *
     * @return array<int,array<string,mixed>|null>
     */
    private static function restingNavigationPeers(string $markup): array
    {
        $peers = array(); $index = -1; $groups = array();
        preg_match_all('/<!--\s*wp:(navigation(?:-link|-submenu)?)\s+(\{.*?\})\s*(?:\/)?-->/s', $markup, $matches, PREG_SET_ORDER);
        foreach ($matches as $match) {
            if ('navigation' === $match[1]) { ++$index; $groups[$index] = array(); continue; }
            if ('navigation-link' !== $match[1] || $index < 0) continue;
            $attrs = json_decode($match[2], true);
            if (!is_array($attrs)) continue;
            $classes = preg_split('/\s+/', trim((string) ($attrs['className'] ?? ''))) ?: array();
            if (in_array('blocks-engine-current-navigation-item', $classes, true)) continue;
            $presentation = array_intersect_key($attrs, array_flip(array('className', 'style', 'color', 'typography')));
            if (is_string($presentation['className'] ?? null)) {
                $classes = array_values(array_filter($classes, static fn(string $class): bool => !preg_match('/^blocks-engine-attribute-[a-f0-9]{6,}(?:-\d+)?$/', $class)));
                sort($classes, SORT_STRING);
                $presentation['className'] = implode(' ', $classes);
            }
            $key = json_encode($presentation);
            $groups[$index][$key] = array('count' => ($groups[$index][$key]['count'] ?? 0) + 1, 'presentation' => $presentation);
        }
        foreach ($groups as $navigation => $candidates) {
            uasort($candidates, static fn(array $left, array $right): int => $right['count'] <=> $left['count']);
            $top = reset($candidates);
            $peers[$navigation] = is_array($top) && 2 <= $top['count'] ? $top['presentation'] : null;
        }
        return $peers;
    }

    /** @param array<int,string> $peerClasses */
    private static function isCurrentPageClass(string $class, array $peerClasses = array()): bool
    {
        if (in_array($class, array('blocks-engine-current-navigation-item', 'blocks-engine-current-navigation-underline', 'current', 'active', 'selected'), true)) return true;
        if (1 !== preg_match('/(?:^|[-_])(?:is-)?(?:current|active|selected|on)$/', $class)) return false;
        return !in_array($class, $peerClasses, true);
    }

    /** @param array<int,string> $classes */
    private static function navigationClassSignature(array $classes): string
    {
        return implode(' ', array_values(array_filter($classes, static function (string $class): bool {
            return !in_array($class, array('blocks-engine-current-navigation-item', 'blocks-engine-current-navigation-underline', 'current', 'active', 'selected'), true)
                && !preg_match('/^blocks-engine-navigation-(?:current|link)-color-[a-f0-9]{64}$/', $class)
                && !preg_match('/^be-inline-geometry-[a-f0-9]{16}(?:-[a-f0-9]{16})?$/', $class);
        })));
    }
}
