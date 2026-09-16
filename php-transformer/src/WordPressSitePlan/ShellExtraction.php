<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan;

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\RuntimeDeclarations;
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
            // Resolve this declared candidate's block-tree position now, while it is
            // still guaranteed unique for its area. Later removal reuses this
            // position directly instead of re-deriving it by searching for the
            // candidate's bytes, which a coincidental duplicate elsewhere in the
            // page could otherwise make ambiguous.
            $range = $this->topLevelShellRange($canonical, (string) $candidate['area'], $markup);
            $row = array('area' => $candidate['area'], 'markup' => $markup, 'inner_markup' => $innerMarkup, 'template_part_markup' => $templatePartMarkup, 'classes' => $classes, 'source_path' => $sourcePath, 'source_hash' => is_string($candidate['source_hash'] ?? null) ? $candidate['source_hash'] : '');
            if (is_array($range)) { $row['offset'] = $range['offset']; $row['length'] = $range['length']; }
            $candidates[] = $row;
        }
        $sourcePath = WordPressSitePlan::value($document, 'source_path');
        $nestedChrome = $this->nestedChromeCandidates($canonical, $sourcePath);
        if (array() !== $nestedChrome) return array_merge($candidates, $nestedChrome);
        return array_merge($candidates, $this->nestedLandmarkShellCandidates($canonical, $sourcePath, array_column($candidates, 'area')));
    }

    /** @return array<int,array<string,mixed>> */
    private function nestedChromeCandidates(string $markup, string $sourcePath): array
    {
        $topLevel = self::topLevelBlockRanges($markup);
        foreach ($topLevel as $index => $wrapperRange) {
            $wrapper = substr($markup, $wrapperRange['offset'], $wrapperRange['length']);
            $preceding = $topLevel[$index - 1] ?? null;
            $toggle = is_array($preceding) ? substr($markup, $preceding['offset'], $preceding['length']) : '';
            // The responsive core/navigation overlay supersedes the only allowed
            // sibling: its legacy authored checkbox toggle.
            if (count($topLevel) !== $index + 1 || (0 < $index && (1 !== $index || !self::isCheckboxBlock($toggle)))) continue;
            $children = self::directChildBlockRanges($wrapper);
            if (2 !== count($children) || !self::isGroupBlock($wrapper)) continue;
            $navigationChildren = array_values(array_filter($children, static fn(array $range): bool => str_contains(substr($wrapper, $range['offset'], $range['length']), '<!-- wp:navigation ')));
            if (1 !== count($navigationChildren)) continue;
            $chromeRange = $navigationChildren[0];
            $contentRange = current(array_filter($children, static fn(array $range): bool => $range !== $chromeRange));
            if (!is_array($contentRange) || !self::isGroupBlock(substr($wrapper, $contentRange['offset'], $contentRange['length']))) continue;
            $chrome = substr($wrapper, $chromeRange['offset'], $chromeRange['length']);
            $content = substr($wrapper, $contentRange['offset'], $contentRange['length']);
            $opening = self::blockOpeningMarkup($wrapper);
            if (null === $opening) continue;
            $contentOffset = $wrapperRange['offset'] + $contentRange['offset'];
            $identity = self::normalizeNestedChromeMarkup($chrome);
            if ('' === $identity) continue;
            return array(array('area' => 'header', 'markup' => $chrome, 'inner_markup' => $chrome, 'template_part_markup' => self::withoutCurrentNavigationState($chrome), 'identity_markup' => $identity, 'classes' => array(), 'source_path' => $sourcePath, 'source_hash' => hash('sha256', $chrome), 'legacy_container_opening' => $opening, 'legacy_container_closing' => '<!-- /wp:group -->', 'legacy_content_markup' => $content, 'legacy_content_range' => array('offset' => $contentOffset, 'length' => $contentRange['length']), 'legacy_page_markup' => $markup));
        }
        return array();
    }

    /** @param array<int,string> $occupiedAreas @return array<int,array<string,mixed>> */
    private function nestedLandmarkShellCandidates(string $markup, string $sourcePath, array $occupiedAreas): array
    {
        $candidates = array();
        foreach (array('header', 'footer') as $area) {
            if (in_array($area, $occupiedAreas, true)) continue;
            $rows = $this->nestedLandmarkCandidates($markup, $sourcePath, $area);
            if (1 !== count($rows)) continue;
            $row = $rows[0];
            $identity = $row['identity_markup'];
            if ('' === $identity) continue;
            $partMarkup = self::withoutLandmarkTagName(self::withoutCurrentNavigationState($row['markup']));
            // The candidate's own block-tree offset is carried forward so removal
            // never needs to re-derive its position by searching for its bytes.
            $candidates[] = array('area' => $area, 'markup' => $row['markup'], 'inner_markup' => $row['markup'], 'template_part_markup' => $partMarkup, 'identity_markup' => $identity, 'classes' => array(), 'source_path' => $sourcePath, 'source_hash' => $row['source_hash'], 'nested_shell' => true, 'offset' => $row['offset'], 'length' => $row['length']);
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
                if (array() === $rows || (null !== $variantCount && $variantCount !== count($rows))) { $rejected = true; break; }
                $variantCount = count($rows); $candidates[$index] = $rows;
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

    /** @return array<int,array<string,mixed>> */
    private function nestedLandmarkCandidates(string $markup, string $sourcePath, string $area): array
    {
        $rows = array(); $stack = array();
        if (!preg_match_all('/<!--\s*(\/?)wp:([^\s]+)(?:\s+([^>]*?))?\s*(\/?)-->/s', $markup, $matches, PREG_OFFSET_CAPTURE)) return $rows;
        foreach ($matches[0] as $index => $match) {
            $token = $match[0]; $offset = $match[1]; $closing = '' !== $matches[1][$index][0]; $selfClosing = '' !== $matches[4][$index][0] || str_ends_with(rtrim($token), '/-->');
            if ($closing) {
                $open = array_pop($stack);
                if (!is_array($open) || empty($open['candidate'])) continue;
                $length = $offset + strlen($token) - $open['offset']; $candidateMarkup = substr($markup, $open['offset'], $length);
                $rows[] = array('area' => $area, 'markup' => $candidateMarkup, 'identity_markup' => self::normalizeNestedChromeMarkup($candidateMarkup), 'source_path' => $sourcePath, 'source_hash' => hash('sha256', $candidateMarkup), 'offset' => $open['offset'], 'length' => $length);
                continue;
            }
            $name = $matches[2][$index][0]; $attributes = trim($matches[3][$index][0] ?? ''); $attrs = '' === $attributes ? array() : json_decode($attributes, true);
            $disallowedAncestor = false;
            foreach ($stack as $ancestor) if (in_array($ancestor['tag_name'] ?? null, array('main', 'article', 'section', 'aside'), true)) { $disallowedAncestor = true; break; }
            $tagName = is_array($attrs) ? ($attrs['tagName'] ?? null) : null;
            $candidate = 0 < count($stack) && !$disallowedAncestor && 'group' === $name && $area === $tagName;
            if (!$selfClosing) $stack[] = array('offset' => $offset, 'tag_name' => $tagName, 'candidate' => $candidate);
        }
        usort($rows, static fn(array $left, array $right): int => $left['offset'] <=> $right['offset']);
        foreach ($rows as $variant => &$row) $row['variant'] = $variant; unset($row);
        return $rows;
    }

    /** @param array<int,array<string,mixed>> $pages @param array<string,true> $reservedSlugs @param array<int,array<string,mixed>> $runtimeDeclarations @return array{pages:array<int,array<string,mixed>>,parts:array<int,array<string,mixed>>,runtime_declarations:array<int,array<string,mixed>>,diagnostics:array<int,array<string,mixed>>} */
    public function sharedShells(array $pages, array $reservedSlugs = array(), array $runtimeDeclarations = array()): array
    {
        $parts = array(); $diagnostics = array();
        foreach (array('footer', 'header') as $area) {
            $candidates = array(); $clusters = array(); $excluded = array(); $overrides = array();
            $templateSlugs = array(); $excludedTemplateSlugs = array();
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
                $clusters[$candidateIdentity]['candidate'] = $candidate;
                $clusters[$candidateIdentity]['indexes'][] = $index;
            }
            uasort($clusters, static fn(array $left, array $right): int => count($right['indexes']) <=> count($left['indexes']) ?: strcmp($left['candidate']['source_path'], $right['candidate']['source_path']));
            $identity = array_key_first($clusters);
            $cluster = null === $identity ? null : $clusters[$identity];
            $runnerUp = array_values($clusters)[1] ?? null;
            if (!is_array($cluster) || (count($cluster['indexes']) < count($applicable) && (count($cluster['indexes']) < 2 || (is_array($runnerUp) && count($cluster['indexes']) === count($runnerUp['indexes']))))) {
                $reason = array() === $clusters ? 'incomplete' : 'non_equivalent';
                $diagnostics[] = array('code' => 'wordpress_site_plan_shell_retained_' . ('incomplete' === $reason ? 'incomplete' : 'ambiguous'), 'severity' => 'info', 'message' => "{$area} shell candidates do not establish a dominant semantic cluster.", 'area' => $area, 'provenance' => $this->shellProvenance($area, 'retained', $reason, $candidates));
                continue;
            }
            $first = $cluster['candidate'];
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
                        ? $this->withoutNestedShell($page['canonical_block_markup'], $candidate['markup'], $candidate['offset'] ?? null)
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
            foreach ($cluster['indexes'] as $index) {
                $page = $pages[$index];
                $candidate = $candidates[$index][0];
                $legacyContentRange = $candidate['legacy_content_range'] ?? null;
                $range = !empty($candidate['nested_shell'])
                    ? $this->nestedShellRange($page['canonical_block_markup'], $candidate['markup'], $candidate['offset'] ?? null)
                    : $this->topLevelShellRange($page['canonical_block_markup'], $area, $candidate['markup'], $candidate['offset'] ?? null);
                $containsBinding = is_array($legacyContentRange)
                    ? $this->shellContainsRuntimeBindingOutsideRange($runtimeDeclarations, $page, $legacyContentRange['offset'], $legacyContentRange['length'])
                    : (is_array($range) && $this->shellContainsRuntimeBinding($runtimeDeclarations, $page, $range['offset'], $range['length']));
                if ($containsBinding) {
                    $diagnostics[] = array('code' => 'wordpress_site_plan_shell_retained_runtime_binding', 'severity' => 'info', 'message' => "{$area} shell remains page-owned because it contains a runtime entity binding anchor.", 'area' => $area, 'source_path' => $page['source_path'], 'provenance' => $this->shellProvenance($area, 'retained', 'runtime_binding', $candidates));
                    $retainedForRuntimeBinding = true;
                    break;
                }
            }
            if ($retainedForRuntimeBinding) continue;
            foreach ($withoutShells as $index => $withoutShell) {
                $pages[$index]['canonical_block_markup'] = $withoutShell;
                $pages[$index]['content_hash'] = WordPressSitePlan::contentHash($withoutShell);
            }
            foreach ($runtimeDeclarations as &$declaration) unset($declaration['reconciliation_identity'], $declaration['payload_hash'], $declaration['content_hash']); unset($declaration);
            $runtimeDeclarations = RuntimeDeclarations::normalizeList($runtimeDeclarations);
            $singlePage = 1 === count($applicable) && 1 === count($cluster['indexes']);
            $sourcePath = $singlePage ? $pages[array_key_first($applicable)]['source_path'] : 'wordpress-site-plan/shared/' . $area;
            $placement = $singlePage ? 'entry_shell' : 'shared_shell';
            if ($singlePage) $templateSlugs = array('front-page');
            $partMarkup = $first['template_part_markup'];
            $container = isset($first['legacy_container_opening']) ? array('opening' => $first['legacy_container_opening'], 'closing' => $first['legacy_container_closing']) : null;
            $parts[] = array('source_path' => $sourcePath . '#' . $area, 'slug' => $area, 'title' => ucfirst($area), 'post_type' => 'wp_template_part', 'parent_source_path' => '', 'entrypoint' => false, 'area' => $area, 'tag_name' => ShellLandmarkPolicy::templatePartAreaTagName($area), 'placement' => array_filter(array('kind' => $placement, 'source_path' => $sourcePath, 'template_slugs' => $templateSlugs, 'excluded_template_slugs' => $excludedTemplateSlugs, 'container' => $container), static fn(mixed $value): bool => array() !== $value && null !== $value), 'canonical_block_markup' => $partMarkup, 'metadata' => array(), 'document_metadata' => array('source_context' => array('source_path' => $sourcePath . '#' . $area, 'kind' => 'template_part'), 'title' => ucfirst($area), 'title_declaration' => array('order' => 0, 'placement' => 'head'), 'meta' => array(), 'links' => array(), 'scripts' => array()), 'provenance' => $this->shellProvenance($area, 'extracted', 'canonical', $candidates, $identity), 'reconciliation_identity' => WordPressSitePlan::identity('template-part', $sourcePath . '#' . $area, 'parts/' . $area . '.html'), 'content_hash' => WordPressSitePlan::contentHash($partMarkup));
            $diagnostics[] = array('code' => $singlePage ? 'wordpress_site_plan_shell_entry_extracted' : 'wordpress_site_plan_shell_extracted', 'severity' => 'info', 'message' => $singlePage ? "Extracted the entry {$area} shell for the front-page template." : "Extracted the dominant semantically equivalent {$area} shell cluster.", 'area' => $area, 'page_count' => count($cluster['indexes']), 'applicable_page_count' => count($applicable), 'exclusions' => array_map(static fn(int $index, string $reason): array => array('source_path' => $pages[$index]['source_path'], 'reason' => $reason), array_keys($excluded), $excluded));
        }
        foreach ($pages as &$page) unset($page['shell_candidates']); unset($page);
        return array('pages' => $pages, 'parts' => $parts, 'runtime_declarations' => $runtimeDeclarations, 'diagnostics' => $diagnostics);
    }

    /** @param array<int,array<string,mixed>> $declarations @param array<string,mixed> $page */
    private function shellContainsRuntimeBinding(array $declarations, array $page, int $offset, int $length): bool
    {
        foreach ($declarations as $declaration) foreach ($declaration['payload']['entities'] ?? array() as $entity) foreach (is_array($entity) ? ($entity['bindings'] ?? array()) : array() as $binding) {
            $position = $binding['position'] ?? null;
            if (($binding['source_path'] ?? null) !== ($page['source_path'] ?? null)) continue;
            $search = $binding['search_block_markup'] ?? null;
            if (!is_string($search) || !WordPressSitePlan::bindingPosition($position, $page['canonical_block_markup'], $search)) continue;
            $indexedRange = WordPressSitePlan::blockRanges($page['canonical_block_markup'])[$position['block_index']] ?? null;
            if (is_array($indexedRange) && $indexedRange['offset'] >= $offset && $indexedRange['offset'] + $indexedRange['length'] <= $offset + $length) return true;
        }
        return false;
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

    private function withoutNestedShell(string $markup, string $candidateMarkup, ?int $offset = null): ?string
    {
        $range = $this->nestedShellRange($markup, $candidateMarkup, $offset);
        return is_array($range) ? substr($markup, 0, $range['offset']) . substr($markup, $range['offset'] + $range['length']) : null;
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
        if (!preg_match_all('/<!--\s*(\/?)wp:([^\s]+)(?:\s+([^>]*?))?\s*-->/s', $markup, $matches, PREG_OFFSET_CAPTURE)) return null;
        $depth = 0; $candidate = null;
        foreach ($matches[0] as $index => $comment) {
            $full = $comment[0]; $offset = $comment[1]; $closing = '' !== $matches[1][$index][0];
            if ($closing) { --$depth; if (is_array($candidate) && null === $candidate['end'] && $depth === $candidate['depth']) $candidate['end'] = $offset + strlen($full); continue; }
            $selfClosing = str_ends_with(trim($full), '/-->');
            $name = $matches[2][$index][0]; $attributes = trim($matches[3][$index][0] ?? '');
            if (0 === $depth && ('group' === $name || str_ends_with($name, '/layout-shell'))) {
                $decoded = json_decode($attributes, true);
                $tagName = 'group' === $name
                    ? ($decoded['tagName'] ?? null)
                    : ($decoded['wrappers'][0]['tagName'] ?? null);
                if (is_array($decoded) && $area === $tagName) {
                    if (null !== $candidate) return null;
                    $candidate = array('start' => $offset, 'depth' => $depth, 'end' => $selfClosing ? $offset + strlen($full) : null);
                }
            }
            if (!$selfClosing) ++$depth;
        }
        if (!is_array($candidate) || !is_int($candidate['end'])) return null;
        return array('offset' => $candidate['start'], 'length' => $candidate['end'] - $candidate['start']);
    }

    /** @return array<int,array{offset:int,length:int}> */
    private static function topLevelBlockRanges(string $markup): array
    {
        $ranges = array(); $stack = array();
        if (!preg_match_all('/<!--\s*(\/?)wp:[^>]*?(\/?)\s*-->/s', $markup, $matches, PREG_OFFSET_CAPTURE)) return $ranges;
        foreach ($matches[0] as $index => $match) {
            $token = $match[0]; $offset = $match[1]; $closing = '' !== $matches[1][$index][0]; $selfClosing = str_ends_with(rtrim($token), '/-->');
            if ($closing) {
                $open = array_pop($stack);
                if (is_array($open) && 0 === count($stack)) $ranges[] = array('offset' => $open['offset'], 'length' => $offset + strlen($token) - $open['offset']);
            } elseif ($selfClosing) {
                if (array() === $stack) $ranges[] = array('offset' => $offset, 'length' => strlen($token));
            } else {
                $stack[] = array('offset' => $offset);
            }
        }
        return $ranges;
    }

    /** @return array<int,array{offset:int,length:int}> */
    private static function directChildBlockRanges(string $markup): array
    {
        $ranges = self::topLevelBlockRanges($markup);
        if (1 !== count($ranges)) return array();
        $children = array(); $stack = array();
        if (!preg_match_all('/<!--\s*(\/?)wp:[^>]*?(\/?)\s*-->/s', $markup, $matches, PREG_OFFSET_CAPTURE)) return $children;
        foreach ($matches[0] as $index => $match) {
            $token = $match[0]; $offset = $match[1]; $closing = '' !== $matches[1][$index][0]; $selfClosing = str_ends_with(rtrim($token), '/-->');
            if ($closing) {
                $open = array_pop($stack);
                if (is_array($open) && 1 === count($stack)) $children[] = array('offset' => $open['offset'], 'length' => $offset + strlen($token) - $open['offset']);
            } elseif (!$selfClosing) {
                $stack[] = array('offset' => $offset);
            } elseif (1 === count($stack)) {
                $children[] = array('offset' => $offset, 'length' => strlen($token));
            }
        }
        return $children;
    }

    private static function isGroupBlock(string $markup): bool { return preg_match('/^<!--\s*wp:group(?:\s|\{)/', $markup) === 1; }

    private static function isCheckboxBlock(string $markup): bool { return preg_match('/^<!--\s*wp:[a-z][a-z0-9-]*\/authored-input\s+\{[^}]*"type":"checkbox"/', $markup) === 1; }

    private static function blockOpeningMarkup(string $markup): ?string
    {
        if (!preg_match('/^(<!--\s*wp:group(?:\s+[^>]*?)?-->)(<div\b[^>]*>)/s', $markup, $match)) return null;
        return $match[1] . $match[2];
    }

    private static function normalizeNestedChromeMarkup(string $markup): string
    {
        $markup = self::withoutCurrentNavigationState($markup, true);
        $markup = preg_replace('/\s*blocks-engine-(?:source-[a-z0-9_-]+|attribute(?:-state)?|richtext|control|specificity-class)-[a-f0-9]{6,}(?:-\d+)?/', '', $markup) ?? $markup;
        return preg_replace('/--blocks-engine-richtext-marker:\s*blocks-engine-richtext-[a-f0-9]+-\d+;?/', '', $markup) ?? $markup;
    }

    private static function withoutLandmarkTagName(string $markup): string
    {
        if (!preg_match('/^<!--\s*wp:group\s+(\{[^>]*\})\s*-->/', $markup, $match)) return $markup;
        $attrs = json_decode($match[1], true);
        $tag = is_array($attrs) ? ($attrs['tagName'] ?? null) : null;
        if (!in_array($tag, array('header', 'footer'), true)) return $markup;
        unset($attrs['tagName']);
        $encoded = json_encode($attrs, JSON_UNESCAPED_SLASHES);
        if (!is_string($encoded)) return $markup;
        $rest = substr($markup, strlen($match[0]));
        $rest = preg_replace('/^<' . preg_quote($tag, '/') . '\b/', '<div', $rest, 1) ?? $rest;
        $rest = preg_replace('/<\/' . preg_quote($tag, '/') . '>(\s*<!--\s*\/wp:group\s*-->)\s*$/', '</div>$1', $rest, 1) ?? $rest;
        return '<!-- wp:group ' . $encoded . ' -->' . $rest;
    }

    private static function withoutCurrentNavigationState(string $markup, bool $semanticIdentity = false): string
    {
        $stateCarrierCounts = array();
        $linkColorCounts = array();
        $linkCount = 0;
        preg_match_all('/<!--\s*wp:navigation(?:-link|-submenu)?\s+(\{.*?\})\s*(?:\/)?-->/s', $markup, $navigationMatches);
        foreach ($navigationMatches[0] as $index => $opening) {
            $attributes = $navigationMatches[1][$index];
            $attrs = json_decode($attributes, true);
            if (!is_array($attrs)) continue;
            $isLink = !preg_match('/<!--\s*wp:navigation\s/', $opening);
            if ($isLink) ++$linkCount;
            foreach (preg_split('/\s+/', trim((string) ($attrs['className'] ?? ''))) ?: array() as $class) {
                if (preg_match('/^blocks-engine-navigation-link-color-states-\d+$/', $class)) $stateCarrierCounts[$class] = ($stateCarrierCounts[$class] ?? 0) + 1;
                if ($isLink && preg_match('/^blocks-engine-navigation-link-color-[a-f0-9]{64}$/', $class)) $linkColorCounts[$class] = ($linkColorCounts[$class] ?? 0) + 1;
            }
        }
        $sharedLinkColors = array_keys(array_filter($linkColorCounts, static fn(int $count): bool => 1 < $linkCount && $linkCount - 1 === $count));
        return preg_replace_callback('/<!--\s*wp:(navigation(?:-link|-submenu)?)\s+(\{.*?\})\s*(\/)?-->/s', static function (array $match) use ($semanticIdentity, $stateCarrierCounts, $sharedLinkColors): string {
            $attrs = json_decode($match[2], true);
            if (!is_array($attrs)) return $match[0];
            $classes = preg_split('/\s+/', trim((string) ($attrs['className'] ?? ''))) ?: array();
            $current = in_array('blocks-engine-current-navigation-item', $classes, true);
            if (!$current && !$semanticIdentity) return $match[0];
            $isLink = 'navigation' !== $match[1];
            $classes = array_values(array_filter($classes, static function (string $class) use ($current, $semanticIdentity, $stateCarrierCounts): bool {
                if ($current && in_array($class, array('blocks-engine-current-navigation-item', 'blocks-engine-current-navigation-underline', 'current', 'active', 'selected'), true)) return false;
                if (($current || $semanticIdentity) && preg_match('/^blocks-engine-navigation-current-color-[a-f0-9]{64}$/', $class)) return false;
                if ($current && preg_match('/^blocks-engine-navigation-link-color-[a-f0-9]{64}$/', $class)) return false;
                if ($semanticIdentity && $current && 1 === ($stateCarrierCounts[$class] ?? 0)) return false;
                if ($current && preg_match('/^be-inline-geometry-[a-f0-9]{64}$/', $class)) return false;
                return true;
            }));
            if ($semanticIdentity && $current && $isLink) $classes = array_values(array_unique(array_merge($classes, $sharedLinkColors)));
            $attrs['className'] = implode(' ', $classes);
            if ('' === $attrs['className']) unset($attrs['className']);
            if ($current) unset($attrs['color'], $attrs['style'], $attrs['typography']);
            if ($current || ($semanticIdentity && $isLink)) unset($attrs['anchor'], $attrs['anchorClassName']);
            return '<!-- wp:' . $match[1] . ' ' . json_encode($attrs, JSON_UNESCAPED_SLASHES) . ' ' . (($match[3] ?? '') ? '/' : '') . '-->';
        }, $markup) ?? $markup;
    }
}
