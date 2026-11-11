<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan;

use Automattic\BlocksEngine\PhpTransformer\Support\ArrayField;
use Automattic\BlocksEngine\PhpTransformer\Support\HtmlSerialization;
use Automattic\BlocksEngine\PhpTransformer\Css\CssStylesheetTransformer;
use Automattic\BlocksEngine\PhpTransformer\Css\CssSelectorMatcher;
use Automattic\BlocksEngine\PhpTransformer\WordPress\SourceClassIdentity;
use Automattic\BlocksEngine\PhpTransformer\Support\BlockAttributes;
use Automattic\BlocksEngine\PhpTransformer\Contract\CaptureEvidence;
use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\RuntimeDeclarations;
use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\RuntimeEntityManifest;
use Automattic\BlocksEngine\PhpTransformer\Support\EngineMarker;
use Automattic\BlocksEngine\PhpTransformer\Support\RenderEquivalentMarkup;
use Automattic\BlocksEngine\PhpTransformer\Support\DocumentVariantIds;
use Automattic\BlocksEngine\PhpTransformer\Support\ShellLandmarkPolicy;
use Automattic\BlocksEngine\PhpTransformer\Support\StylesheetActivation;

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
    /**
     * Capture-document siblings after the root owning main are page chrome,
     * not article content. Keep their device scope when moving them to the
     * shared single template. Visibility is deliberately irrelevant: hidden
     * blocks inside main (and ordinary authored wrappers) remain content.
     * Runtime-bound siblings stay put rather than stranding their bindings.
     *
     * @param array<int,array<string,mixed>> $pages
     * @param array<int,array<string,mixed>> $runtimeDeclarations
     * @param array<int,array<string,mixed>> $runtimeEntityRecords
     * @return array{pages:array<int,array<string,mixed>>,chrome:array<string,string>,diagnostics:array<int,array<string,mixed>>}
     */
    public function separateCaptureDocumentChrome(array $pages, array $runtimeDeclarations, array $runtimeEntityRecords = array()): array
    {
        $this->resolveManifestEntities($runtimeDeclarations, $runtimeEntityRecords);
        $chrome = array();
        $variants = array();
        $diagnostics = array();
        $postCount = count(array_filter($pages, static fn(array $page): bool => 'post' === ($page['post_type'] ?? null)));
        foreach ($pages as $index => $page) {
            if ('post' !== ($page['post_type'] ?? null)) continue;
            $markup = (string) ($page['canonical_block_markup'] ?? '');
            $nodes = self::blockNodes(new \Automattic\BlocksEngine\PhpTransformer\Support\BlockMarkupIndex($markup));
            $removals = array();
            foreach (self::topLevelNodeIds($nodes) as $id) {
                $wrapper = $nodes[$id];
                $children = $wrapper['children'];
                if (count($children) < 2) continue;
                $opening = substr($markup, $wrapper['offset'], $nodes[$children[0]]['offset'] - $wrapper['offset']);
                if (!preg_match('/\bclass="([^"]*)"/', $opening, $match)) continue;
                $classes = preg_split('/\s+/', $match[1]) ?: array();
                if (array() === array_filter($classes, CaptureEvidence::isDeviceDocumentClass(...))) continue;
                $roots = array();
                foreach ($children as $position => $child) {
                    if (self::nodesHoldMain($markup, $nodes, array($child))) $roots[] = $position;
                }
                if (1 !== count($roots)) continue;
                // The content root contains main; main itself is not the root.
                // In a flat header/main/footer document the footer remains the
                // shell extractor's responsibility, not a trailing scaffold.
                $root = $nodes[$children[$roots[0]]];
                if (preg_match('/"tagName"\s*:\s*"main"/', $root['attributes'])) continue;
                $leading = array_slice($children, 0, $roots[0]);
                $trailing = array_slice($children, $roots[0] + 1);
                if (array() === $leading && array() === $trailing) continue;
                $closing = substr($markup, $nodes[$children[count($children) - 1]]['end'], $wrapper['end'] - $nodes[$children[count($children) - 1]]['end']);
                $device = CaptureEvidence::deviceDocumentProfile((string) current(array_filter($classes, CaptureEvidence::isDeviceDocumentClass(...)))) ?? 'desktop';
                $sides = array_fill_keys($leading, 'leading') + array_fill_keys($trailing, 'trailing');
                foreach ($sides as $child => $side) {
                    $range = array('offset' => $nodes[$child]['offset'], 'length' => $nodes[$child]['end'] - $nodes[$child]['offset']);
                    $bindings = $this->runtimeBindingsInRanges($runtimeDeclarations, $page, array($range));
                    if ($bindings['blocked'] || array() !== $bindings['refs']) continue;
                    $sibling = substr($markup, $range['offset'], $range['length']);
                    // Compare complete sibling content, not just its outer shell:
                    // avatar lists and other per-request data are not design.
                    $identity = self::documentChromeIdentity($sibling);
                    $variants[$device]['opening'] ??= $opening;
                    $variants[$device]['closing'] ??= $closing;
                    $variants[$device]['siblings'][$identity]['markup'] ??= $sibling;
                    $variants[$device]['siblings'][$identity]['position'] = $side;
                    $variants[$device]['siblings'][$identity]['sources'][(string) $page['source_path']] = true;
                    $removals[] = $range;
                }
            }
            if (array() === $removals) continue;
            usort($removals, static fn(array $a, array $b): int => $b['offset'] <=> $a['offset']);
            foreach ($removals as $range) $markup = substr_replace($markup, '', $range['offset'], $range['length']);
            $pages[$index]['canonical_block_markup'] = $markup;
            $pages[$index]['content_hash'] = WordPressSitePlan::contentHash($markup);
        }
        $shared = '';
        foreach ($variants as $variant) {
            $leading = ''; $trailing = '';
            foreach ($variant['siblings'] as $sibling) {
                if (count($sibling['sources']) === $postCount) {
                    if ('leading' === $sibling['position']) $leading .= $sibling['markup']; else $trailing .= $sibling['markup'];
                    continue;
                }
                preg_match('/"anchor"\s*:\s*"([^"]+)"|\bid="([^"]+)"|\bclass="([^"]+)"/', $sibling['markup'], $label);
                $anchor = $label[1] ?? '';
                if ('' === $anchor) $anchor = $label[2] ?? '';
                if ('' === $anchor) $anchor = $label[3] ?? 'unlabelled sibling';
                foreach (array_keys($sibling['sources']) as $source) {
                    $diagnostics[] = array('code' => 'wordpress_site_plan_document_chrome_varying_omitted', 'severity' => 'info', 'source_path' => $source, 'anchor_or_class' => $anchor, 'message' => 'Omitted per-post document chrome (' . $anchor . '); per-request output is not shared template design.');
                }
            }
            if ('' !== $leading || '' !== $trailing) $shared .= $variant['opening'] . $leading . '<!-- wp:post-content /-->' . $trailing . $variant['closing'];
        }
        if ('' !== $shared) $chrome['single'] = $shared;
        return array('pages' => $pages, 'chrome' => $chrome, 'diagnostics' => $diagnostics);
    }

    /**
     * Isolated artifacts prove equal source landmarks across routes. Responsive
     * variants retain page-compiled presentation inside their device shells;
     * isolated identity must not replace selector-derived classes on those boxes.
     * @param array<int,array<string,mixed>> $pages
     * @param array<int,array<string,mixed>> $artifacts
     * @return array<int,array<string,mixed>>
     */
    public function withSourceSharedShellIdentity(array $pages, array $artifacts, array $runtimeDeclarations = array(), array $runtimeEntityRecords = array()): array
    {
        $this->resolveManifestEntities($runtimeDeclarations, $runtimeEntityRecords);
        $identities = array();
        foreach ($pages as $page) foreach ($page['shell_candidates'] ?? array() as $candidate) {
            $identities[$candidate['area']][$candidate['identity_markup'] ?? $candidate['markup']] = true;
        }
        $variantIdentities = array(); $sourceRows = array();
        foreach ($pages as $page) {
            $index = new \Automattic\BlocksEngine\PhpTransformer\Support\BlockMarkupIndex($page['canonical_block_markup']);
            foreach (array('header', 'footer') as $area) {
                $sourceRows[$page['source_path']][$area] = $this->nestedLandmarkCandidates($index, '', $area);
                foreach ($sourceRows[$page['source_path']][$area] as $position => $row) $variantIdentities[$area][$position + 1][$row['identity_markup']] = true;
            }
        }
        foreach ($pages as &$page) {
            // Record identities against the original variant rows: primary-shell
            // removal can change offsets and authored positions before absorption.
            $page['_source_shared_variant_identities'] = array();
            foreach (array('header', 'footer') as $area) {
                $variant = 0;
                foreach ($sourceRows[$page['source_path']][$area] as $row) {
                    ++$variant;
                    $class = self::responsiveVariantClass($row);
                    if (null === $class || !empty($row['capture_scope_edge']) || count($variantIdentities[$area][$variant]) < 2) continue;
                    $artifact = self::sourceSharedShellArtifact($artifacts, $page, $area, $variant);
                    if (null === $artifact) continue;
                    $bindings = $this->runtimeBindingsInRanges($runtimeDeclarations, $page, array($row));
                    if ($bindings['blocked'] || array() !== $bindings['refs']) continue;
                    $page['_source_shared_variant_identities'][$area][$class][$row['identity_markup']] = array(
                        'identity_markup' => self::normalizeNestedChromeMarkup($artifact['canonical_block_markup']),
                        // The isolated artifact proves identity, not presentation:
                        // its stylesheet context can differ from the page's and
                        // omit selector-derived attribute classes. Emit the
                        // compiled page landmark under its original device shell.
                        'template_part_markup' => self::landmarkPartMarkup(self::withoutCurrentNavigationState($row['markup']), $area),
                    );
                }
            }
            if (!is_array($page['shell_candidates'] ?? null)) continue;
            $areaVariants = array();
            foreach ($page['shell_candidates'] as &$candidate) {
                $variant = ($areaVariants[$candidate['area']] ?? 0) + 1;
                $areaVariants[$candidate['area']] = $variant;
                // Keep viewport-owned presentation and anchors on the primary
                // candidate; absorption emits the canonical variant separately.
                if (null !== self::responsiveVariantClass($candidate)) {
                    $candidate = self::withVariantSourceIdentity($candidate, $page['_source_shared_variant_identities'][$candidate['area']] ?? array());
                    continue;
                }
                if (!empty($candidate['device_document_bundle'])) continue;
                // Already-equivalent compiled shells keep their existing
                // context-sensitive support assets and presentation intact.
                if (count($identities[$candidate['area']]) < 2) continue;
                // Runtime-bound source blocks must retain the exact compiled
                // payload their bindings address; the hoister owns those shells.
                if (isset($candidate['offset'], $candidate['length'])) {
                    $bindings = $this->runtimeBindingsInRanges($runtimeDeclarations, $page, array($candidate));
                    if ($bindings['blocked'] || array() !== $bindings['refs']) continue;
                }
                $artifact = self::sourceSharedShellArtifact($artifacts, $page, $candidate['area'], $variant);
                if (null === $artifact) continue;
                $markup = (string) $artifact['canonical_block_markup'];
                // Area and position match an artifact to a candidate, not its
                // extent: chrome detected around the landmark (an off-canvas
                // drawer) is absent from a landmark-only artifact.
                if (array() !== array_diff(self::textRuns($candidate['markup']), self::textRuns($markup))) continue;
                $candidate['identity_markup'] = self::normalizeNestedChromeMarkup($markup);
                $candidate['template_part_markup'] = self::landmarkPartMarkup(self::withoutCurrentNavigationState($markup), $candidate['area']);
            }
            unset($candidate);
        }
        unset($page);
        return $pages;
    }

    /** @return array<int,string> Visible text runs of block markup. */
    private static function textRuns(string $markup): array
    {
        $source = preg_replace(array('/<!--.*?-->/s', '#<(script|style|template)\b[^>]*>.*?</\1\s*>#is'), '', $markup) ?? '';
        $runs = array();
        foreach (preg_split('/<[^>]*>/', $source) ?: array() as $run) {
            $run = trim(preg_replace('/\s+/u', ' ', html_entity_decode($run, ENT_QUOTES | ENT_HTML5)) ?? '');
            if (preg_match('/[\p{L}\p{N}]/u', $run)) $runs[$run] = $run;
        }
        return array_values($runs);
    }

    private static function sourceSharedShellArtifact(array $artifacts, array $page, string $area, int $variant): ?array
    {
        $matches = array_values(array_filter($artifacts, static fn(array $artifact): bool =>
            ($artifact['area'] ?? null) === $area
            && ($artifact['placement']['variant'] ?? 1) === $variant
            && !empty($artifact['placement']['equal_source_identity'])
            && in_array($page['source_path'], $artifact['placement']['source_paths'] ?? array(), true)
        ));
        // Membership and authored position must identify exactly one group.
        return 1 === count($matches) ? $matches[0] : null;
    }

    /** Preserve page ranges/markup while comparing proven source identities. */
    private static function withVariantSourceIdentity(array $row, array $identities): array
    {
        $class = self::responsiveVariantClass($row);
        $identity = $identities[$class ?? ''][$row['identity_markup']] ?? null;
        if (is_array($identity)) $row = array_replace($row, $identity);
        return $row;
    }

    public static function documentChromeIdentity(string $markup, ?string $document = null): string
    {
        // Only generated identity is ignored; authored ids, classes and all
        // content remain significant. Device variants are compared separately.
        $markup = preg_replace('/be-inline-geometry-[a-f0-9]{16}(?:-[a-f0-9]{16})?/', '', $markup) ?? $markup;
        $references = array();
        preg_match_all('/\s(?:href|for|aria-[\w-]+)\s*=\s*([\'\"])(.*?)\1/is', $document ?? $markup, $attributes, PREG_SET_ORDER);
        foreach ($attributes as $attribute) {
            foreach (preg_split('/\s+/', html_entity_decode($attribute[2], ENT_QUOTES | ENT_HTML5)) ?: array() as $value) {
                $references[ltrim($value, '#')] = true;
            }
        }
        $markup = preg_replace_callback('/("(?:anchor|id)"\s*:\s*"|\sid\s*=\s*[\'\"])(yui_\d+_[\w-]+)([\'\"])/', static fn(array $match): string => isset($references[$match[2]]) ? $match[0] : $match[1] . $match[3], $markup) ?? $markup;
        $suffix = (string) DocumentVariantIds::suffixForClass(CaptureEvidence::MOBILE_DOCUMENT_CLASS);
        // Block attribute JSON escapes hyphens; rendered HTML keeps them literal.
        $markup = str_replace(str_replace('--', '\\u002d\\u002d', $suffix), $suffix, $markup);
        $markup = preg_replace('/' . preg_quote($suffix, '/') . '\b/', '', $markup) ?? $markup;
        $markup = preg_replace_callback('/("(?:className|class)"\s*:\s*"|\bclass=")([^"]*)"/', static function (array $match): string {
            $classes = array_values(array_filter(preg_split('/\s+/', trim($match[2])) ?: array(), static fn(string $class): bool => !str_starts_with($class, EngineMarker::EDITOR_ANCHOR_PREFIX)));
            // Squarespace toggles this state after measuring the slide layer.
            if (in_array('sqs-slide-layer-content', $classes, true)) $classes = array_values(array_diff($classes, array('scroll')));
            sort($classes, SORT_STRING);
            return $match[1] . implode(' ', $classes) . '"';
        }, $markup) ?? $markup;
        return $markup;
    }

    /**
     * Move repeated siblings with a hoisted footer, not with post content.
     * Evidence comes from the original block-tree siblings in every contributing
     * route and document scope. Varying siblings or non-equivalent runtime
     * entities stay per-page; equivalent bindings move with their chrome.
     *
     * @return array{pages:array,parts:array,runtime_declarations:array,runtime_entity_records:array}
     */
    public function withSharedFooterTrailingChrome(array $originals, array $pages, array $parts, array $declarations, array $records = array(), array $assets = array()): array
    {
        // Composition can supply content-addressed entities. Edit their resolved
        // values here, then rebuild their records without changing storage form.
        $manifestIndexes = array();
        foreach ($declarations as $declarationIndex => &$declaration) {
            if (RuntimeEntityManifest::SCHEMA !== ($declaration['payload']['schema'] ?? null)) continue;
            $manifestIndexes[] = $declarationIndex;
            $payload = $declaration['payload'];
            $declaration['payload'] = array('schema' => $payload['entity_schema'], 'entities' => RuntimeEntityManifest::resolve($payload, $records));
        }
        unset($declaration);
        $this->resolveManifestEntities($declarations, $records);
        foreach ($parts as $partIndex => $part) {
            if ('footer' !== ($part['area'] ?? null) || 'shared_shell' !== ($part['placement']['kind'] ?? null)) continue;
            $sources = array_keys($part['provenance']['sources'] ?? array());
            $evidence = array();
            foreach ($originals as $original) {
                if (!in_array($original['source_path'], $sources, true)) continue;
                $markup = $original['canonical_block_markup'];
                $index = new \Automattic\BlocksEngine\PhpTransformer\Support\BlockMarkupIndex($markup);
                $nodes = self::blockNodes($index);
                foreach ($this->nestedLandmarkCandidates($index, '', 'footer', false) as $row) {
                    $scope = self::responsiveVariantClass($row) ?? '';
                    foreach ($nodes as $id => $node) {
                        if ($node['offset'] !== $row['offset'] || $node['end'] !== $row['offset'] + $row['length']) continue;
                        $siblings = null === $node['parent'] ? self::topLevelNodeIds($nodes) : $nodes[$node['parent']]['children'];
                        $position = array_search($id, $siblings, true);
                        foreach (array_slice($siblings, $position + 1) as $tailPosition => $tailId) {
                            $tail = $nodes[$tailId];
                            $range = array('offset' => $tail['offset'], 'length' => $tail['end'] - $tail['offset']);
                            $bindings = $this->runtimeBindingsInRanges($declarations, $original, array($range));
                            if ($bindings['blocked']) continue;
                            $content = substr($markup, $range['offset'], $range['length']);
                            $identity = self::documentChromeIdentity($content, $markup);
                            $identity = self::engineMarkerChromeIdentity($identity, $original['source_path'], $assets, substr_replace($markup, '<!--engine-chrome-start-->' . $content . '<!--engine-chrome-end-->', $range['offset'], $range['length']));
                            $evidence[$scope][$tailPosition][$identity][$original['source_path']] = array('content' => $content, 'refs' => $bindings['refs']);
                        }
                    }
                }
            }
            $partMarkup = $part['canonical_block_markup'];
            $insertions = array();
            $pendingBindings = array();
            foreach ($this->nestedLandmarkCandidates(new \Automattic\BlocksEngine\PhpTransformer\Support\BlockMarkupIndex($partMarkup), '', 'footer', false) as $row) {
                $scope = self::responsiveVariantClass($row) ?? '';
                $trailing = '';
                foreach ($evidence[$scope] ?? array() as $identities) {
                    if (1 !== count($identities)) continue;
                    $contents = reset($identities);
                    if (count($contents) !== count($sources) || count($sources) < 2) continue;
                    // Validate all removals before moving any occurrence. Ambiguous
                    // copies or a retained footer cannot prove ownership.
                    $removals = array();
                    foreach ($pages as $index => $page) {
                        $content = $contents[$page['source_path']]['content'] ?? null;
                        if (null === $content) continue;
                        $current = $page['canonical_block_markup'];
                        $matches = array();
                        $currentIndex = new \Automattic\BlocksEngine\PhpTransformer\Support\BlockMarkupIndex($current);
                        $currentNodes = self::blockNodes($currentIndex);
                        foreach ($currentNodes as $nodeId => $node) {
                            if ($content !== substr($current, $node['offset'], $node['end'] - $node['offset'])) continue;
                            $classes = '';
                            for ($parent = $node['parent']; null !== $parent; $parent = $currentNodes[$parent]['parent']) {
                                $ancestor = $currentNodes[$parent];
                                $openingEnd = $currentNodes[$ancestor['children'][0]]['offset'];
                                $classes .= substr($current, $ancestor['offset'], $openingEnd - $ancestor['offset']);
                            }
                            if ('' !== $scope && !str_contains($classes, $scope)) continue;
                            $matches[] = $node['offset'];
                        }
                        if (1 !== count($matches)) continue;
                        $retained = false;
                        foreach ($this->nestedLandmarkCandidates($currentIndex, '', 'footer', false) as $remaining) {
                            if ((self::responsiveVariantClass($remaining) ?? '') === $scope) $retained = true;
                        }
                        if (!$retained) $removals[$index] = array('offset' => $matches[0], 'content' => $content);
                    }
                    if (count($removals) !== count($sources)) continue;
                    $bindingRefs = array();
                    $originalIndexes = array();
                    foreach ($originals as $originalIndex => $original) {
                        if (!isset($contents[$original['source_path']])) continue;
                        $originalIndexes[] = $originalIndex;
                        $bindingRefs[$originalIndex] = $contents[$original['source_path']]['refs'];
                    }
                    $firstSource = array_key_first($contents);
                    $first = array('source_path' => $firstSource, 'template_part_markup' => $contents[$firstSource]['content']);
                    if (null === $this->sharedShellBindingHoist($declarations, $originals, $originalIndexes, $bindingRefs, $first, 'footer')) continue;
                    $pendingBindings[] = array($originalIndexes, $bindingRefs, $first);
                    foreach ($removals as $index => $removal) {
                        $pages[$index]['canonical_block_markup'] = substr_replace($pages[$index]['canonical_block_markup'], '', $removal['offset'], strlen($removal['content']));
                        $pages[$index]['content_hash'] = WordPressSitePlan::contentHash($pages[$index]['canonical_block_markup']);
                    }
                    $trailing .= $contents[$firstSource]['content'];
                }
                if ('' !== $trailing) $insertions[$row['offset'] + $row['length']] = $trailing;
            }
            krsort($insertions, SORT_NUMERIC);
            foreach ($insertions as $offset => $content) $partMarkup = substr_replace($partMarkup, $content, $offset, 0);
            $combined = array('keep' => array(), 'drop' => array());
            foreach ($pendingBindings as [$originalIndexes, $bindingRefs, $first]) {
                $first['template_part_markup'] = $partMarkup;
                $hoist = $this->sharedShellBindingHoist($declarations, $originals, $originalIndexes, $bindingRefs, $first, 'footer');
                if (null === $hoist) throw new \LogicException('Trailing chrome binding lost its part anchor.');
                $combined['keep'] = array_merge($combined['keep'], $hoist['keep']);
                $combined['drop'] = array_merge($combined['drop'], $hoist['drop']);
            }
            $declarations = self::applySharedShellBindingHoist($declarations, $combined, $part['source_path']);
            $parts[$partIndex]['canonical_block_markup'] = $partMarkup;
            $parts[$partIndex]['content_hash'] = WordPressSitePlan::contentHash($partMarkup);
        }
        foreach ($manifestIndexes as $declarationIndex) {
            $payload = $declarations[$declarationIndex]['payload'];
            $manifest = RuntimeEntityManifest::fromEntities($payload['schema'], $payload['entities']);
            $declarations[$declarationIndex]['payload'] = RuntimeDeclarations::canonical($manifest['payload']);
            $records = array_values(array_column(array_merge($records, $manifest['records']), null, 'content_hash'));
        }
        return array('pages' => $pages, 'parts' => $parts, 'runtime_declarations' => $declarations, 'runtime_entity_records' => $records);
    }

    /**
     * Compare generated markers by their emitted rules, including conditional
     * context. Missing evidence keeps the class significant. The emitted copy
     * keeps its original class, so support-CSS projection still sees its rules.
     */
    private static function engineMarkerChromeIdentity(string $markup, string $source, array $assets, string $documentMarkup): string
    {
        preg_match_all('/\bblocks-engine-responsive-typography-[a-f0-9]+\b/', $markup, $typography);
        $classes = array_unique(array_merge(EngineMarker::all($markup), $typography[0]));
        $replacements = array();
        // Read the original copy, not its normalized identity, with its document
        // ancestors intact. Boundary comments add no selector-visible wrapper.
        $document = new \DOMDocument();
        $document->loadHTML('<?xml encoding="UTF-8"><html><body>' . $documentMarkup . '</body></html>', LIBXML_NOERROR | LIBXML_NOWARNING);
        $elements = (new \DOMXPath($document))->query('//*[preceding::comment()[.="engine-chrome-start"] and following::comment()[.="engine-chrome-end"]]');
        foreach ($classes as $class) {
            $kind = EngineMarker::withoutDocumentSeeds($class);
            if ($kind === $class) $kind = 'blocks-engine-responsive-typography';
            $rules = array();
            foreach ($assets as $asset) {
                if ('css' !== ($asset['kind'] ?? null) || !StylesheetActivation::active($asset) || !self::stylesheetAppliesToPages($asset, array($source))) continue;
                (new CssStylesheetTransformer())->visitStyleRules((string) ($asset['content'] ?? ''), static function (string $selector, string $body, array $ancestors) use ($class, $kind, $elements, &$rules): void {
                    // A grouped rule can name several documents' copies. Compare
                    // only the arms emitted for this marker, not its neighbours.
                    foreach (CssStylesheetTransformer::splitSelectorList($selector) ?? array() as $arm) {
                        if (!preg_match('/\.' . preg_quote($class, '/') . '(?![\w-])/', $arm)) continue;
                        $parsed = CssSelectorMatcher::parse($arm);
                        $applies = !$parsed['supported'] || null !== $parsed['pseudo_state_suffix_span'];
                        foreach ($elements ?: array() as $element) {
                            if ($applies) break;
                            $match = CssSelectorMatcher::matches($element, $parsed);
                            $applies = !$match['supported'] || $match['matches'];
                        }
                        if ($applies) $rules[] = array($ancestors, str_replace($class, $kind, trim($arm)), trim($body));
                    }
                });
            }
            if (array() !== $rules) $replacements[$class] = $kind . '-' . RuntimeDeclarations::hash($rules);
        }
        return strtr($markup, $replacements);
    }

    /** @var array<string,array<int,array<string,mixed>>> Entities of this call's manifest declarations, by payload hash. */
    private array $manifestEntities = array();

    /**
     * Bind producer-owned parts to equivalent landmarks at their authored
     * positions, including repeated device documents. A conflicting part stays
     * unbound and continues to reserve its slug; runtime anchors stay page-owned.
     *
     * @return array{pages:array,parts:array}
     */
    public function bindExplicitParts(array $pages, array $parts, array $declarations, array $records = array()): array
    {
        $this->resolveManifestEntities($declarations, $records);
        foreach ($parts as &$part) {
            $area = $part['area'] ?? '';
            if ('unbound' !== ($part['placement']['kind'] ?? null) || !in_array($area, array('header', 'footer'), true)) continue;
            $identity = self::normalizeNestedChromeMarkup(self::landmarkPartMarkup($part['canonical_block_markup'], $area));
            $sources = array();
            foreach ($pages as &$page) {
                if (!empty($page['synthetic'])) continue;
                $rows = $this->nestedLandmarkCandidates(new \Automattic\BlocksEngine\PhpTransformer\Support\BlockMarkupIndex($page['canonical_block_markup']), $page['source_path'], $area, false);
                if (array() === $rows) continue;
                foreach ($rows as $row) {
                    if ($identity !== self::normalizeNestedChromeMarkup($row['markup'])) continue 2;
                    $bindings = $this->runtimeBindingsInRanges($declarations, $page, array($row));
                    if ($bindings['blocked'] || array() !== $bindings['refs']) continue 2;
                }
                usort($rows, static fn(array $left, array $right): int => $right['offset'] <=> $left['offset']);
                $markup = $page['canonical_block_markup'];
                $reference = '<!-- wp:template-part {"slug":"' . $part['slug'] . '","area":"' . $area . '","tagName":"div","className":"blocks-engine-shell-transport"} /-->';
                foreach ($rows as $row) $markup = substr_replace($markup, $reference, $row['offset'], $row['length']);
                $page['canonical_block_markup'] = $markup;
                $page['content_hash'] = WordPressSitePlan::contentHash($markup);
                $sources[] = $page['source_path'];
            }
            unset($page);
            if (array() !== $sources) {
                $part['placement'] = array('kind' => 'inline_shared_shell', 'source_path' => $part['source_path'], 'source_paths' => $sources);
                $part['tag_name'] = 'div';
            }
        }
        unset($part);
        return array('pages' => $pages, 'parts' => $parts);
    }

    public function __construct(private readonly WordPressSitePlan $plan)
    {
    }

    /**
     * Resolve manifest declarations once per extraction so the bindings their
     * records hold are read like inline ones.
     *
     * @param array<int,array<string,mixed>> $declarations @param array<int,array<string,mixed>> $records
     */
    private function resolveManifestEntities(array $declarations, array $records): void
    {
        $this->manifestEntities = array();
        foreach ($declarations as $declaration) if (RuntimeEntityManifest::SCHEMA === ($declaration['payload']['schema'] ?? null)) $this->manifestEntities[RuntimeDeclarations::hash($declaration['payload'])] = RuntimeEntityManifest::resolve($declaration['payload'], $records);
    }

    /** @param array<string,mixed> $declaration @return array<int|string,mixed> */
    private function entities(array $declaration): array
    {
        $payload = $declaration['payload'] ?? array();
        if (RuntimeEntityManifest::SCHEMA === ($payload['schema'] ?? null)) return $this->manifestEntities[RuntimeDeclarations::hash($payload)];
        return ArrayField::array($payload, 'entities');
    }

    /** @param array<string,mixed> $document @return array<int,array<string,mixed>> */
    public function shellCandidates(array $document, AssetReferenceCanonicalizer $references, array $routes, string $canonical): array
    {
        $index = new \Automattic\BlocksEngine\PhpTransformer\Support\BlockMarkupIndex($canonical);
        $candidates = array();
        foreach ($document['shell_artifacts'] ?? array() as $candidate) {
            if (!is_array($candidate) || !in_array($candidate['area'] ?? null, array('header', 'footer'), true) || !is_string($candidate['block_markup'] ?? null) || '' === trim($candidate['block_markup'])) continue;
            $sourcePath = WordPressSitePlan::value($document, 'source_path');
            $markup = $this->plan->routeLinks($references->content($candidate['block_markup'], $sourcePath), $sourcePath, $routes);
            // One rendered part serves every route; a page's own current item
            // is restored at render time, never frozen into the shared part.
            $templatePartMarkup = self::landmarkPartMarkup(self::withoutCurrentNavigationState($markup), (string) $candidate['area']);
            // Resolve this declared candidate's block-tree position now, while it is
            // still guaranteed unique for its area. Later removal reuses this
            // position directly instead of re-deriving it by searching for the
            // candidate's bytes, which a coincidental duplicate elsewhere in the
            // page could otherwise make ambiguous.
            $range = $this->topLevelShellRange($index, (string) $candidate['area'], $markup);
            $identity = self::normalizeNestedChromeMarkup($markup);
            $row = array('area' => $candidate['area'], 'markup' => $markup, 'template_part_markup' => $templatePartMarkup, 'identity_markup' => $identity, 'source_path' => $sourcePath, 'source_hash' => ArrayField::string($candidate, 'source_hash'));
            if (is_array($range)) { $row['offset'] = $range['offset']; $row['length'] = $range['length']; }
            if ('header' === $row['area'] && is_array($range)) $row = $this->withLeadingHeaderChrome($index, $row);
            $candidates[] = $row;
        }
        $sourcePath = WordPressSitePlan::value($document, 'source_path');
        $occupiedAreas = array_column($candidates, 'area');
        $nestedLandmarks = $this->nestedLandmarkShellCandidates($index, $sourcePath, $occupiedAreas);
        if (array() === $nestedLandmarks) {
            return $this->withSameDocumentFragments(array_merge($candidates, $this->nestedChromeCandidates($index, $sourcePath, $occupiedAreas)), $sourcePath);
        }
        // A labelled landmark claims only its own area. Unlabeled chrome still
        // supplies the other one, so a page with a nested <footer> and an
        // unlabeled leading masthead shares both.
        $occupiedAreas = array_merge($occupiedAreas, array_column($nestedLandmarks, 'area'));
        return $this->withSameDocumentFragments(array_merge($candidates, $nestedLandmarks, $this->unlabeledChromeCandidates($index, $sourcePath, $occupiedAreas)), $sourcePath);
    }

    /**
     * Route links have already been resolved to plan paths. Only the owning
     * route's fragment links are portable to every page rendering this shell.
     * Keep source markup and ranges intact for structural removal.
     */
    private function withSameDocumentFragments(array $candidates, string $sourcePath): array
    {
        $normalize = function (string $markup) use ($sourcePath): string {
            return preg_replace_callback('/("(?:url|href)"\s*:\s*"|\bhref\s*=\s*[\'"])([^"\']+)([\'"])/i', fn(array $match): string =>
                $match[1] . $this->plan->shellFragmentReference($match[2], $sourcePath) . $match[3], $markup) ?? $markup;
        };
        foreach ($candidates as &$candidate) {
            $markup = $normalize($candidate['markup']);
            if ($markup === $candidate['markup']) continue;
            $candidate['identity_markup'] = self::normalizeNestedChromeMarkup($markup);
            $candidate['template_part_markup'] = $normalize($candidate['template_part_markup']);
        }
        unset($candidate);
        return $candidates;
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
    private function nestedChromeCandidates(\Automattic\BlocksEngine\PhpTransformer\Support\BlockMarkupIndex $index, string $sourcePath, array $occupiedAreas = array()): array
    {
        $markup = $index->markup;
        $legacy = $this->legacyTwoChildChromeCandidate($index, $sourcePath);
        if (array() !== $legacy) {
            return $legacy;
        }
        return $this->unlabeledChromeCandidates($index, $sourcePath, $occupiedAreas);
    }

    /** @return array<int,array<string,mixed>> */
    private function legacyTwoChildChromeCandidate(\Automattic\BlocksEngine\PhpTransformer\Support\BlockMarkupIndex $index, string $sourcePath): array
    {
        $markup = $index->markup;
        $topLevel = $index->topLevel();
        foreach ($topLevel as $position => $wrapperRange) {
            $wrapper = substr($markup, $wrapperRange['offset'], $wrapperRange['length']);
            $preceding = $topLevel[$position - 1] ?? null;
            $toggle = is_array($preceding) ? substr($markup, $preceding['offset'], $preceding['length']) : '';
            // The responsive core/navigation overlay supersedes the only allowed
            // sibling: its legacy authored checkbox toggle.
            if (count($topLevel) !== $position + 1 || (0 < $position && (1 !== $position || !self::isCheckboxBlock($toggle)))) {
                continue;
            }
            $children = $index->children($wrapperRange);
            if (2 !== count($children) || !self::isGroupBlock($wrapper)) {
                continue;
            }
            $navigationChildren = array_values(array_filter(
                $children,
                static fn(array $range): bool => str_contains(substr($markup, $range['offset'], $range['length']), '<!-- wp:navigation ')
            ));
            if (1 !== count($navigationChildren)) {
                continue;
            }
            $chromeRange = $navigationChildren[0];
            $contentRange = current(array_filter($children, static fn(array $range): bool => $range !== $chromeRange));
            if (!is_array($contentRange) || !self::isGroupBlock(substr($markup, $contentRange['offset'], $contentRange['length']))) {
                continue;
            }
            $chrome = substr($markup, $chromeRange['offset'], $chromeRange['length']);
            // The navigation child holds the page itself when it also holds
            // the main landmark; its sibling is then a trailing layer.
            if (self::containsMainLandmark($chrome)) {
                continue;
            }
            $content = substr($markup, $contentRange['offset'], $contentRange['length']);
            $opening = self::blockOpeningMarkup($wrapper);
            if (null === $opening) {
                continue;
            }
            $contentOffset = $contentRange['offset'];
            $identity = self::normalizeNestedChromeMarkup($chrome);
            if ('' === $identity) {
                continue;
            }
            return array(array(
                'area' => 'header',
                'markup' => $chrome,
                'template_part_markup' => self::landmarkPartMarkup(self::withoutCurrentNavigationState($chrome), 'header'),
                'identity_markup' => $identity,
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
    private function unlabeledChromeCandidates(\Automattic\BlocksEngine\PhpTransformer\Support\BlockMarkupIndex $index, string $sourcePath, array $occupiedAreas): array
    {
        $markup = $index->markup;
        $headers = array();
        $footers = array();
        $this->collectNestedChrome($index, $index->topLevel(), $headers, $footers);
        $candidates = array();
        foreach (array('header' => $headers, 'footer' => $footers) as $area => $rows) {
            if (in_array($area, $occupiedAreas, true) || array() === $rows) {
                continue;
            }
            // Repeated chrome must agree within a document scope, not across
            // devices. The ordered scope identities still compare across routes.
            $scoped = array();
            foreach ($rows as $row) $scoped[$row['capture_scope']][] = $row;
            ksort($scoped, SORT_STRING);
            $identities = array();
            foreach ($scoped as $scope => $scopeRows) {
                $unique = array_unique(array_column($scopeRows, 'identity_markup'));
                if (1 !== count($unique)) continue 2;
                $identities[$scope] = reset($unique);
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
            $partMarkup = self::landmarkPartMarkup(self::withoutCurrentNavigationState($row['markup']), $area);
            $identity = $row['identity_markup'];
            if (1 < count(array_unique($identities)) || (1 < count($scoped) && !isset($scoped['']))) {
                if (isset($scoped[''])) continue;
                // Labelled landmarks (including leading chrome) already have
                // a responsive absorption path; do not preempt that ownership.
                if (array() !== $this->nestedLandmarkCandidates($index, $sourcePath, $area)) continue;
                $pieces = array();
                foreach ($scoped as $scopeRows) {
                    $variant = $scopeRows[0];
                    $pieces[] = self::variantVisibilityGroup($variant['capture_scope'], self::landmarkPartMarkup(self::withoutCurrentNavigationState($variant['markup']), $area), $variant['capture_scope_shell'] ?? null);
                }
                $partMarkup = implode("\n", $pieces);
                $identity = json_encode($identities, JSON_THROW_ON_ERROR);
            }
            $candidates[] = array(
                'area' => $area,
                'markup' => $row['markup'],
                'template_part_markup' => $partMarkup,
                'identity_markup' => $identity,
                'source_path' => $sourcePath,
                'source_hash' => $row['source_hash'],
                'nested_shell' => true,
                'device_document_bundle' => count($scoped) > 1 && !isset($scoped['']),
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
    private function collectNestedChrome(\Automattic\BlocksEngine\PhpTransformer\Support\BlockMarkupIndex $index, array $ranges, array &$headers, array &$footers, string $captureScope = '', ?array $captureShell = null): void
    {
        $markup = $index->markup;
        // Empty decoration groups ahead of the header do not move it: the first
        // block that holds anything is the one that may be the header.
        $lead = 0;
        while ($lead < count($ranges) - 1 && self::isEmptyVisualGroup(substr($markup, $ranges[$lead]['offset'], $ranges[$lead]['length']))) ++$lead;
        if (2 <= count($ranges) - $lead) {
            $head = $ranges[$lead];
            $first = substr($markup, $head['offset'], $head['length']);
            // Capture documents are detection roots, not chrome: descend into
            // each device document instead of claiming its route content.
            if (!str_contains($first, CaptureEvidence::DOCUMENT_SCOPE) && self::containsNavigation($first) && !self::containsMainLandmark($first) && !self::chromeSplitIsInside($index, $head)) {
                // A hidden menu or dialog mounted after the block is not page
                // content. When only such layers follow a block that holds the
                // page heading, that block is the route itself, not chrome.
                $restHasContent = false;
                $restInFlow = false;
                foreach (array_slice($ranges, $lead + 1) as $range) {
                    $sibling = substr($markup, $range['offset'], $range['length']);
                    if (self::isEmptyVisualGroup($sibling)) continue;
                    $restHasContent = true;
                    if (!self::isOutOfFlowChrome($sibling)) {
                        $restInFlow = true;
                        break;
                    }
                }
                if (!$restInFlow && 1 === preg_match('/<h1\b/i', $first)) $restHasContent = false;
                if ($restHasContent) {
                    $headers[] = $this->nestedChromeRow($first, $head['offset'], $head['length']) + array('capture_scope' => $captureScope, 'capture_scope_shell' => $captureShell);
                    // A projected modal dialog mounts at the end of its document;
                    // it renders in the top layer, so the footer is the last
                    // sibling before it.
                    $lastIndex = count($ranges) - 1;
                    while ($lastIndex > $lead && self::isCapturedModalDialogBlock(substr($markup, $ranges[$lastIndex]['offset'], $ranges[$lastIndex]['length']))) --$lastIndex;
                    $last = $ranges[$lastIndex];
                    $lastMarkup = substr($markup, $last['offset'], $last['length']);
                    if ($last !== $head && self::isFooterChrome($lastMarkup)) {
                        $footers[] = $this->nestedChromeRow($lastMarkup, $last['offset'], $last['length']) + array('capture_scope' => $captureScope, 'capture_scope_shell' => $captureShell);
                    }
                    return;
                }
            }
        }
        foreach ($ranges as $range) {
            $block = substr($markup, $range['offset'], $range['length']);
            // Modal content is not document chrome, even when its editable
            // owner serializes as a Group. Never mine its children for parts.
            if (self::isCapturedModalDialogBlock($block)) continue;
            // A complete labelled shell owns its descendants. Looking for
            // unlabeled chrome inside it can mistake its link row for another
            // header and its trailing controls for a footer when a preceding
            // document sibling prevents the outer first-child match.
            $openingBlock = strstr($block, '-->', true) ?: '';
            if (1 === preg_match('/"tagName":"(?:header|footer)"|"role":"(?:banner|contentinfo)"/', $openingBlock)
                && !self::containsMainLandmark($block) && !str_contains($block, '<!-- wp:heading')) continue;
            $children = $index->children($range);
            if (array() === $children) continue;
            $opening = substr($markup, $range['offset'], $children[0]['offset'] - $range['offset']);
            $scope = $captureScope;
            $shell = $captureShell;
            if (str_contains($opening, CaptureEvidence::DOCUMENT_SCOPE) && preg_match('/\bclass="([^"]*)"/', $opening, $match)) {
                $classes = array_values(array_unique(preg_split('/\s+/', trim($match[1])) ?: array()));
                sort($classes, SORT_STRING);
                $scope = implode(' ', $classes);
                $shell = null;
                if (preg_match('/^<!--\s*wp:(\S+)\s+(\{.*?\})\s*-->/s', $opening, $comment)) {
                    $attrs = json_decode($comment[2], true);
                    foreach ($classes as $class) {
                        if (self::isResponsiveVariantClass($class) && is_array($attrs)) $shell = self::deviceDocumentShell($comment[1], $attrs, $class);
                    }
                }
            }
            $this->collectNestedChrome($index, $children, $headers, $footers, $scope, $shell);
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
        $index = new \Automattic\BlocksEngine\PhpTransformer\Support\BlockMarkupIndex($markup);
        while (preg_match('/^<!--\s*wp:\S+/', $markup)) {
            $children = 1 === count($index->topLevel()) ? $index->atDepth(1) : array();
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
            $index = new \Automattic\BlocksEngine\PhpTransformer\Support\BlockMarkupIndex($markup);
        }
        return $markup;
    }

    private static function isLayoutTransparentBlock(string $markup): bool
    {
        if (!preg_match('/^<!--\s*wp:\S+\s+(\{.*?\})\s*-->/s', ltrim($markup), $match)) {
            return false;
        }
        $attrs = json_decode($match[1], true);
        if (!is_array($attrs) || isset($attrs['metadata']['blocksEngineDialog'])) {
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
    private static function chromeSplitIsInside(\Automattic\BlocksEngine\PhpTransformer\Support\BlockMarkupIndex $index, array $range): bool
    {
        $markup = $index->markup;
        $children = $index->children($range);
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
            || str_contains($markup, '<!-- wp:post-content')
            || ShellLandmarkPolicy::containsNamedMainLandmark($markup);
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
        if (self::isCapturedModalDialogBlock($markup) || self::containsMainLandmark($markup) || self::isEmptyVisualGroup($markup) || self::containsNavigation($markup) || self::isHeadingBlock($markup)) {
            return false;
        }
        if (str_contains($markup, '<!-- wp:heading') || str_contains($markup, '<!-- wp:post-content')) {
            return false;
        }
        return 1 === preg_match('/^<!--\s*wp:group(?:\s|\{)/', ltrim($markup));
    }

    private static function isCapturedModalDialogBlock(string $markup): bool
    {
        // Only a modal: a dropdown flows in its document like a source panel.
        if (1 === preg_match('#^<!--\s*wp:[a-z0-9-]+/captured-dialog(?:\s+(\{.*?\}))?\s*/?-->#s', ltrim($markup), $match)) {
            return 'dropdown' !== ((json_decode($match[1] ?? '', true) ?: array())['presentation'] ?? null);
        }
        if (1 !== preg_match('#^<!--\s*wp:group\s+(\{.*?\})\s*-->#s', ltrim($markup), $match)) return false;
        $shell = json_decode($match[1], true)['metadata']['blocksEngineDialog'] ?? null;
        return is_array($shell) && 'dropdown' !== ($shell['attributes']['data-blocks-engine-presentation'] ?? null);
    }

    private static function isHeadingBlock(string $markup): bool
    {
        return 1 === preg_match('/^<!--\s*wp:heading(?:\s|\{)/', ltrim($markup));
    }

    /** @param array<int,string> $occupiedAreas @return array<int,array<string,mixed>> */
    private function nestedLandmarkShellCandidates(\Automattic\BlocksEngine\PhpTransformer\Support\BlockMarkupIndex $index, string $sourcePath, array $occupiedAreas): array
    {
        $markup = $index->markup;
        $candidates = array();
        foreach (array('header', 'footer') as $area) {
            if (in_array($area, $occupiedAreas, true)) continue;
            $rows = $this->nestedLandmarkCandidates($index, $sourcePath, $area);
            if (array() === $rows) continue;
            if (!empty($rows[0]['document_level']) && 1 < count($rows)) continue;
            $identities = array_column($rows, 'identity_markup');
            if (1 !== count(array_unique($identities))) continue;
            $row = $rows[0];
            if ('header' === $area && 1 === count($rows)) $row = $this->withLeadingHeaderChrome($index, $row);
            $identity = $row['identity_markup'];
            if ('' === $identity) continue;
            $partMarkup = self::landmarkPartMarkup(self::withoutCurrentNavigationState($row['markup']), $area);
            $additional = array();
            foreach (array_slice($rows, 1) as $extra) $additional[] = array('offset' => $extra['offset'], 'length' => $extra['length'], 'markup' => $extra['markup']);
            // The candidate's own block-tree offset is carried forward so removal
            // never needs to re-derive its position by searching for its bytes.
            $candidates[] = array('document_edge' => $row['document_edge'] ?? false, 'area' => $area, 'markup' => $row['markup'], 'template_part_markup' => $partMarkup, 'identity_markup' => $identity, 'source_path' => $sourcePath, 'source_hash' => $row['source_hash'], 'nested_shell' => true, 'shared_only' => !empty($row['document_level']), 'wrapper_contract_shell' => !empty($row['wrapper_contract_shell']), 'offset' => $row['offset'], 'length' => $row['length'], 'additional_ranges' => $additional, 'ancestor_context' => $row['ancestor_context'] ?? null) + (isset($row['shared_leading']) ? array('shared_leading' => $row['shared_leading']) : array());
        }
        return $candidates;
    }

    /**
     * Compare the leading chrome, not a route heading appended inside its header.
     * Retain the landmark extent so extraction can unwrap the route-owned remainder.
     */
    private function withLeadingHeaderChrome(\Automattic\BlocksEngine\PhpTransformer\Support\BlockMarkupIndex $index, array $row): array
    {
        $children = $index->children($row);
        $count = 0;
        $navigation = false;
        foreach ($children as $child) {
            $block = substr($index->markup, $child['offset'], $child['length']);
            if (!self::isCapturedModalDialogBlock($block) && (self::containsMainLandmark($block) || str_contains($block, '<!-- wp:heading'))) break;
            $navigation = $navigation || self::containsNavigation($block);
            ++$count;
        }
        if (!$navigation || 0 === $count || count($children) === $count) return $row;
        $end = $children[$count - 1]['offset'] + $children[$count - 1]['length'];
        $last = $children[count($children) - 1];
        $closing = $last['offset'] + $last['length'];
        $pruned = substr($index->markup, $row['offset'], $end - $row['offset'])
            . substr($index->markup, $closing, $row['offset'] + $row['length'] - $closing);
        return array_replace($row, array(
            'markup' => $pruned,
            'identity_markup' => self::normalizeNestedChromeMarkup($pruned),
            'source_hash' => hash('sha256', $pruned),
            'template_part_markup' => self::landmarkPartMarkup(self::withoutCurrentNavigationState($pruned), 'header'),
            'nested_shell' => true,
            // Keep the original landmark for restoration and structural removal.
            'shared_leading' => array('markup' => substr($index->markup, $row['offset'], $row['length']), 'end' => $end, 'closing' => $closing),
        ));
    }

    /** @param array<int,array<string,mixed>> $pages @param array<string,true> $reservedSlugs @param array<int,array<string,mixed>> $runtimeDeclarations @param array<int,array<string,mixed>> $assets @return array{pages:array<int,array<string,mixed>>,parts:array<int,array<string,mixed>>,runtime_declarations:array<int,array<string,mixed>>,diagnostics:array<int,array<string,mixed>>} */
    public function inlineSharedShells(array $pages, array $reservedSlugs, array $runtimeDeclarations, array $canonicalArtifacts = array(), array $runtimeEntityRecords = array(), array $assets = array()): array
    {
        $this->resolveManifestEntities($runtimeDeclarations, $runtimeEntityRecords);
        if (count(array_filter($pages, static fn(array $page): bool => empty($page['synthetic']))) < 2) return array('pages' => $pages, 'parts' => array(), 'runtime_declarations' => $runtimeDeclarations, 'diagnostics' => array());
        $stylesheetIds = self::stylesheetReferencedShellIds($assets, self::pageSourcePaths($pages));
        $parts = array(); $diagnostics = array();
        foreach (array('header', 'footer') as $area) {
            $applicable = array_filter($pages, static fn(array $page): bool => empty($page['synthetic']));
            $sourcePaths = array_values(array_map(static fn(array $page): string => $page['source_path'], $applicable));
            $areaArtifacts = array_values(array_filter($canonicalArtifacts, static fn(array $artifact): bool => $area === ($artifact['area'] ?? null)));
            usort($areaArtifacts, static fn(array $left, array $right): int => ($left['variant'] ?? 0) <=> ($right['variant'] ?? 0));
            $candidates = array(); $variantCount = null; $rejected = false; $variantBindings = array();
            foreach ($applicable as $index => $page) {
                $rows = $this->nestedLandmarkCandidates(new \Automattic\BlocksEngine\PhpTransformer\Support\BlockMarkupIndex($page['canonical_block_markup']), $page['source_path'], $area);
                if (!empty($rows[0]['document_level']) && 1 < count($rows)) { $rejected = true; break; }
                foreach ($rows as $row) if (null !== self::responsiveVariantClass($row)) continue 3;
                if (array() === $rows) { $rejected = true; break; }
                $count = self::logicalNestedVariantCount($rows);
                if (null !== $variantCount && $variantCount !== $count) { $rejected = true; break; }
                $variantCount = $count; $candidates[$index] = $rows;
                foreach ($rows as $candidate) {
                    $found = $this->runtimeBindingsInRanges($runtimeDeclarations, $page, array(array('offset' => $candidate['offset'], 'length' => $candidate['length'])));
                    if ($found['blocked']) { $rejected = true; break 2; }
                    if (array() !== $found['refs']) $variantBindings[$candidate['variant']][$index] = $found['refs'];
                }
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
                $identities = self::canonicalizeRouteNumberedShellIds($identities, $stylesheetIds);
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
            // A runtime binding inside a variant moves into that variant's part
            // when every page binds the same entities there, as for a shared shell.
            $partMarkups = array(); $hoists = array();
            foreach ($slugs as $variant => $slug) {
                $first = $candidates[array_key_first($candidates)][$variant];
                $partMarkups[$variant] = self::landmarkPartMarkup(self::withoutCurrentNavigationState($canonical ? (string) ($areaArtifacts[$variant]['canonical_block_markup'] ?? '') : $first['markup']), $area);
                if (!isset($variantBindings[$variant])) continue;
                $hoists[$slug] = $this->sharedShellBindingHoist($runtimeDeclarations, $pages, array_keys($candidates), $variantBindings[$variant], array('source_path' => $first['source_path'], 'template_part_markup' => $partMarkups[$variant]), $area);
                if (null === $hoists[$slug]) { $rejected = true; break; }
            }
            if ($rejected) continue;
            foreach ($candidates as $index => $rows) {
                usort($rows, static fn(array $left, array $right): int => $right['offset'] <=> $left['offset']);
                $markup = $pages[$index]['canonical_block_markup'];
                foreach ($rows as $candidate) {
                    $variant = $candidate['variant']; $slug = $slugs[$variant];
                    $reference = '<!-- wp:template-part {"slug":"' . $slug . '","area":"' . $area . '","tagName":"div","className":"blocks-engine-shell-transport"} /-->';
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
                $partMarkup = $partMarkups[$variant];
                $parts[] = array('source_path' => $sourcePath . '#' . $area, 'slug' => $slug, 'title' => $title, 'post_type' => 'wp_template_part', 'parent_source_path' => '', 'entrypoint' => false, 'area' => $area, 'tag_name' => 'div', 'placement' => array('kind' => 'inline_shared_shell', 'source_path' => $sourcePath, 'source_paths' => $sourcePaths, 'variant' => $variant + 1), 'canonical_block_markup' => $partMarkup, 'metadata' => array(), 'document_metadata' => array('source_context' => array('source_path' => $sourcePath . '#' . $area, 'kind' => 'template_part'), 'title' => $title, 'title_declaration' => array('order' => 0, 'placement' => 'head'), 'meta' => array(), 'links' => array(), 'scripts' => array()), 'provenance' => $this->shellProvenance($area, 'extracted', 'inline_responsive_variant', $candidateRows, hash('sha256', $variants[$variant])), 'reconciliation_identity' => WordPressSitePlan::identity('template-part', $sourcePath . '#' . $area, 'parts/' . $slug . '.html'), 'content_hash' => WordPressSitePlan::contentHash($partMarkup));
            }
            if (array() !== $hoists) {
                $drops = array();
                foreach ($hoists as $slug => $hoist) {
                    $runtimeDeclarations = self::applySharedShellBindingHoist($runtimeDeclarations, array('keep' => $hoist['keep'], 'drop' => array()), 'wordpress-site-plan/shared/' . $slug . '#' . $area);
                    array_push($drops, ...$hoist['drop']);
                }
                $runtimeDeclarations = self::applySharedShellBindingHoist($runtimeDeclarations, array('keep' => array(), 'drop' => $drops), '');
                foreach ($runtimeDeclarations as &$declaration) unset($declaration['reconciliation_identity'], $declaration['payload_hash'], $declaration['content_hash']); unset($declaration);
                $runtimeDeclarations = RuntimeDeclarations::normalizeForComposition($runtimeDeclarations);
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
        $index = new \Automattic\BlocksEngine\PhpTransformer\Support\BlockMarkupIndex($markup);
        $ranges = array();
        foreach ($declarations as $declaration) foreach ($this->entities($declaration) as $entity) foreach (is_array($entity) ? ($entity['bindings'] ?? array()) : array() as $binding) {
            if (($binding['source_path'] ?? null) !== ($document['source_path'] ?? null)) continue;
            $position = $binding['position'] ?? null;
            $search = $binding['search_block_markup'] ?? null;
            if (!is_string($search) || '' === $search) continue;
            if (WordPressSitePlan::bindingPosition($position, $index, $search)) { $ranges[] = array('offset' => $position['offset'], 'length' => $position['length']); continue; }
            // A manifest record keeps the position it was compiled with, which
            // page canonicalization can shift; protect every block it may name.
            for ($offset = strpos($markup, $search); false !== $offset; $offset = strpos($markup, $search, $offset + strlen($search))) $ranges[] = array('offset' => $offset, 'length' => strlen($search));
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
    public function factorSharedFooterContent(array $pages, array $parts, array $runtimeDeclarations = array(), array $runtimeEntityRecords = array()): array
    {
        $this->resolveManifestEntities($runtimeDeclarations, $runtimeEntityRecords);
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
                    elseif ('page' !== ($page['post_type'] ?? null)) $selected = in_array('single', $part['placement']['template_slugs'] ?? array(), true) && !isset($excluded[WordPressSitePlan::routeTemplateKey($page)]);
                    else $selected = in_array('page', $part['placement']['template_slugs'] ?? array(), true) && !isset($excluded[WordPressSitePlan::routeTemplateKey($page)]);
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
            foreach ((new \Automattic\BlocksEngine\PhpTransformer\Support\BlockMarkupIndex($regionMarkup))->ranges() as $range) {
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
                // One shared part can represent many routes, but its content
                // is already stored once. Only distinct physical owners can
                // justify another shared-content extraction.
                $clusters[$key]['owners'][$document['kind'] . ':' . $document['index']] = true;
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
        $clusters = array_filter($clusters, static fn(array $cluster): bool => empty($cluster['incomplete_responsive_regions']) && count($cluster['owners'] ?? array()) >= 2);
        uasort($clusters, static fn(array $left, array $right): int => count($right['source_paths'] ?? array()) <=> count($left['source_paths'] ?? array()) ?: ($right['text_length'] ?? 0) <=> ($left['text_length'] ?? 0));
        $cluster = reset($clusters);
        if (!is_array($cluster) || count($cluster['source_paths'] ?? array()) < 2) return array('pages' => $pages, 'parts' => $parts, 'diagnostics' => array());

        $baseSlug = 'footer-content';
        $slug = $baseSlug;
        $existingSlugs = array_fill_keys(array_column($parts, 'slug'), true);
        for ($suffix = 2; isset($existingSlugs[$slug]); ++$suffix) $slug = $baseSlug . '-' . $suffix;
        $excludedTemplateSlugs = array();
        foreach ($parts as $existingPart) foreach ($existingPart['placement']['excluded_template_slugs'] ?? array() as $templateSlug) if (is_string($templateSlug)) $excludedTemplateSlugs[$templateSlug] = true;
        $reference = '<!-- wp:template-part {"slug":"' . $slug . '","area":"footer","tagName":"div","className":"blocks-engine-shell-transport"} /-->';
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
        // The identity is a comparison form without RichText markers and
        // engine classes. Emit a real occurrence, as the other extractors do.
        $firstOccurrences = reset($cluster['documents']);
        $partMarkup = (string) ($firstOccurrences[0]['markup'] ?? $cluster['identity']);
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
        return array_values(array_map(static fn(array $candidate): array => array('offset' => $candidate['offset'], 'length' => $candidate['length']), $this->nestedLandmarkCandidates(new \Automattic\BlocksEngine\PhpTransformer\Support\BlockMarkupIndex($markup), $sourcePath, 'footer')));
    }

    /** @return array<int,array<string,mixed>> */
    private function nestedLandmarkCandidates(\Automattic\BlocksEngine\PhpTransformer\Support\BlockMarkupIndex $index, string $sourcePath, string $area, bool $includeCaptureEdges = true): array
    {
        // Keep the existing shell-main rule, with an exception for a unique
        // document-edge landmark inside main/section. Article/aside landmarks
        // remain content even when they happen to sit at a document edge.
        $markup = $index->markup;
        $tokens = $index->tokens();
        $landmarks = array();
        foreach ($tokens as $token) {
            if ($token['closing']) continue;
            $attrs = json_decode($token['attributes'], true);
            if (is_array($attrs) && $area === ShellLandmarkPolicy::landmarkKind((string) ($attrs['tagName'] ?? ''), (string) ($attrs['role'] ?? ''))) $landmarks[] = $token['offset'];
        }
        $uniqueLandmark = 1 === count($landmarks) ? $landmarks[0] : null;
        $rows = array(); $stack = array(); $shellMains = null; $documentEnds = array();
        foreach ($index->topLevel() as $range) $documentEnds[$range['offset']] = $range['offset'] + $range['length'];
        foreach ($tokens as $token) {
            $offset = $token['offset']; $closing = $token['closing']; $selfClosing = $token['self_closing'];
            if ($closing) {
                $open = array_pop($stack);
                if (!is_array($open) || empty($open['candidate'])) continue;
                $length = $offset + strlen($token['token']) - $open['offset']; $candidateMarkup = substr($markup, $open['offset'], $length);
                // A section/div named footer is the route's footer only when no
                // block follows it in its top-level document block.
                if (!empty($open['named_footer']) && 1 === preg_match('/<!--\s*wp:/', substr($markup, $offset + strlen($token['token']), $open['document_end'] - $offset - strlen($token['token'])))) continue;
                $rows[] = array('document_edge' => $open['document_edge'], 'main_ancestors' => $open['main_ancestors'], 'area' => $area, 'markup' => $candidateMarkup, 'identity_markup' => self::normalizeNestedChromeMarkup($candidateMarkup), 'source_path' => $sourcePath, 'source_hash' => hash('sha256', $candidateMarkup), 'offset' => $open['offset'], 'length' => $length, 'document_level' => empty($stack), 'wrapper_contract_shell' => !empty($open['wrapper_contract_shell']), 'ancestor_context' => self::ancestorContext($stack) + array('preceded' => !empty($open['preceded'])) + (array() !== ($open['backdrop_ids'] ?? array()) ? array('backdrop_ids' => $open['backdrop_ids']) : array()) + array('static_start_ids' => $open['static_start_ids'] ?? array()));
                continue;
            }
            $name = $token['name']; $attributes = $token['attributes']; $attrs = '' === $attributes ? array() : json_decode($attributes, true);
            $edgeLandmark = $offset === $uniqueLandmark;
            if ($edgeLandmark) {
                foreach ($stack as $ancestor) {
                    if (in_array($ancestor['tag_name'] ?? '', array('article', 'aside', 'nav'), true)) $edgeLandmark = false;
                }
                if ('header' === $area) {
                    $edgeLandmark = $edgeLandmark && count($stack) === count(array_filter($tokens, static fn(array $t): bool => $t['offset'] < $offset));
                } else {
                    // After the landmark closes, only enclosing block closings
                    // may remain: it ends the document, not merely a section.
                    $depth = 0; $ended = false;
                    foreach ($tokens as $following) {
                        if ($following['offset'] < $offset) continue;
                        if ($ended && !$following['closing']) { $edgeLandmark = false; break; }
                        if (!$following['self_closing']) $depth += $following['closing'] ? -1 : 1;
                        if (0 === $depth) $ended = true;
                    }
                }
            }
            $disallowedAncestor = false; $mainAncestors = array();
            foreach ($stack as $ancestor) {
                if ('main' === ($ancestor['tag_name'] ?? null)) $mainAncestors[] = $ancestor['offset'];
                elseif (ShellLandmarkPolicy::isContentRegionTag((string) ($ancestor['tag_name'] ?? ''))) { $disallowedAncestor = true; break; }
            }
            $tagName = is_array($attrs) ? ($attrs['tagName'] ?? null) : null;
            $role = is_array($attrs) && is_string($attrs['role'] ?? null) ? $attrs['role'] : '';
            $className = is_array($attrs) && is_string($attrs['className'] ?? null) ? $attrs['className'] : '';
            $anchor = is_array($attrs) && is_string($attrs['anchor'] ?? null) ? $attrs['anchor'] : '';
            $variantScopeClasses = array();
            $scopeClassNames = array($className);
            $variantScopeShells = array();
            foreach ((array) ($attrs['wrappers'] ?? array()) as $wrapper) {
                if (!is_array($wrapper)) continue;
                $wa = ArrayField::array($wrapper, 'attributes');
                $scopeClassNames[] = (string) ($wa['class'] ?? '');
                // A nested device-only subtree and its device-hidden sibling are
                // the two device variants of one landmark. Their wrapper is the
                // gate the capture's visibility CSS selects, so it is the shell.
                $scope = isset($wa[CaptureEvidence::DEVICE_ONLY]) ? 'site-document-variant-' . $wa[CaptureEvidence::DEVICE_ONLY] : (isset($wa[CaptureEvidence::DEVICE_HIDE]) ? CaptureEvidence::DESKTOP_DOCUMENT_CLASS : null);
                if (null === $scope) continue;
                $scopeClassNames[] = $scope;
                if (str_ends_with($name, '/layout-shell')) $variantScopeShells[$scope] = array('gate' => true, 'block' => $name, 'wrapper' => array('tagName' => is_string($wrapper['tagName'] ?? null) ? $wrapper['tagName'] : 'div', 'attributes' => array_intersect_key($wa, array_flip(array('class', CaptureEvidence::DEVICE_ONLY, CaptureEvidence::DEVICE_HIDE)))));
            }
            foreach ($scopeClassNames as $scopeClassName) {
                $scopeClasses = array_values(array_filter(preg_split('/\s+/', trim($scopeClassName)) ?: array()));
                foreach ($scopeClasses as $scopeClass) {
                    if (self::isResponsiveVariantClass($scopeClass)) $variantScopeClasses[$scopeClass] = $scopeClasses;
                }
            }
            foreach (array_keys($variantScopeClasses) as $scopeClass) {
                if (isset($variantScopeShells[$scopeClass])) continue;
                $shell = self::deviceDocumentShell($name, is_array($attrs) ? $attrs : array(), $scopeClass);
                if (null !== $shell) $variantScopeShells[$scopeClass] = $shell;
            }
            $hasShellWrapperContract = false;
            if (is_array($attrs) && is_array($attrs['wrappers'] ?? null)) {
                $hasShellWrapperContract = true;
                foreach ($attrs['wrappers'] as $wrapper) {
                    if (!is_array($wrapper)) continue;
                    $wrapperAttributes = ArrayField::array($wrapper, 'attributes');
                    if ('' === $role && is_string($wrapperAttributes['role'] ?? null)) $role = $wrapperAttributes['role'];
                    if ('' === $anchor && is_string($wrapperAttributes['id'] ?? null)) $anchor = $wrapperAttributes['id'];
                    $wrapperClass = trim((string) ($wrapperAttributes['class'] ?? ''));
                    if ('' !== $wrapperClass) $className = trim($className . ' ' . $wrapperClass);
                    if (null === $tagName && is_string($wrapper['tagName'] ?? null) && in_array($wrapper['tagName'], array('header', 'footer', 'main', 'article', 'section', 'aside'), true)) $tagName = $wrapper['tagName'];
                }
            }
            // Semantic shell landmarks can be direct document children when the
            // source has no layout wrapper. The footer still requires an
            // enclosing wrapper, and article-owned landmarks stay excluded.
            // Without the wrapper contract, a section/div named footer by class
            // or id is a footer candidate too, once it proves page-final.
            $footerName = self::footerAreaFromName($className, $anchor);
            $landmark = ShellLandmarkPolicy::landmarkKind((string) $tagName, $role);
            $namedFooter = '' === $landmark && !$hasShellWrapperContract && 'footer' === $footerName && in_array($tagName ?? 'div', array('div', 'section'), true);
            $footerClass = $hasShellWrapperContract ? $footerName : null;
            $tagName = $landmark ?: ($footerClass ?? $tagName);
            $kind = $namedFooter ? 'footer' : $tagName;
            if (null === $shellMains && !$disallowedAncestor && 'header' === $landmark && self::isShellLandmarkBlock($name)) $shellMains = $mainAncestors;
            $candidate = (!$includeCaptureEdges || 'header' === $area || 0 < count($stack)) && (!$disallowedAncestor || $edgeLandmark) && $area === $kind && (self::isShellLandmarkBlock($name) || ('footer' === $area && $hasShellWrapperContract && 'footer' === $footerClass));
            // Whether page content precedes the landmark inside its ancestors: a
            // block other than the enclosing openings started or ended before it.
            $preceded = false;
            $backdropIds = array();
            if ($candidate && 0 < count($stack)) {
                $between = substr($markup, $stack[0]['offset'], $offset - $stack[0]['offset']);
                $preceded = preg_match_all('/<!--\s*wp:/', $between) > count($stack) || 0 < preg_match_all('/<!--\s*\/wp:/', $between);
                if ($preceded) $backdropIds = self::backdropAncestorIds($markup, $stack, $offset);
            }
            if (!$selfClosing) $stack[] = array('document_edge' => $edgeLandmark && (array() !== $mainAncestors || $disallowedAncestor), 'offset' => $offset, 'inner_offset' => $offset + strlen($token['token']), 'tag_name' => $tagName, 'candidate' => $candidate, 'named_footer' => $candidate && $namedFooter, 'document_end' => $stack[0]['document_end'] ?? $documentEnds[$offset] ?? strlen($markup), 'main_ancestors' => $edgeLandmark ? array() : $mainAncestors, 'wrapper_contract_shell' => $candidate && $hasShellWrapperContract && 'footer' === $area && 'footer' === $footerClass, 'preceded' => $preceded, 'backdrop_ids' => $backdropIds, 'static_start_ids' => $candidate ? self::precedingStaticStartIds($index, $stack, $offset) : array(), 'anchor' => $anchor, 'class_name' => $className, 'variant_scope_classes' => $variantScopeClasses, 'variant_scope_shells' => $variantScopeShells);
        }
        $rows = array_values(array_filter($rows, static fn(array $row): bool => array() === array_diff($row['main_ancestors'], $shellMains ?? array())));
        usort($rows, static fn(array $left, array $right): int => $left['offset'] <=> $right['offset']);
        // Block-tree ranges are either nested or disjoint. A same-area landmark
        // inside another belongs to that outer shell, not to a responsive variant.
        // Filter here so every consumer (including additional_ranges) receives
        // disjoint ranges before identities and variant counts are considered.
        $disjoint = array(); $outerEnd = -1;
        foreach ($rows as $row) {
            $end = $row['offset'] + $row['length'];
            if ($end <= $outerEnd) continue;
            $disjoint[] = $row;
            $outerEnd = $end;
        }
        $rows = $includeCaptureEdges ? $this->captureScopeEdges($index, $disjoint, $area) : $disjoint;
        foreach ($rows as $variant => &$row) { unset($row['main_ancestors']); $row['variant'] = $variant; } unset($row);
        return $rows;
    }

    /**
     * Give each capture document its own route edge. The responsive shell
     * machinery then compares and removes the complete edge, not just its
     * landmark. Unscoped documents keep the ordinary route_edge pass.
     *
     * @param list<array<string,mixed>> $rows
     * @return list<array<string,mixed>>
     */
    private function captureScopeEdges(\Automattic\BlocksEngine\PhpTransformer\Support\BlockMarkupIndex $index, array $rows, string $area): array
    {
        $markup = $index->markup;
        if (!str_contains($markup, CaptureEvidence::DOCUMENT_SCOPE)) return $rows;
        $stack = array(); $scopes = array();
        foreach ($index->tokens() as $token) {
            if ($token['closing']) {
                $open = array_pop($stack);
                if (!is_array($open) || !$open['scope']) continue;
                $scopes[] = array('offset' => $open['offset'], 'length' => $token['offset'] + strlen($token['token']) - $open['offset']);
            } elseif (!$token['self_closing']) {
                $openingEnd = strpos($markup, '>', $token['offset'] + strlen($token['token']));
                $opening = false === $openingEnd ? '' : substr($markup, $token['offset'], $openingEnd + 1 - $token['offset']);
                $stack[] = array('offset' => $token['offset'], 'scope' => str_contains($opening, CaptureEvidence::DOCUMENT_SCOPE));
            }
        }
        foreach ($scopes as $scope) {
            $children = $index->children($scope);
            if (array() === $children) continue;
            $base = $children[0]['offset'];
            $last = $children[count($children) - 1];
            $document = substr($markup, $base, $last['offset'] + $last['length'] - $base);
            $edge = $this->routeEdge(new \Automattic\BlocksEngine\PhpTransformer\Support\BlockMarkupIndex($document), array(), $area, array());
            foreach ($rows as &$row) {
                if ($row['offset'] < $scope['offset'] || $row['offset'] + $row['length'] > $scope['offset'] + $scope['length']) continue;
                $at = $this->edgeLandmarkIndex($document, $edge, $row['markup']);
                if (null === $at) continue;
                $length = $at + 1;
                while ($length < count($edge) && self::isOutOfFlowChrome(substr($document, $edge[$length]['offset'], $edge[$length]['length']))) ++$length;
                $range = self::edgeRange($edge, $length);
                $row['offset'] = $base + $range['offset'];
                $row['length'] = $range['length'];
                $row['markup'] = substr($markup, $row['offset'], $row['length']);
                $row['identity_markup'] = self::normalizeNestedChromeMarkup($row['markup']);
                $row['source_hash'] = hash('sha256', $row['markup']);
                $row['template_part_markup'] = self::landmarkPartMarkup($row['markup'], $area);
                $row['capture_scope_edge'] = $length > 1;
            }
            unset($row);
        }
        return $rows;
    }

    /** Compare capture navigation with its unscoped, non-responsive fallback. */
    private static function captureEdgeIdentity(string $markup): string
    {
        $markup = preg_replace_callback('/<!--\s*wp:navigation\s+(\{.*?\})\s*(\/)?-->/s', static function (array $match): string {
            $attrs = json_decode($match[1], true);
            if (!is_array($attrs)) return $match[0];
            unset($attrs['overlayMenu'], $attrs['customOverlayBackgroundColor']);
            if (isset($attrs['className'])) {
                $attrs['className'] = trim(preg_replace('/\bblocks-engine-(?:native-responsive-navigation|navigation-overlay-[a-f0-9]+)\b/', '', $attrs['className']) ?? $attrs['className']);
                if ('' === $attrs['className']) unset($attrs['className']);
            }
            return '<!-- wp:navigation ' . json_encode($attrs, JSON_UNESCAPED_SLASHES) . ' ' . ($match[2] ?? '') . '-->';
        }, $markup) ?? $markup;
        return self::normalizeNestedChromeMarkup($markup);
    }

    /**
     * The ids and classes of the blocks enclosing a nested landmark. Hoisted into
     * a template part, the landmark leaves them behind, so author rules that
     * reached it through them need re-anchoring (see WordPressSitePlan).
     *
     * @param array<int,array<string,mixed>> $stack
     * @return array{ids:array<int,string>,classes:array<int,string>,variant_classes:array<string,list<string>>,variant_shells:array<string,array{block:string,wrapper:array{tagName:string,attributes:array<string,mixed>}}>}
     */
    private static function ancestorContext(array $stack): array
    {
        $ids = array(); $classes = array(); $variantClasses = array(); $variantShells = array(); $documentShells = array();
        foreach ($stack as $ancestor) {
            if (empty($ancestor['candidate']) && '' !== ($ancestor['anchor'] ?? '')) $ids[] = (string) $ancestor['anchor'];
            if (!empty($ancestor['candidate'])) continue;
            $ancestorClasses = array_values(array_filter(preg_split('/\s+/', trim((string) ($ancestor['class_name'] ?? ''))) ?: array()));
            foreach ($ancestorClasses as $class) {
                $classes[] = $class;
            }
            // Reuse the specific serialized document wrapper, not classes
            // merged from other coalesced layout wrappers around post content.
            foreach ($ancestor['variant_scope_classes'] ?? array() as $scope => $scopeClasses) {
                $variantClasses[$scope] = $scopeClasses;
            }
            foreach ($ancestor['variant_scope_shells'] ?? array() as $scope => $shell) {
                // A device gate nested in a device document keeps that document
                // around it: the document's rules select through its marker.
                if (!empty($shell['gate'])) $shell['outer'] = array_values($documentShells);
                else $documentShells[$scope] = $shell;
                $variantShells[$scope] = $shell;
            }
        }
        return array('ids' => array_values(array_unique($ids)), 'classes' => array_values(array_unique($classes)), 'variant_classes' => $variantClasses, 'variant_shells' => $variantShells);
    }

    /**
     * The ids of the ancestors that enclose page content preceding a nested
     * landmark: the outer ancestors down to the deepest one in which a block
     * opens before the next ancestor (or the landmark) does. In the source,
     * those ancestors sized what preceded the chrome (a page background
     * layer, say) to a box that also held the chrome. Ancestors below that
     * point hold only the chrome and the content after it.
     *
     * @param array<int,array<string,mixed>> $stack
     * @return list<string>
     */
    private static function backdropAncestorIds(string $markup, array $stack, int $offset): array
    {
        $deepest = -1;
        foreach ($stack as $level => $ancestor) {
            $start = (int) ($ancestor['inner_offset'] ?? $ancestor['offset']);
            $end = isset($stack[$level + 1]) ? (int) $stack[$level + 1]['offset'] : $offset;
            if (1 === preg_match('/<!--\s*\/?wp:/', substr($markup, $start, max(0, $end - $start)))) $deepest = $level;
        }
        $ids = array();
        for ($level = 0; $level <= $deepest; ++$level) {
            if (!empty($stack[$level]['candidate'])) continue;
            $id = (string) ($stack[$level]['anchor'] ?? '');
            if ('' === $id) {
                foreach (preg_split('/\s+/', trim((string) ($stack[$level]['class_name'] ?? ''))) ?: array() as $class) {
                    $id = (string) EngineMarker::editorAnchorId($class);
                    if ('' !== $id) break;
                }
            }
            if ('' !== $id) $ids[] = $id;
        }
        return array_values(array_unique($ids));
    }

    /**
     * Preceding siblings at every ancestor level have a static block start
     * independent of that chrome. Keep their hooks before removing it.
     *
     * @param array<int,array<string,mixed>> $stack
     * @return list<string>
     */
    private static function precedingStaticStartIds(\Automattic\BlocksEngine\PhpTransformer\Support\BlockMarkupIndex $index, array $stack, int $offset): array
    {
        $ids = array();
        foreach ($stack as $level => $ancestor) {
            $start = (int) ($ancestor['inner_offset'] ?? $ancestor['offset']);
            $end = (int) ($stack[$level + 1]['offset'] ?? $offset);
            $tokens = array_filter($index->tokens(), static fn(array $token): bool => $token['offset'] >= $start && $token['offset'] < $end);
            $depth = 0;
            foreach ($tokens as $token) {
                if ($token['closing']) { --$depth; continue; }
                $sibling = 0 === $depth;
                if (!$token['self_closing']) ++$depth;
                if (!$sibling) continue;
                $attrs = json_decode($token['attributes'], true);
                $id = (string) ($attrs['anchor'] ?? '');
                if ('' === $id) foreach (preg_split('/\s+/', trim((string) ($attrs['className'] ?? ''))) ?: array() as $class) {
                    $id = (string) EngineMarker::editorAnchorId($class);
                    if ('' !== $id) break;
                }
                if ('' === $id) {
                    $classes = preg_split('/\s+/', trim((string) ($attrs['className'] ?? ''))) ?: array();
                    if (!empty($classes[0])) $id = '.' . $classes[0];
                }
                if ('' !== $id) $ids[] = $id;
            }
        }
        return array_values(array_unique($ids));
    }

    /** @param array<int,array<string,mixed>> $rows */
    private static function logicalNestedVariantCount(array $rows): int
    {
        $identities = array_column($rows, 'identity_markup');
        if (array() !== $identities && 1 === count(array_unique($identities))) return 1;
        return count($rows);
    }

    /** @param array<int,array<string,mixed>> $pages @param array<string,true> $reservedSlugs @param array<int,array<string,mixed>> $runtimeDeclarations @return array{pages:array<int,array<string,mixed>>,parts:array<int,array<string,mixed>>,runtime_declarations:array<int,array<string,mixed>>,diagnostics:array<int,array<string,mixed>>} */
    public function sharedShells(array $pages, array $reservedSlugs = array(), array $runtimeDeclarations = array(), array $runtimeEntityRecords = array(), bool $partitionTypes = true, bool $partitionHeaders = true, array $assets = array()): array
    {
        $this->resolveManifestEntities($runtimeDeclarations, $runtimeEntityRecords);
        $pages = self::withWordmarkRouteState(self::withRouteCurrentLinksAtRest($pages), $assets);
        // Route-numbered id canonicalization must not merge a shell whose id an
        // applying stylesheet names, so the shared-chrome scoping inventory of
        // stylesheet selectors is computed for the candidate pages up front.
        $stylesheetIds = self::stylesheetReferencedShellIds($assets, self::pageSourcePaths(array_filter($pages, static fn(array $page): bool => empty($page['synthetic']))));
        $parts = array(); $diagnostics = array(); $unremovable = array();
        $areas = array('footer', 'header');
        while (null !== ($area = array_shift($areas))) {
            // A multilingual capture has independent shell populations. A
            // dominant language must not leave every other language's header
            // inline, nor supply its menu as their template fallback.
            $languages = array();
            foreach ($pages as $index => $page) {
                if (!empty($page['synthetic'])) continue;
                $language = strtolower((string) ($page['document_metadata']['root_attributes']['lang'] ?? ''));
                $languages[$language][] = $index;
            }
            if ($partitionHeaders && 'header' === $area && count($languages) > 1 && !isset($languages['']) && !isset($reservedSlugs[$area])) {
                // Document language is not shell identity: translated posts
                // can still carry the original header. Join them to the pages
                // with that header, not to a different locale's fallback.
                $populations = array();
                $entryIdentity = null;
                foreach ($pages as $index => $page) {
                    if (!empty($page['synthetic'])) continue;
                    $headers = array_values(array_filter($page['shell_candidates'] ?? array(), static fn(array $candidate): bool => 'header' === $candidate['area']));
                    if (1 !== count($headers)) continue;
                    $identity = hash('sha256', $headers[0]['identity_markup'] ?? $headers[0]['markup']);
                    $populations[$identity][] = $index;
                    if (!empty($page['entrypoint'])) $entryIdentity = $identity;
                }
                foreach ($populations as $identity => $indexes) {
                    $language = strtolower((string) ($pages[$indexes[0]]['document_metadata']['root_attributes']['lang'] ?? ''));
                    $subset = array();
                    foreach ($indexes as $index) {
                        $subset[$index] = $pages[$index];
                        $subset[$index]['shell_candidates'] = array_values(array_filter($pages[$index]['shell_candidates'] ?? array(), static fn(array $candidate): bool => 'header' === $candidate['area']));
                    }
                    $populationReserved = $reservedSlugs;
                    unset($populationReserved['header']);
                    $resolved = $this->sharedShells($subset, $populationReserved, $runtimeDeclarations, $runtimeEntityRecords, $partitionTypes, false, $assets);
                    $runtimeDeclarations = $resolved['runtime_declarations'];
                    $adoptedSources = array();
                    foreach ($resolved['pages'] as $index => $page) {
                        if ($page['canonical_block_markup'] !== $subset[$index]['canonical_block_markup']) $adoptedSources[] = $page['source_path'];
                    }
                    // Language parts are placed at the authored position. This
                    // avoids installing a locale's shell on unrelated routes.
                    $renames = array();
                    foreach ($resolved['parts'] as &$part) {
                        $old = $part['slug'];
                        $slug = $old . ($identity === $entryIdentity ? '' : '-' . explode('-', $language)[0]);
                        for ($suffix = 2; isset($reservedSlugs[$slug]); ++$suffix) $slug = $old . '-' . $language . '-' . $suffix;
                        $reservedSlugs[$slug] = true;
                        $renames[$old] = $slug;
                        $oldSource = $part['source_path'];
                        $part['slug'] = $slug;
                        $part['source_path'] = 'wordpress-site-plan/shared/' . $slug . '#header';
                        $part['placement'] = array('kind' => 'inline_shared_shell', 'source_path' => 'wordpress-site-plan/shared/' . $slug, 'source_paths' => $adoptedSources);
                        $part['document_metadata']['source_context']['source_path'] = $part['source_path'];
                        $part['reconciliation_identity'] = WordPressSitePlan::identity('template-part', $part['source_path'], 'parts/' . $slug . '.html');
                        foreach ($runtimeDeclarations as &$declaration) {
                            if (!isset($declaration['payload']['entities'])) continue;
                            foreach ($declaration['payload']['entities'] as &$entity) {
                                if (($entity['source_path'] ?? null) === $oldSource) $entity['source_path'] = $part['source_path'];
                                foreach ($entity['bindings'] ?? array() as $key => $binding) {
                                    if (($binding['source_path'] ?? null) === $oldSource) $entity['bindings'][$key]['source_path'] = $part['source_path'];
                                }
                            }
                            unset($entity, $declaration['reconciliation_identity'], $declaration['payload_hash'], $declaration['content_hash']);
                        }
                        unset($declaration);
                    }
                    unset($part);
                    foreach ($resolved['pages'] as $index => $page) {
                        $markup = $page['canonical_block_markup'];
                        foreach ($renames as $old => $slug) {
                            $markup = str_replace('"slug":"' . $old . '"', '"slug":"' . $slug . '"', $markup);
                            if (in_array($page['source_path'], $adoptedSources, true) && !str_contains($markup, '"slug":"' . $slug . '"')) $markup = '<!-- wp:template-part {"slug":"' . $slug . '","tagName":"div","className":"blocks-engine-shell-transport"} /-->' . $markup;
                        }
                        $page['canonical_block_markup'] = $markup;
                        $page['content_hash'] = WordPressSitePlan::contentHash($markup);
                        $pages[$index] = $page;
                    }
                    array_push($parts, ...$resolved['parts']);
                    array_push($diagnostics, ...$resolved['diagnostics']);
                    $runtimeDeclarations = RuntimeDeclarations::normalizeForComposition($runtimeDeclarations);
                }
                continue;
            }
            $candidates = array(); $clusters = array(); $excluded = array(); $overrides = array();
            $templateSlugs = array(); $excludedTemplateSlugs = array();
            // Aligned edges have already replaced equivalent wrapper contracts.
            // Distinct authored wrappers still need the inner-copy fallback.
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
            foreach ($applicable as $index => $page) if (!isset($unremovable[$area][$index])) foreach ($page['shell_candidates'] ?? array() as $candidate) if ($area === ($candidate['area'] ?? null)) $candidates[$index][] = $candidate;
            // Clustering compares one route candidate per page. Builder sites
            // number chrome ids per route (logo-container-75450 versus
            // logo-container-236894), so the cluster key compares canonical
            // identities: unreferenced ids that share a stem across the area's
            // candidates collapse to that stem. Each candidate keeps its own
            // markup for removal and for the emitted part.
            $identityPages = array(); $identityInputs = array();
            foreach ($candidates as $index => $rows) {
                if (1 !== count($rows)) continue;
                $identityPages[] = $index;
                $identityInputs[] = (string) ($rows[0]['identity_markup'] ?? $rows[0]['markup']);
            }
            $clusterIdentities = array_combine($identityPages, self::canonicalizeRouteNumberedShellIds($identityInputs, $stylesheetIds));
            foreach ($applicable as $index => $page) {
                $rows = $candidates[$index] ?? array();
                if (1 !== count($rows)) { $excluded[$index] = count($rows) > 1 ? 'multiple' : 'missing'; continue; }
                $candidate = $rows[0];
                $candidateIdentity = hash('sha256', $area . "\0" . $clusterIdentities[$index]);
                if (!isset($clusters[$candidateIdentity]['candidate']) || (!empty($page['entrypoint']) && !empty($candidate['wordmark_route_state'])) || self::prefersScrollStateCarrier($candidate, $clusters[$candidateIdentity]['candidate'])) $clusters[$candidateIdentity]['candidate'] = $candidate;
                $clusters[$candidateIdentity]['indexes'][] = $index;
            }
            uasort($clusters, static fn(array $left, array $right): int => count($right['indexes']) <=> count($left['indexes']) ?: strcmp($left['candidate']['source_path'], $right['candidate']['source_path']));
            $identity = array_key_first($clusters);
            $cluster = null === $identity ? null : $clusters[$identity];
            $runnerUp = array_values($clusters)[1] ?? null;
            // A cluster cannot establish a fallback for a type it never contains.
            // Resolve those disjoint populations independently, including ties
            // between equally sized page and post shells.
            $types = array();
            foreach ($applicable as $index => $page) $types[$page['post_type']][] = $index;
            $disjointType = false;
            foreach ($types as $indexes) {
                if (is_array($cluster) && array() === array_intersect($indexes, $cluster['indexes'])) $disjointType = true;
            }
            $responsive = (bool) array_filter($applicable, fn(array $page): bool => $this->retainsResponsiveVariantLandmark($page['canonical_block_markup'], $area));
            if ($partitionTypes && count($types) > 1 && $disjointType && !$responsive) {
                // Types represented in the dominant cluster remain one population
                // and retain the site's portable header/footer part slugs.
                $dominantIndexes = array();
                foreach ($types as $type => $indexes) {
                    if (array() === array_intersect($indexes, $cluster['indexes'])) continue;
                    array_push($dominantIndexes, ...$indexes);
                    unset($types[$type]);
                }
                $types = array('' => $dominantIndexes) + $types;
                foreach ($types as $type => $indexes) {
                    $dominant = '' === $type;
                    $subset = array();
                    foreach ($indexes as $index) {
                        $subset[$index] = $pages[$index];
                        $subset[$index]['shell_candidates'] = array_values(array_filter($pages[$index]['shell_candidates'] ?? array(), static fn(array $candidate): bool => $area === $candidate['area']));
                    }
                    $resolved = $this->sharedShells($subset, $reservedSlugs, $runtimeDeclarations, $runtimeEntityRecords, false, true, $assets);
                    $renames = array();
                    $runtimeDeclarations = $resolved['runtime_declarations'];
                    foreach ($resolved['parts'] as &$part) {
                        $oldSlug = $part['slug'];
                        $baseSlug = $dominant ? $oldSlug : $oldSlug . '-' . $type;
                        $slug = $baseSlug;
                        for ($suffix = 2; isset($reservedSlugs[$slug]); ++$suffix) $slug = $baseSlug . '-' . $suffix;
                        $renames[$oldSlug] = $slug;
                        $oldSource = $part['source_path'];
                        $part['slug'] = $slug;
                        $part['source_path'] = 'wordpress-site-plan/shared/' . $slug . '#' . $area;
                        $part['placement']['source_path'] = 'wordpress-site-plan/shared/' . $slug;
                        $part['document_metadata']['source_context']['source_path'] = $part['source_path'];
                        $part['reconciliation_identity'] = WordPressSitePlan::identity('template-part', $part['source_path'], 'parts/' . $slug . '.html');
                        if (in_array($part['placement']['kind'], array('shared_shell', 'entry_shell'), true)) {
                            $allowed = $dominant
                                ? array_merge(WordPressSitePlan::NATIVE_QUERY_TEMPLATE_SLUGS, array_values(array_unique(array_map(static fn(array $page): string => 'page' === $page['post_type'] ? 'page' : 'single', $subset))))
                                : array('page' === $type ? 'page' : 'single');
                            if (array_filter($subset, static fn(array $page): bool => !empty($page['entrypoint']))) $allowed[] = 'front-page';
                            if (array() !== array_intersect($indexes, $cluster['indexes'])) $allowed = array_merge($allowed, WordPressSitePlan::NATIVE_QUERY_TEMPLATE_SLUGS);
                            $part['placement']['template_slugs'] = array_values(array_intersect($part['placement']['template_slugs'] ?? array(), $allowed));
                            $populationTypes = array_values(array_unique(array_column($subset, 'post_type')));
                            // A dominant shell can serve several singular post types.
                            // Keep the whole population when binding route overrides.
                            unset($part['placement']['post_type']);
                            $part['placement']['post_types'] = $populationTypes;
                        }
                        // Composition declarations are materialized before shell binding
                        // hoists; update only the newly hoisted part owner.
                        foreach ($runtimeDeclarations as &$declaration) {
                            if (!isset($declaration['payload']['entities'])) continue;
                            foreach ($declaration['payload']['entities'] as &$entity) {
                                if (($entity['source_path'] ?? null) === $oldSource) $entity['source_path'] = $part['source_path'];
                                if (!isset($entity['bindings'])) continue;
                                foreach ($entity['bindings'] as &$binding) if (($binding['source_path'] ?? null) === $oldSource) $binding['source_path'] = $part['source_path'];
                                unset($binding);
                            }
                            unset($entity);
                            unset($declaration['reconciliation_identity'], $declaration['payload_hash'], $declaration['content_hash']);
                        }
                        unset($declaration);
                    }
                    unset($part);
                    foreach ($resolved['pages'] as $index => $page) {
                        $page['shell_candidates'] = array_values(array_filter($pages[$index]['shell_candidates'] ?? array(), static fn(array $candidate): bool => $area !== $candidate['area']));
                        foreach ($renames as $oldSlug => $slug) $page['canonical_block_markup'] = str_replace('"slug":"' . $oldSlug . '"', '"slug":"' . $slug . '"', $page['canonical_block_markup']);
                        $page['content_hash'] = WordPressSitePlan::contentHash($page['canonical_block_markup']);
                        $pages[$index] = $page;
                    }
                    array_push($parts, ...$resolved['parts']);
                    array_push($diagnostics, ...$resolved['diagnostics']);
                    $runtimeDeclarations = RuntimeDeclarations::normalizeForComposition($runtimeDeclarations);
                }
                continue;
            }
            // A multi-route capture can carry chrome on the entry page only
            // (single-page apps whose legal pages render bare). When no other
            // route has any candidate for this area, nothing disagrees with
            // the entry page, so it owns the part for the front page alone.
            $entryOnly = is_array($cluster) && 1 === count($cluster['indexes']) && 1 < count($applicable) && !empty($pages[$cluster['indexes'][0]]['entrypoint']) && array() === array_diff_key($candidates, array($cluster['indexes'][0] => true)) && 1 === count($clusters);
            if (!is_array($cluster) || (!$entryOnly && count($cluster['indexes']) < count($applicable) && (count($cluster['indexes']) < 2 || (is_array($runnerUp) && count($cluster['indexes']) === count($runnerUp['indexes']))))) {
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
            // Shared ownership does not imply detached placement. When any
            // occurrence is nested, bind every occurrence at its source position
            // and leave route-owned ancestors and sibling order intact.
            // A single document's newly recognized page-edge landmark belongs
            // in every template; established multi-route layout bindings stay put.
            $inlineShell = (bool) array_filter($cluster['indexes'], fn(int $index): bool => (count($applicable) > 1 || empty($candidates[$index][0]['document_edge'])) && $this->hasLayoutAncestor(new \Automattic\BlocksEngine\PhpTransformer\Support\BlockMarkupIndex($pages[$index]['canonical_block_markup']), $candidates[$index][0], $area));
            // A heterogeneous responsive bundle has its own visibility owner.
            // Nesting that bundle beneath one viewport would hide the other;
            // retain the existing partition contract for those bundles.
            foreach ($cluster['indexes'] as $index) {
                if (!empty($candidates[$index][0]['device_document_bundle'])) { $inlineShell = false; break; }
                $variants = $this->scopedVariantLandmarks($pages[$index]['canonical_block_markup'], $area);
                if (!is_array($variants) || count($variants) < 2) continue;
                $variantIdentities = array();
                foreach ($variants as $rows) $variantIdentities = array_merge($variantIdentities, array_column($rows, 'identity_markup'));
                // Device documents are independent detection roots, not authored
                // layout ancestors. Even equal variants must move together under
                // their visibility scopes rather than leave references in content.
                if (count(array_unique($variantIdentities)) > 1 || str_contains($pages[$index]['canonical_block_markup'], CaptureEvidence::DOCUMENT_SCOPE)) { $inlineShell = false; break; }
            }
            foreach ($applicable as $index => $page) if (!in_array($index, $cluster['indexes'], true)) $excluded[$index] = isset($candidates[$index]) ? 'non_equivalent' : 'missing';
            // 'search' is never an applicable page in its own right (WordPress
            // synthesizes it), so it rides along wherever 'index' is bound: both
            // are the site's generic, non-singular fallback templates.
            $templateSlugs = count($cluster['indexes']) === count($applicable) ? WordPressSitePlan::NATIVE_TEMPLATE_SLUGS : WordPressSitePlan::NATIVE_QUERY_TEMPLATE_SLUGS;
            if (count($cluster['indexes']) !== count($applicable)) foreach ($applicable as $index => $page) {
                $selected = in_array($index, $cluster['indexes'], true);
                if (!empty($page['entrypoint'])) { if ($selected) $templateSlugs[] = 'front-page'; continue; }
                // The generic fallback templates always carry the dominant
                // shell; excluded routes get their own override templates.
                $templateSlugs[] = 'page' === ($page['post_type'] ?? null) ? 'page' : 'single';
                if (!$selected) {
                    $slug = WordPressSitePlan::routeTemplateKey($page);
                    if (isset($overrides[$slug])) {
                        $diagnostics[] = array('code' => 'wordpress_site_plan_shell_retained_ambiguous', 'severity' => 'info', 'message' => "{$area} shell exclusions cannot be assigned distinct route templates.", 'area' => $area, 'provenance' => $this->shellProvenance($area, 'retained', 'route_template_ambiguous', $candidates));
                        continue 2;
                    }
                    $overrides[$slug] = $index;
                }
            }
            if ($entryOnly) $templateSlugs = array('front-page');
            $templateSlugs = array_values(array_unique($templateSlugs));
            $excludedTemplateSlugs = array_keys($overrides ?? array());
            $withoutShells = array();
            $retainedForRuntimeBinding = false;
            foreach ($cluster['indexes'] as $index) {
                $page = $pages[$index];
                $candidate = $candidates[$index][0];
                $withoutShell = $inlineShell
                    ? $this->replaceShellInPlace($page['canonical_block_markup'], $candidate, $area)
                    : (isset($candidate['legacy_content_markup'])
                    ? (($candidate['legacy_page_markup'] ?? null) === $page['canonical_block_markup'] ? $candidate['legacy_content_markup'] : null)
                    : (!empty($candidate['nested_shell'])
                        ? $this->withoutNestedShell($page['canonical_block_markup'], $candidate)
                        : $this->withoutTopLevelShell($page['canonical_block_markup'], $area, $candidate['markup'], $candidate['offset'] ?? null)));
                // One route whose shell cannot be located keeps it page-owned;
                // the rest of the cluster still shares the part.
                if (null === $withoutShell && count($cluster['indexes']) > 2) {
                    $diagnostics[] = array('code' => 'wordpress_site_plan_shell_retained_ambiguous', 'severity' => 'info', 'message' => "{$area} shell remains page-owned on {$page['source_path']} because its candidate cannot be removed unambiguously.", 'area' => $area, 'source_path' => $page['source_path'], 'provenance' => $this->shellProvenance($area, 'retained', 'ambiguous'));
                    $unremovable[$area][$index] = true;
                    array_unshift($areas, $area);
                    continue 2;
                }
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
            // A wrapper whose only content is chrome moves to that route's
            // template around the shared part. Keep its authored attributes
            // per route; another page may render the same part unwrapped.
            $templateWrappers = array();
            $pageWrappers = array();
            foreach ($cluster['indexes'] as $index) {
                if ($inlineShell) continue;
                if (empty($candidates[$index][0]['nested_shell']) || array() !== ($candidates[$index][0]['additional_ranges'] ?? array())) continue;
                $ranges = $this->nestedShellRanges($pages[$index]['canonical_block_markup'], $candidates[$index][0], $area);
                if (1 !== count($ranges)) continue;
                $wrapper = self::soleChromeWrapper($pages[$index]['canonical_block_markup'], $ranges[0]);
                if (null === $wrapper) continue;
                $slug = WordPressSitePlan::routeTemplateKey($pages[$index]);
                $templateWrappers[$slug] = array('opening' => $wrapper['opening'], 'closing' => $wrapper['closing']);
                if ('page' === ($pages[$index]['post_type'] ?? null) && empty($pages[$index]['entrypoint'])) $pageWrappers[$index] = $templateWrappers[$slug];
                $withoutShells[$index] = $wrapper['page'];
            }
            $innerPages = array_filter($applicable, static fn(array $page): bool => 'page' === ($page['post_type'] ?? null) && empty($page['entrypoint']));
            if (array() !== $innerPages && count($pageWrappers) === count($innerPages) && 1 === count(array_unique(array_map('serialize', $pageWrappers)))) $templateWrappers['page'] = reset($pageWrappers);
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
            foreach ($withoutShells as $index => $withoutShell) {
                $variants = $this->scopedVariantLandmarks($pages[$index]['canonical_block_markup'], $area);
                if ($this->retainsResponsiveVariantLandmark($withoutShell, $area) || (!$inlineShell && is_array($variants) && count($variants) > 1)) {
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
                    $templateSlugs = WordPressSitePlan::NATIVE_TEMPLATE_SLUGS;
                } else {
                    $templateSlugs = WordPressSitePlan::NATIVE_QUERY_TEMPLATE_SLUGS;
                    foreach ($applicable as $index => $page) {
                        $selected = isset($withoutShells[$index]);
                        if (!empty($page['entrypoint'])) { if ($selected) $templateSlugs[] = 'front-page'; continue; }
                        // The generic fallback templates always carry the dominant
                        // shell; excluded routes get their own override templates.
                        $templateSlugs[] = 'page' === ($page['post_type'] ?? null) ? 'page' : 'single';
                        if (!$selected) {
                            $slug = WordPressSitePlan::routeTemplateKey($page);
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
            $runtimeDeclarations = RuntimeDeclarations::normalizeForComposition($runtimeDeclarations);
            $sourcePath = $singlePage ? $pages[array_key_first($applicable)]['source_path'] : 'wordpress-site-plan/shared/' . $area;
            $placement = $inlineShell ? 'inline_shared_shell' : ($singlePage ? 'entry_shell' : 'shared_shell');
            if ($inlineShell) { $templateSlugs = array(); $excludedTemplateSlugs = array(); }
            elseif ($singlePage) $templateSlugs = WordPressSitePlan::NATIVE_TEMPLATE_SLUGS;
            $partMarkup = is_array($absorbed) ? self::landmarkPartMarkup($absorbed['markup'], $area) : $first['template_part_markup'];
            // An entry shell renders on every route, but its in-page sections exist only on the entry page.
            if ($singlePage && !$inlineShell) $partMarkup = $this->plan->qualifyShellFragments($partMarkup, $pages[array_key_first($applicable)]['source_path']);
            $ancestorContext = is_array($absorbed) ? ($absorbed['ancestor_context'] ?? null) : ($first['ancestor_context'] ?? null);
            $container = isset($first['legacy_container_opening']) ? array('opening' => $first['legacy_container_opening'], 'closing' => $first['legacy_container_closing']) : null;
            $parts[] = array('source_path' => $sourcePath . '#' . $area, 'slug' => $area, 'title' => ucfirst($area), 'post_type' => 'wp_template_part', 'parent_source_path' => '', 'entrypoint' => false, 'area' => $area, 'tag_name' => 'div', 'placement' => array_filter(array('kind' => $placement, 'source_path' => $sourcePath, 'source_paths' => $inlineShell ? array_values(array_map(static fn(int $index): string => $pages[$index]['source_path'], $cluster['indexes'])) : null, 'template_slugs' => $templateSlugs, 'excluded_template_slugs' => $excludedTemplateSlugs, 'container' => $inlineShell ? null : $container, 'template_wrappers' => !$singlePage && !$inlineShell ? $templateWrappers : array()), static fn(mixed $value): bool => array() !== $value && null !== $value), 'canonical_block_markup' => $partMarkup, 'metadata' => array(), 'document_metadata' => array('source_context' => array('source_path' => $sourcePath . '#' . $area, 'kind' => 'template_part'), 'title' => ucfirst($area), 'title_declaration' => array('order' => 0, 'placement' => 'head'), 'meta' => array(), 'links' => array(), 'scripts' => array()), 'provenance' => $this->shellProvenance($area, 'extracted', is_array($absorbed) ? 'responsive_variant_partition' : (!empty($first['route_edge']) ? 'route_edge' : 'canonical'), array_intersect_key($candidates, array_flip($cluster['indexes'])), $identity), 'reconciliation_identity' => WordPressSitePlan::identity('template-part', $sourcePath . '#' . $area, 'parts/' . $area . '.html'), 'content_hash' => WordPressSitePlan::contentHash($partMarkup)) + (is_array($ancestorContext) ? array('ancestor_context' => $ancestorContext) : array());
            $diagnostics[] = array('code' => $singlePage ? 'wordpress_site_plan_shell_entry_extracted' : 'wordpress_site_plan_shell_extracted', 'severity' => 'info', 'message' => $singlePage ? "Extracted the entry {$area} shell for the front-page template." : "Extracted the dominant semantically equivalent {$area} shell cluster.", 'area' => $area, 'page_count' => count($cluster['indexes']), 'applicable_page_count' => count($applicable), 'exclusions' => array_map(static fn(int $index, string $reason): array => array('source_path' => $pages[$index]['source_path'], 'reason' => $reason), array_keys($excluded), $excluded));
        }
        foreach ($pages as &$page) unset($page['shell_candidates'], $page['_source_shared_variant_identities']); unset($page);
        return array('pages' => $pages, 'parts' => $parts, 'runtime_declarations' => $runtimeDeclarations, 'diagnostics' => $diagnostics);
    }

    /**
     * Route edges are the primary shell candidates. Where the blocks opening
     * (header) or closing (footer) the most routes are not exactly that
     * area's landmark candidate (a skip link, utility bar, background layer or
     * drawer travels with it, or there is no landmark), those routes offer the
     * edge instead, so shared-shell placement moves all of it. The landmark
     * candidates remain the fallback: they stand where no edge repeats, where
     * a landmark cluster spans more routes, and where a landmark carries its
     * own placement contract.
     *
     * When routes repeat more than one chrome design for an area, there is no
     * single site-wide header or footer: each repeated design becomes its
     * own editable part. Frame hoisting binds these parts in route templates,
     * including captured posts. Unmatched edges remain route-owned.
     *
     * @param array<int,array<string,mixed>> $pages
     * @param array<string,true> $reservedSlugs
     * @param array<int,array<string,mixed>> $runtimeDeclarations
     * @return array{pages:array<int,array<string,mixed>>,parts:list<array<string,mixed>>,diagnostics:list<array<string,mixed>>}
     */
    public function edgeShells(array $pages, array $reservedSlugs, array $runtimeDeclarations): array
    {
        $parts = array(); $diagnostics = array();
        $applicable = array_filter($pages, static fn(array $page): bool => empty($page['synthetic']));
        if (count($applicable) < 2) return compact('pages', 'parts', 'diagnostics');
        foreach (array('header', 'footer') as $area) {
            if (isset($reservedSlugs[$area])) continue;
            $landmarks = array(); $clusters = array(); $repeats = array();
            foreach ($applicable as $index => $page) {
                $rows = array_values(array_filter($page['shell_candidates'] ?? array(), static fn(array $candidate): bool => $area === ($candidate['area'] ?? null)));
                if (count($rows) > 1 && substr_count((string) $page['canonical_block_markup'], '"tagName":"main"') > 1) continue 2;
                // Legacy and responsive-copy candidates own placement
                // contracts an edge does not reproduce.
                foreach ($rows as $row) {
                    if (array() !== ($row['additional_ranges'] ?? array())) continue 3;
                    if (isset($row['legacy_content_markup']) || isset($row['legacy_container_opening'])) {
                        if (count($rows) > 1) continue 3;
                        continue 2;
                    }
                }
                $landmarks[$index] = $rows;
                foreach ($rows as $row) $repeats[self::landmarkIdentity($row)][$index] = true;
                if (1 === count($rows)) $clusters[self::landmarkIdentity($rows[0])][] = $index;
            }
            $edges = array();
            foreach (array_keys($applicable) as $index) {
                $edge = $this->routeEdge(new \Automattic\BlocksEngine\PhpTransformer\Support\BlockMarkupIndex((string) $pages[$index]['canonical_block_markup']), $pages[$index], $area, $runtimeDeclarations);
                if (array() !== $edge) $edges[$index] = $edge;
            }
            $edges = self::normalizeEdgeLinks($edges, $pages);
            $groups = self::edgeGroups($edges, $pages);
            $largestCluster = max(array_map('count', $clusters) ?: array(0));
            // Variants are distinct designs: they differ from their first
            // block, so no chrome would be copied into two parts, and no
            // landmark repeats on more routes than any of them (that landmark
            // is the site's single shell; the edges only differ around it).
            $firstBlocks = array_map(static fn(array $group): string => $edges[$group['indexes'][0]][0]['identity'], $groups);
            if (isset($groups[1]) && count(array_unique($firstBlocks)) === count($groups) && max(array_map(static fn(array $group): int => count($group['indexes']), $groups)) >= $largestCluster) {
                $variants = $this->edgeVariantParts($pages, $edges, $groups, $area, $reservedSlugs, $runtimeDeclarations);
                $pages = $variants['pages'];
                array_push($parts, ...$variants['parts']);
                array_push($diagnostics, ...$variants['diagnostics']);
                continue;
            }
            $group = $groups[0] ?? null;
            if (null === $group || count($group['indexes']) < $largestCluster) continue;
            // With a landmark the edge holds it at the same position on every
            // route and extends outward from it: the blocks before a header,
            // the blocks after a footer. Between the landmark and the content
            // it continues only through chrome (a drawer, a navigation overlay,
            // an empty scrim); a block with its own content there, such as a
            // hero, stays page content even when every route repeats it.
            $landmarkEnd = null;
            foreach ($group['indexes'] as $index) {
                if (array() === ($landmarks[$index] ?? array())) continue;
                // A landmark the edge's routes repeat is chrome, so the shared
                // edge must hold it. One unique among them is that page's own
                // content (a page-title or article header) and does not bound
                // the edge.
                $at = -1;
                foreach ($landmarks[$index] as $landmark) {
                    if (count(array_intersect_key($repeats[self::landmarkIdentity($landmark)] ?? array(), array_flip($group['indexes']))) < 2) continue;
                    $position = $this->edgeLandmarkIndex((string) $pages[$index]['canonical_block_markup'], array_slice($edges[$index], 0, $group['length']), (string) ($landmark['markup'] ?? ''));
                    if (null === $position) continue 3;
                    $at = max($at, $position);
                }
                if (-1 === $at) continue;
                if (null !== $landmarkEnd && $landmarkEnd !== $at + 1) continue 2;
                $landmarkEnd = $at + 1;
            }
            // A complete landmark can keep its context-aware placement only
            // when every route has one at the same depth. Mixed depths, missing
            // top-level candidates and wrapper contracts need the aligned edge
            // to own the cluster rather than splitting it by discovery path.
            $length = $landmarkEnd ?? $group['length'];
            $first = $group['indexes'][0];
            while ($length < $group['length'] && self::isOutOfFlowChrome(substr((string) $pages[$first]['canonical_block_markup'], $edges[$first][$length]['offset'], $edges[$first][$length]['length']))) ++$length;
            if (null !== $landmarkEnd && 1 === $length
                && count(array_unique(array_map(static fn(int $index): bool => $edges[$index][0]['nested'], $group['indexes']))) === 1
                && !array_filter($group['indexes'], static fn(int $index): bool => empty($landmarks[$index]) || !empty($landmarks[$index][0]['wrapper_contract_shell']))) continue;
            $nested = !empty($edges[$group['indexes'][0]][0]['nested']);
            $identity = hash('sha256', implode("\0", array_column(array_slice($edges[$group['indexes'][0]], 0, $length), 'identity')));
            foreach ($group['indexes'] as $index) {
                $range = self::edgeRange($edges[$index], $length);
                $markup = substr((string) $pages[$index]['canonical_block_markup'], $range['offset'], $range['length']);
                $candidate = array('area' => $area, 'markup' => $markup, 'template_part_markup' => self::landmarkPartMarkup(self::withoutEdgeCurrentState($markup, $pages[$index]), $area), 'identity_markup' => 'route-edge:' . $identity, 'source_path' => $pages[$index]['source_path'], 'offset' => $range['offset'], 'length' => $range['length'], 'route_edge' => true);
                if ($nested) $candidate += array('nested_shell' => true, 'additional_ranges' => array());
                $pages[$index]['shell_candidates'] = array_merge(array_values(array_filter($pages[$index]['shell_candidates'] ?? array(), static fn(array $row): bool => $area !== ($row['area'] ?? null))), array($candidate));
            }
        }
        return compact('pages', 'parts', 'diagnostics');
    }

    /**
     * One inline part per repeated edge group. A group whose edge carries a
     * runtime binding stays page-owned. An edge that is exactly the area's
     * landmark keeps that landmark as the part's semantic wrapper.
     *
     * @param array<int,array<string,mixed>> $pages
     * @param array<int,list<array{offset:int,length:int,identity:string,nested:bool}>> $edges
     * @param list<array{indexes:list<int>,length:int}> $groups
     * @param array<string,true> $reservedSlugs
     * @param array<int,array<string,mixed>> $runtimeDeclarations
     * @return array{pages:array<int,array<string,mixed>>,parts:list<array<string,mixed>>,diagnostics:list<array<string,mixed>>}
     */
    private function edgeVariantParts(array $pages, array $edges, array $groups, string $area, array $reservedSlugs, array $runtimeDeclarations): array
    {
        $parts = array(); $diagnostics = array();
        // The variant most routes use is the area's own part; each other one
        // is named after the first route that uses it.
        $mainGroup = null;
        foreach ($groups as $key => $group) if (null === $mainGroup || count($group['indexes']) > count($groups[$mainGroup]['indexes'])) $mainGroup = $key;
        foreach ($groups as $groupKey => $group) {
            $identity = hash('sha256', implode("\0", array_column(array_slice($edges[$group['indexes'][0]], 0, $group['length']), 'identity')));
            $slug = $groupKey === $mainGroup ? $area : $area . '-' . substr($identity, 0, 12);
            if (isset($reservedSlugs[$slug])) continue;
            $ranges = array();
            foreach ($group['indexes'] as $index) {
                $ranges[$index] = self::edgeRange($edges[$index], $group['length']);
                if (array() !== $this->runtimeBindingsInRanges($runtimeDeclarations, $pages[$index], array($ranges[$index]))['refs']) continue 2;
            }
            $first = $group['indexes'][0];
            $markup = self::landmarkPartMarkup(self::withoutEdgeCurrentState(substr((string) $pages[$first]['canonical_block_markup'], $ranges[$first]['offset'], $ranges[$first]['length']), $pages[$first]), $area);
            $reference = '<!-- wp:template-part {"slug":"' . $slug . '","area":"' . $area . '","tagName":"div","className":"blocks-engine-shell-transport"} /-->';
            // Replace from the end of each page so earlier offsets stay valid.
            foreach ($group['indexes'] as $index) {
                $page = (string) $pages[$index]['canonical_block_markup'];
                $at = $ranges[$index]['offset'];
                $end = $at + $ranges[$index]['length'];
                // A partial landmark is route content, not the shared part's
                // frame. Put the reference before it and retain its remainder.
                $insert = $at;
                foreach (self::blockNodes(new \Automattic\BlocksEngine\PhpTransformer\Support\BlockMarkupIndex($page)) as $node) {
                    if ($node['offset'] < $at && $node['end'] > $end && preg_match('/"tagName":"' . $area . '"/', $node['attributes'])) $insert = $node['offset'];
                }
                $pages[$index]['canonical_block_markup'] = substr($page, 0, $insert) . $reference . substr($page, $insert, $at - $insert) . substr($page, $end);
                $pages[$index]['content_hash'] = WordPressSitePlan::contentHash($pages[$index]['canonical_block_markup']);
            }
            $title = $groupKey === $mainGroup ? ucfirst($area) : self::routeVariantTitle($area, $pages[$first], count($parts) + 1);
            $sourcePath = 'wordpress-site-plan/shared/' . $slug;
            $sources = array_values(array_map(static fn(int $index): string => $pages[$index]['source_path'], $group['indexes']));
            $candidates = array_map(static fn(int $index): array => array(array('source_path' => $pages[$index]['source_path'], 'identity_markup' => 'route-edge:' . $identity)), $group['indexes']);
            $parts[] = array('source_path' => $sourcePath . '#' . $area, 'slug' => $slug, 'title' => $title, 'post_type' => 'wp_template_part', 'parent_source_path' => '', 'entrypoint' => false, 'area' => $area, 'tag_name' => 'div', 'placement' => array('kind' => 'inline_shared_shell', 'source_path' => $sourcePath, 'source_paths' => $sources, 'variant' => count($parts) + 1), 'canonical_block_markup' => $markup, 'metadata' => array(), 'document_metadata' => array('source_context' => array('source_path' => $sourcePath . '#' . $area, 'kind' => 'template_part'), 'title' => $title, 'title_declaration' => array('order' => 0, 'placement' => 'head'), 'meta' => array(), 'links' => array(), 'scripts' => array()), 'provenance' => $this->shellProvenance($area, 'extracted', 'route_variant', $candidates, $identity), 'reconciliation_identity' => WordPressSitePlan::identity('template-part', $sourcePath . '#' . $area, 'parts/' . $slug . '.html'), 'content_hash' => WordPressSitePlan::contentHash($markup));
            $diagnostics[] = array('code' => 'wordpress_site_plan_shell_route_variant_extracted', 'severity' => 'info', 'message' => "Extracted a repeated route-specific {$area} as an editable template part.", 'area' => $area, 'slug' => $slug, 'page_count' => count($sources), 'source_paths' => $sources);
        }
        return compact('pages', 'parts', 'diagnostics');
    }

    /**
     * Readable title for a route variant that is not the area's main part:
     * the area and the first route using it, `Header (Pricing)`. A route
     * without a title falls back to its slug, then to the variant number.
     *
     * @param array<string,mixed> $page
     */
    private static function routeVariantTitle(string $area, array $page, int $variant): string
    {
        foreach (array('title', 'slug') as $field) {
            $name = trim(preg_replace('/\s+/', ' ', html_entity_decode(strip_tags((string) ($page[$field] ?? '')), ENT_QUOTES | ENT_HTML5, 'UTF-8')) ?? '');
            if ('' !== $name) return ucfirst($area) . ' (' . $name . ')';
        }
        return ucfirst($area) . ' Variant ' . $variant;
    }

    /** @param array<string,mixed> $candidate */
    private static function landmarkIdentity(array $candidate): string
    {
        return hash('sha256', ($candidate['identity_markup'] ?? $candidate['markup'] ?? ''));
    }

    /**
     * Position, counted from the page edge, of the edge block that holds the
     * landmark candidate, or null when the edge does not hold it.
     *
     * @param list<array{offset:int,length:int,identity:string,nested:bool}> $edge
     */
    private function edgeLandmarkIndex(string $markup, array $edge, string $landmark): ?int
    {
        if ('' === $landmark) return null;
        foreach ($edge as $position => $block) {
            $at = strpos(substr($markup, $block['offset'], $block['length']), $landmark);
            if (false !== $at) return $position;
        }
        return null;
    }

    /**
     * The edge blocks of one route, outermost first for a header and last
     * first for a footer, each with its byte range and route-free identity.
     *
     * @param array<string,mixed> $page
     * @param array<int,array<string,mixed>> $runtimeDeclarations
     * @return list<array{offset:int,length:int,identity:string}>
     */
    private function routeEdge(\Automattic\BlocksEngine\PhpTransformer\Support\BlockMarkupIndex $index, array $page, string $area, array $runtimeDeclarations): array
    {
        $markup = $index->markup;
        // Device-split documents keep their existing correspondence contract;
        // never extract only the desktop edge from a multi-document bundle.
        if (substr_count($markup, '"tagName":"main"') > 1) return array();
        $base = 0; $depth = 0;
        $ranges = $index->topLevel();
        // Empty embed holders beside the app frame stay page-owned and do
        // not count against its sole rendering wrapper.
        while (true) {
            $rendering = array_values(array_filter($ranges, static fn(array $range): bool => !self::hasEmptyContent(substr($markup, $base + $range['offset'], $range['length']))));
            if (1 !== count($rendering)) break;
            $wrapper = $rendering[0];
            $block = substr($markup, $base + $wrapper['offset'], $wrapper['length']);
            if (!self::isGroupBlock($block) || preg_match('/^<!--\s*wp:group\s+\{[^}]*"tagName":"main"/', $block)) return array();
            $children = $index->children($wrapper);
            if (array() === $children) return array();
            $ranges = $children;
            ++$depth;
        }
        $ranges = $rendering;
        if (count($ranges) < 2) return array();
        // A landmark may frame both the repeated navbar and route-owned hero.
        // Open that frame only when it has content of its own after chrome;
        // intact link-only landmarks retain their authored wrapper.
        $position = 'header' === $area ? 0 : count($ranges) - 1;
        $outer = $ranges[$position];
        $block = substr($markup, $base + $outer['offset'], $outer['length']);
        if ('header' === $area && self::isGroupBlock($block) && preg_match('/"tagName":"header"/', $block) && str_contains($block, '<!-- wp:heading')) {
            $children = $index->children($outer);
            if (count($children) > 1) {
                array_splice($ranges, $position, 1, $children);
                ++$depth;
            }
        }
        if ('footer' === $area) $ranges = array_reverse($ranges);
        $edge = array(); $contentFollows = false;
        $other = 'header' === $area ? 'footer' : 'header';
        foreach ($ranges as $range) {
            $offset = $base + $range['offset'];
            $block = substr($markup, $offset, $range['length']);
            if (self::isCapturedModalDialogBlock($block) || self::hasEmptyContent($block)) continue;
            // Main content, an existing part, or a block a binding blocks ends
            // the edge with content beyond it; the other area's landmark ends
            // it without.
            if (str_contains($block, 'wp:template-part') || preg_match('/"tagName":"main"|<main\b/', $block)) { $contentFollows = true; break; }
            if (preg_match('/"tagName":"' . $other . '"|<' . $other . '\b/', $block)) break;
            if ($this->runtimeBindingsInRanges($runtimeDeclarations, $page, array(array('offset' => $offset, 'length' => $range['length'])))['blocked']) { $contentFollows = true; break; }
            $edge[] = array('offset' => $offset, 'length' => $range['length'], 'identity' => self::edgeBlockIdentity($block), 'nested' => 0 < $depth);
        }
        // Chrome frames content: when only the other area's chrome or the
        // page end follows the edge, its innermost block is the content.
        if (!$contentFollows) array_pop($edge);
        return $edge;
    }

    /**
     * Whether a block is chrome rather than page content: it carries site
     * navigation, its own element is a dialog, drawer or hidden layer, or it
     * holds no text or media at all (an overlay or scrim).
     */
    private static function isOutOfFlowChrome(string $block): bool
    {
        if (1 === preg_match('/<!--\s*wp:navigation\b/', $block)) return true;
        $html = preg_replace('/<!--.*?-->/s', '', $block) ?? '';
        if (1 !== preg_match('/<([a-z][a-z0-9-]*)\b([^>]*)>/i', $html, $tag)) return false;
        if (self::hasEmptyContent($block)) return true;
        if (in_array(strtolower($tag[1]), array('nav', 'dialog'), true)) return true;
        $attributes = $tag[2];
        if (1 === preg_match('/\srole=["\']?(?:dialog|alertdialog|navigation|menu)\b/i', $attributes)) return true;
        return 1 === preg_match('/\saria-(?:hidden=["\']?true|modal=["\']?true)\b|\shidden(?:[\s=>]|$)/i', $attributes);
    }

    private static function hasEmptyContent(string $block): bool
    {
        if (1 === preg_match('/<!--\s*wp:navigation\b/', $block)) return false;
        $html = preg_replace('/<!--.*?-->/s', '', $block) ?? '';
        $html = preg_replace('#<(script|style)\b[^>]*>.*?</\1\s*>#is', '', $html) ?? $html;
        return '' === trim(html_entity_decode(strip_tags($html))) && 1 !== preg_match('/<(?:img|picture|video|audio|iframe|svg|canvas|object|embed|input|select|textarea|button)\b/i', $html);
    }

    /**
     * Plain anchors do not have core/navigation's current-item machinery.
     * Infer author state tokens only from self links, never from a name such
     * as "active" alone. Engine paint/geometry classes remain intact.
     *
     * @param array<string,mixed> $page
     */
    private static function withoutEdgeCurrentState(string $markup, array $page): string
    {
        $self = array(); $rest = array();
        preg_match_all('/<a\b[^>]*>/i', $markup, $anchors);
        foreach ($anchors[0] as $anchor) {
            if (!preg_match('/\bhref="([^"]*)"/', $anchor, $href)) continue;
            preg_match('/\sclass="([^"]*)"/', $anchor, $classes);
            $tokens = preg_split('/\s+/', trim($classes[1] ?? '')) ?: array();
            $current = html_entity_decode($href[1], ENT_QUOTES | ENT_HTML5, 'UTF-8') === ($page['route']['path'] ?? null);
            foreach ($tokens as $token) {
                if ($current && str_contains($anchor, 'aria-current=')) $self[$token] = true;
                else $rest[$token] = true;
            }
        }
        $state = array_diff_key($self, $rest);
        foreach (array_keys($state) as $token) if ('' === $token || str_starts_with($token, 'blocks-engine-') || str_starts_with($token, 'be-') || str_starts_with($token, 'wp-')) unset($state[$token]);
        $markup = preg_replace_callback('/<a\b[^>]*>/i', static function (array $match) use ($page, $state): string {
            $anchor = $match[0];
            if (!preg_match('/\bhref="([^"]*)"/', $anchor, $href) || html_entity_decode($href[1], ENT_QUOTES | ENT_HTML5, 'UTF-8') !== ($page['route']['path'] ?? null)) return $anchor;
            $anchor = preg_replace('/\saria-current="[^"]*"/', '', $anchor) ?? $anchor;
            return preg_replace_callback('/\sclass="([^"]*)"/', static function (array $classes) use ($state): string {
                $tokens = array_values(array_filter(preg_split('/\s+/', trim($classes[1])) ?: array(), static fn(string $token): bool => !isset($state[$token])));
                return array() === $tokens ? '' : ' class="' . implode(' ', $tokens) . '"';
            }, $anchor) ?? $anchor;
        }, $markup) ?? $markup;
        // layout-shell attributes duplicate the anchor's author class list.
        $markup = preg_replace_callback('/"className":"([^"]*)"/', static function (array $match) use ($state): string {
            return '"className":"' . implode(' ', array_filter(explode(' ', $match[1]), static fn(string $token): bool => !isset($state[$token]))) . '"';
        }, $markup) ?? $markup;
        return self::withoutCurrentNavigationState($markup);
    }

    /**
     * Compare blocks with the same shape before allowing a small minority of
     * link destinations to vary. HTML anchors and layout-shell href copies
     * are not parallel arrays: text anchors have no wrapper. Build a value
     * set per cluster and replace those values in both representations.
     * Fixed links that are self on just one route must normalize on every
     * route, including their non-self copies.
     *
     * @param array<int,array> $edges
     * @param array<int,array> $pages
     * @return array<int,array>
     */
    private static function normalizeEdgeLinks(array $edges, array $pages): array
    {
        $clusters = array();
        $hrefPattern = '/(?<=\\bhref=")[^"]*|(?<="href":")[^"]*/';
        foreach ($edges as $index => $edge) foreach ($edge as $position => $range) {
            $markup = self::withoutEdgeCurrentState(substr((string) $pages[$index]['canonical_block_markup'], $range['offset'], $range['length']), $pages[$index]);
            $shape = self::edgeBlockIdentity(preg_replace($hrefPattern, 'edge-link', $markup) ?? $markup);
            $clusters[$position . ':' . $shape][$index] = array('position' => $position, 'markup' => $markup);
        }
        foreach ($clusters as $cluster) {
            if (count($cluster) < 2) continue;
            $links = array(); $values = array();
            foreach ($cluster as $index => $row) {
                // Menu items count as links too: a header whose menu is a
                // navigation block still has mostly stable links when only
                // its language switch follows the route.
                preg_match_all('/<a\\b[^>]*\\bhref="([^"]*)"|<!-- wp:navigation-(?:sub)?link (\\{.*?\\}) \\/?-->/', $row['markup'], $matches);
                $links[$index] = array_map(static fn(string $href, string $attrs): string => '' !== $attrs ? (string) (json_decode($attrs, true)['url'] ?? '') : $href, $matches[1], $matches[2]);
            }
            $first = reset($links);
            if (array() === $first) continue;
            $varying = 0;
            foreach ($first as $slot => $href) {
                $targets = array_unique(array_column($links, $slot));
                if (count($targets) > 1) {
                    ++$varying;
                    foreach ($targets as $target) $values[$target] = true;
                }
                foreach ($links as $index => $routeLinks) if (html_entity_decode($routeLinks[$slot], ENT_QUOTES | ENT_HTML5, 'UTF-8') === ($pages[$index]['route']['path'] ?? null)) $values[$routeLinks[$slot]] = true;
            }
            if (0 === $varying || $varying > min(3, intdiv(count($first), 3))) continue;
            foreach ($cluster as $index => $row) {
                $markup = preg_replace_callback($hrefPattern, static fn(array $match): string => isset($values[$match[0]]) ? 'edge-link' : $match[0], $row['markup']) ?? $row['markup'];
                $edges[$index][$row['position']]['identity'] = self::edgeBlockIdentity($markup);
            }
        }
        return $edges;
    }

    private static function edgeBlockIdentity(string $block): string
    {
        $markup = self::identityMarkup(self::withoutCurrentNavigationState($block, true));
        $markup = preg_replace('/\b(?:id|anchor)="[^"]*"/i', '', $markup) ?? $markup;
        $markup = preg_replace('/"anchor":"[^"]*"/', '', $markup) ?? $markup;
        // A class list is a set: removing a route's current-state class can
        // leave a repeated token on the selected item only.
        $markup = preg_replace_callback('/("className"\s*:\s*"|\bclass=")([^"]*)"/', static fn(array $match): string => $match[1] . implode(' ', array_unique(preg_split('/\s+/', trim($match[2])) ?: array())) . '"', $markup) ?? $markup;
        return hash('sha256', preg_replace('/\s+/', ' ', trim($markup)) ?? trim($markup));
    }

    /**
     * Routes grouped by the chrome edge they share, best first: each group is
     * its routes and the edge's block count. Each prefix length proposes the
     * largest set of routes that share it; the edge that moves the most chrome
     * out of pages wins, so a banner most routes repeat is not cut short by
     * one route that shares only the top bar above it. A route joins one
     * group. The edge stops at the first block where its routes disagree and
     * must hold more than a lone skip link: chrome carries navigation, a
     * landmark or links.
     *
     * @param array<int,list<array{offset:int,length:int,identity:string,nested:bool}>> $edges
     * @param array<int,array<string,mixed>> $pages
     * @return list<array{indexes:list<int>,length:int}>
     */
    private static function edgeGroups(array $edges, array $pages): array
    {
        $groups = array();
        while (true) {
            $best = null;
            for ($length = 1; ; ++$length) {
                $byPrefix = array();
                foreach ($edges as $index => $edge) if (count($edge) >= $length) $byPrefix[implode("\0", array_column(array_slice($edge, 0, $length), 'identity'))][] = $index;
                $byPrefix = array_filter($byPrefix, static fn(array $indexes): bool => count($indexes) >= 2);
                if (array() === $byPrefix) break;
                foreach ($byPrefix as $indexes) {
                    $score = count($indexes) * array_sum(array_column(array_slice($edges[$indexes[0]], 0, $length), 'length'));
                    if (null === $best || $score > $best['score'] || ($score === $best['score'] && self::holdsEntry($indexes, $pages) > self::holdsEntry($best['indexes'], $pages))) $best = array('score' => $score, 'indexes' => $indexes, 'length' => $length);
                }
            }
            if (null === $best) return $groups;
            $indexes = $best['indexes'];
            usort($indexes, static fn(int $left, int $right): int => (int) empty($pages[$left]['entrypoint']) <=> (int) empty($pages[$right]['entrypoint']) ?: $left <=> $right);
            $range = self::edgeRange($edges[$indexes[0]], $best['length']);
            if (self::carriesChrome(substr((string) $pages[$indexes[0]]['canonical_block_markup'], $range['offset'], $range['length']))) $groups[] = array('indexes' => $indexes, 'length' => $best['length']);
            foreach ($indexes as $index) unset($edges[$index]);
        }
    }

    /**
     * Byte range of a route's first $length edge blocks.
     *
     * @param list<array{offset:int,length:int,identity:string,nested:bool}> $edge
     * @return array{offset:int,length:int}
     */
    private static function edgeRange(array $edge, int $length): array
    {
        $blocks = array_slice($edge, 0, $length);
        $offset = min(array_column($blocks, 'offset'));
        return array('offset' => $offset, 'length' => max(array_map(static fn(array $block): int => $block['offset'] + $block['length'], $blocks)) - $offset);
    }

    /** @param list<int> $indexes @param array<int,array<string,mixed>> $pages */
    private static function holdsEntry(array $indexes, array $pages): int
    {
        foreach ($indexes as $index) if (!empty($pages[$index]['entrypoint'])) return 1;
        return 0;
    }

    /** Chrome carries navigation, a header/footer/nav landmark or links. */
    private static function carriesChrome(string $markup): bool
    {
        if (str_contains($markup, '<!-- wp:navigation ') || preg_match('/"tagName":"(?:header|footer|nav)"/', $markup) || preg_match('/<(?:header|footer|nav)\b/i', $markup)) return true;
        return preg_match_all('/<a\b[^>]*\bhref=/i', $markup) >= 2;
    }

    /**
     * Move the layout frame around an in-place shared header/footer out of the
     * page and into the route's template.
     *
     * An inline shell stays at its source position, so the page content held
     * the app wrapper, the header and footer references and every sibling of
     * the main content. Here the page keeps only the blocks that sit between
     * the header and the footer. The wrapper, the references and the
     * surrounding chrome become the template, with post-content in the gap. The
     * template renders the same block tree as before, so the front end keeps
     * its heights and positions. The post-content wrapper element is dropped
     * at render time for the same reason (see the marker class).
     *
     * A page is left alone when the split is not clearly safe: no main content
     * between the shells, a main landmark inside the frame, a second reference,
     * or a runtime binding anchored in the frame.
     *
     * @param array<int,array<string,mixed>> $pages
     * @param array<int,array<string,mixed>> $parts
     * @param array<int,array<string,mixed>> $runtimeDeclarations
     * @return array{pages:array<int,array<string,mixed>>,frames:array<string,array{opening:string,closing:string,shells:array<int,string>}>,generic_source:?string}
     */
    public function hoistInlineShellFrames(array $pages, array $parts, array $runtimeDeclarations): array
    {
        $areas = array();
        foreach ($parts as $part) if ('inline_shared_shell' === ($part['placement']['kind'] ?? null) && in_array($part['area'] ?? null, array('header', 'footer'), true)) $areas[(string) $part['slug']] = (string) $part['area'];
        if (array() === $areas) return array('pages' => $pages, 'frames' => array(), 'generic_source' => null);
        // Without a heading/main landmark, repeated wrapper structure can
        // still prove the content boundary. Require the exact frame on every
        // captured route; a route-specific chain retains in-place transport.
        $invariant = array();
        foreach ($pages as $index => $page) {
            if (!empty($page['synthetic'])) continue;
            $candidate = $this->shellFrame((string) ($page['canonical_block_markup'] ?? ''), $areas, true);
            if (null !== $candidate) $invariant[$index] = $candidate;
        }
        $capturedCount = count(array_filter($pages, static fn(array $page): bool => empty($page['synthetic'])));
        $invariant = $capturedCount >= 2 && count($invariant) === $capturedCount && 1 === count(array_unique(array_map(self::frameIdentity(...), $invariant))) ? $invariant : array();
        $frames = array();
        $split = array();
        foreach ($pages as $index => $page) {
            if (!empty($page['synthetic'])) continue;
            $frame = $this->shellFrame((string) ($page['canonical_block_markup'] ?? ''), $areas) ?? ($invariant[$index] ?? null);
            if (null === $frame) continue;
            $ranges = array(array('offset' => 0, 'length' => strlen($frame['opening'])), array('offset' => strlen($page['canonical_block_markup']) - strlen($frame['closing']), 'length' => strlen($frame['closing'])));
            $found = $this->runtimeBindingsInRanges($runtimeDeclarations, $page, $ranges);
            if ($found['blocked'] || array() !== $found['refs']) continue;
            $split[$index] = self::withSharedDialogId($frame, $parts);
        }
        // The generic page template carries the frame most inner pages share in
        // structure (compared without per-document marker seeds and dialog ids),
        // so a page made later in WordPress gets the site chrome. Its marker
        // rules are site-wide chrome rules, so any page of that shape can use it.
        // A page with another structure, or none, gets a route template.
        $inner = array_filter($pages, static fn(array $page): bool => 'page' === ($page['post_type'] ?? null) && empty($page['entrypoint']) && empty($page['synthetic']));
        // A frame around a route's own variant of an area the generic page
        // template already binds as a shared part is that route's alone.
        $boundAreas = array();
        foreach ($parts as $part) if ('shared_shell' === ($part['placement']['kind'] ?? null) && in_array('page', $part['placement']['template_slugs'] ?? array(), true)) $boundAreas[(string) ($part['area'] ?? '')] = true;
        $groups = array();
        foreach (array_intersect_key($split, $inner) as $index => $frame) {
            preg_match_all('/<!--\s*wp:template-part\s+\{[^}]*"slug":"([^"]+)"/', $frame['opening'] . $frame['closing'], $slugs);
            foreach ($slugs[1] as $slug) if (isset($areas[$slug], $boundAreas[$areas[$slug]])) continue 2;
            $groups[self::frameIdentity($frame)][] = $index;
        }
        $generic = null;
        foreach ($groups as $indexes) if (null === $generic || count($indexes) > count($generic)) $generic = $indexes;
        $genericSource = null !== $generic ? (string) $pages[$generic[0]]['source_path'] : null;
        $generic = null !== $generic ? $split[$generic[0]] : null;
        $postGroups = array();
        foreach ($split as $index => $frame) if ('post' === $pages[$index]['post_type']) $postGroups[self::frameIdentity($frame)][] = $index;
        $postGeneric = null;
        foreach ($postGroups as $indexes) if (null === $postGeneric || count($indexes) > count($postGeneric)) $postGeneric = $indexes;
        $postCount = count(array_filter($pages, static fn(array $page): bool => empty($page['synthetic']) && 'post' === $page['post_type']));
        $splitPostCount = array_sum(array_map('count', $postGroups));
        $postFrame = null !== $postGeneric && $postCount === $splitPostCount ? $split[$postGeneric[0]] : null;
        // Partition every assigned lifecycle by frame, not just same-slug
        // overrides. A shared key must never let the last cluster overwrite
        // another route's header/footer. Identical overrides are shared later.
        $excludedTemplates = array();
        foreach ($parts as $part) foreach ($part['placement']['excluded_template_slugs'] ?? array() as $slug) $excludedTemplates[$slug] = true;
        $assigned = array_filter(array('page' => $generic, 'single' => $postFrame));
        foreach ($split as $index => $frame) {
            $page = $pages[$index];
            $routeKey = WordPressSitePlan::routeTemplateKey($page);
            $slug = !empty($page['entrypoint']) ? 'front-page' : ($page['route_template'] ?? (isset($inner[$index]) && null !== $generic ? 'page' : ('post' === $page['post_type'] && null !== $postFrame ? 'single' : $routeKey)));
            // Exclusions already require a route override. Its content may be
            // stripped only when that override receives the captured frame.
            if (isset($excludedTemplates[$routeKey])) $slug = $routeKey;
            if (isset($assigned[$slug]) && self::frameIdentity($assigned[$slug]) !== self::frameIdentity($frame)) {
                $slug = WordPressSitePlan::routeTemplateKey(array_diff_key($page, array('route_template' => true)));
                $pages[$index]['route_template'] = $slug;
                $pages[$index]['metadata']['post_meta']['_wp_page_template'] = $slug;
            }
            $assigned[$slug] = $frame;
            preg_match_all('/<!--\s*wp:template-part\s+\{[^}]*"slug":"([^"]+)"/', $frame['opening'] . $frame['closing'], $slugs);
            $frames[$slug] = array('opening' => $frame['opening'], 'closing' => $frame['closing'], 'shells' => array_values(array_unique($slugs[1])));
            $pages[$index]['canonical_block_markup'] = $frame['content'];
            $pages[$index]['content_hash'] = WordPressSitePlan::contentHash($frame['content']);
        }
        if (null !== $generic && !isset($frames['page'])) {
            preg_match_all('/<!--\s*wp:template-part\s+\{[^}]*"slug":"([^"]+)"/', $generic['opening'] . $generic['closing'], $slugs);
            $frames['page'] = array('opening' => $generic['opening'], 'closing' => $generic['closing'], 'shells' => array_values(array_unique($slugs[1])));
        }
        // Use the shared cluster rather than entry-only wrappers or an
        // incomplete entry frame. Preserve a distinct entry cluster (for
        // example another language) when it owns different shared parts.
        $fallback = $frames['front-page'] ?? $frames['page'] ?? null;
        if (isset($frames['page']) && array() === array_diff($fallback['shells'] ?? array(), $frames['page']['shells'])) {
            $fallback = $frames['page'];
        }
        if (null !== $fallback) {
            $hasCapturedPosts = (bool) array_filter($pages, static fn(array $page): bool => 'post' === ($page['post_type'] ?? null));
            foreach (array('single', 'index', 'archive', 'search', '404') as $slug) {
                // Captured posts retain occurrence-owned chrome in their layout.
                if ('single' === $slug && $hasCapturedPosts) continue;
                $frames[$slug] = $fallback;
            }
            if (!isset($frames['page']) && 1 === count(array_filter($pages, static fn(array $page): bool => empty($page['synthetic'])))) {
                $frames['page'] = $fallback;
                $genericSource = (string) $pages[array_key_first($split)]['source_path'];
            }
        }
        // A page that keeps its chrome in its own content must not also get the
        // generic frame, so it renders through a plain route template.
        if (isset($frames['page'])) foreach ($inner as $index => $page) if (!isset($split[$index])) $frames[WordPressSitePlan::routeTemplateKey($page)] = array('opening' => '', 'closing' => '', 'shells' => array());
        return array('pages' => $pages, 'frames' => $frames, 'generic_source' => $genericSource);
    }

    /**
     * Fold the chrome that follows the content slot in every template frame
     * into the footer part.
     *
     * A frame repeats its trailing siblings (the footer wrapper around the
     * part reference, and site-wide layers such as a consent banner or chat
     * widget) verbatim in every template. When every frame ends with the same
     * run (before any trailing modal dialog) and that run holds the one inline
     * footer reference, the run is the
     * footer: it moves into the part and each frame keeps one reference. The
     * reference renders as a layout-transparent transport wrapper, so sibling
     * order and paint are unchanged.
     *
     * @param array<string,array{opening:string,closing:string,shells:array<int,string>}> $frames
     * @param array<int,array<string,mixed>> $parts
     * @param array<int,array<string,mixed>> $pages
     * @return array{frames:array<string,array{opening:string,closing:string,shells:array<int,string>}>,parts:array<int,array<string,mixed>>}
     */
    public function foldFrameTrailingChrome(array $frames, array $parts, array $pages): array
    {
        $unchanged = array('frames' => $frames, 'parts' => $parts);
        $footers = array_keys(array_filter($parts, static fn(array $part): bool => 'footer' === ($part['area'] ?? null) && 'inline_shared_shell' === ($part['placement']['kind'] ?? null)));
        if (1 !== count($footers)) return $unchanged;
        $partIndex = $footers[0];
        $slug = (string) $parts[$partIndex]['slug'];
        $reference = '/<!--\s*wp:template-part\s+\{[^}]*"slug":"' . preg_quote($slug, '/') . '"[^}]*\}\s*\/-->/';
        foreach ($pages as $page) if (1 === preg_match($reference, (string) ($page['canonical_block_markup'] ?? ''))) return $unchanged;
        $slot = '<!-- wp:post-content {"className":"blocks-engine-frame-slot"} /-->';
        $runs = array();
        foreach ($frames as $template => $frame) {
            if (!in_array($slug, $frame['shells'], true)) continue;
            $markup = $frame['opening'] . $slot . $frame['closing'];
            $nodes = self::blockNodes(new \Automattic\BlocksEngine\PhpTransformer\Support\BlockMarkupIndex($markup));
            $slotId = null;
            foreach ($nodes as $id => $node) if ($node['offset'] === strlen($frame['opening'])) { $slotId = $id; break; }
            if (null === $slotId) return $unchanged;
            $parent = $nodes[$slotId]['parent'];
            $siblings = null === $parent ? self::topLevelNodeIds($nodes) : $nodes[$parent]['children'];
            $trailing = array_slice($siblings, (int) array_search($slotId, $siblings, true) + 1);
            // A modal dialog mounted at the end belongs to its header trigger
            // and renders in the top layer; it stays in the frame.
            while (array() !== $trailing && self::isCapturedModalDialogBlock(substr($markup, $nodes[end($trailing)]['offset'], $nodes[end($trailing)]['end'] - $nodes[end($trailing)]['offset']))) array_pop($trailing);
            if (array() === $trailing) return $unchanged;
            $start = $nodes[$trailing[0]]['offset'];
            $end = $nodes[$trailing[count($trailing) - 1]]['end'];
            $run = substr($markup, $start, $end - $start);
            if (1 !== preg_match_all($reference, $run, $found)) return $unchanged;
            // Everything the frame holds after the slot outside this run (its
            // closing wrappers) must not reference the part again.
            if (1 !== preg_match_all($reference, $frame['closing'])) return $unchanged;
            $runs[$template] = array('run' => $run, 'start' => $start - strlen($frame['opening']) - strlen($slot), 'reference' => $found[0][0]);
        }
        if (array() === $runs || 1 !== count(array_unique(array_map(static fn(array $run): string => self::frameIdentity(array('opening' => $run['run'], 'closing' => '')), $runs)))) return $unchanged;
        $first = reset($runs);
        $partMarkup = (string) preg_replace_callback($reference, static fn(): string => (string) $parts[$partIndex]['canonical_block_markup'], $first['run'], 1);
        foreach ($runs as $template => $run) {
            $frames[$template]['closing'] = substr_replace($frames[$template]['closing'], $run['reference'], $run['start'], strlen($run['run']));
        }
        $parts[$partIndex]['canonical_block_markup'] = $partMarkup;
        $parts[$partIndex]['content_hash'] = WordPressSitePlan::contentHash($partMarkup);
        return array('frames' => $frames, 'parts' => $parts);
    }

    /**
     * The captured dialog and its trigger are linked by id. The shared header
     * part holds one trigger, so a frame's dialog takes that trigger's id.
     *
     * @param array{opening:string,closing:string,content:string} $frame
     * @param array<int,array<string,mixed>> $parts
     * @return array{opening:string,closing:string,content:string}
     */
    private static function withSharedDialogId(array $frame, array $parts): array
    {
        $triggers = array();
        foreach ($parts as $part) if ('inline_shared_shell' === ($part['placement']['kind'] ?? null) && preg_match_all('/blocks-engine-dialog-trigger-([0-9a-f]{16})-/', (string) ($part['canonical_block_markup'] ?? ''), $found)) $triggers = array_merge($triggers, $found[1]);
        $triggers = array_unique($triggers);
        $framed = $frame['opening'] . $frame['closing'];
        if (1 !== count($triggers) || !preg_match_all('/blocks-engine-dialog-(?!trigger-)([0-9a-f]{16})\b/', $framed, $dialogs) || 1 !== count(array_unique($dialogs[1]))) return $frame;
        $frame['opening'] = str_replace($dialogs[1][0], reset($triggers), $frame['opening']);
        $frame['closing'] = str_replace($dialogs[1][0], reset($triggers), $frame['closing']);
        return $frame;
    }

    /** @param array{opening:string,closing:string} $frame */
    private static function frameIdentity(array $frame): string
    {
        return (string) preg_replace('/blocks-engine-dialog(?:-trigger)?-[0-9a-f]{16}/', 'blocks-engine-dialog', EngineMarker::withoutDocumentSeeds($frame['opening'] . "\0" . $frame['closing']));
    }

    /**
     * @param array<string,string> $areas Inline shell part slug to its area.
     * @return array{opening:string,closing:string,content:string}|null
     */
    private function shellFrame(string $markup, array $areas, bool $allowInvariantChain = false): ?array
    {
        $nodes = self::blockNodes(new \Automattic\BlocksEngine\PhpTransformer\Support\BlockMarkupIndex($markup));
        if (array() === $nodes) return null;
        $refs = array();
        foreach ($nodes as $id => $node) {
            if ('template-part' !== $node['name'] || !preg_match('/"slug":"([^"]+)"/', $node['attributes'], $match) || !isset($areas[$match[1]])) continue;
            if (isset($refs[$areas[$match[1]]])) return null;
            $refs[$areas[$match[1]]] = $id;
        }
        if (array() === $refs) return null;
        $level = null;
        foreach ($refs as $id) {
            $chain = array();
            for ($up = $nodes[$id]['parent']; null !== $up; $up = $nodes[$up]['parent']) $chain[] = $up;
            $level = null === $level ? $chain : array_values(array_intersect($level, $chain));
        }
        $parent = array() === $level ? null : $level[0];
        // A single reference needs a main landmark or a page heading beside it.
        // Plain main wrappers can be flattened during HTML transformation; a
        // heading still identifies their content, unlike incidental shell text.
        while (true) {
            $siblings = null === $parent ? self::topLevelNodeIds($nodes) : $nodes[$parent]['children'];
            $positions = array();
            foreach ($refs as $area => $id) {
                $cursor = $id;
                while ($nodes[$cursor]['parent'] !== $parent) $cursor = $nodes[$cursor]['parent'];
                $positions[$area] = (int) array_search($cursor, $siblings, true);
            }
            $from = isset($positions['header']) ? $positions['header'] + 1 : 0;
            $to = isset($positions['footer']) ? $positions['footer'] - 1 : count($siblings) - 1;
            // A footer at the end of a sole-child wrapper chain also has an
            // unambiguous content gap, provided other routes prove that frame.
            $chain = $allowInvariantChain && isset($positions['footer']) && !isset($positions['header']) && $positions['footer'] === count($siblings) - 1 && null !== $parent;
            for ($up = $parent; $chain && null !== $up; $up = $nodes[$up]['parent']) {
                $peers = null === $nodes[$up]['parent'] ? self::topLevelNodeIds($nodes) : $nodes[$nodes[$up]['parent']]['children'];
                if (1 !== count($peers) || 'group' !== $nodes[$up]['name']) $chain = false;
            }
            if ($from <= $to && ($chain || count($refs) > 1 || self::nodesHoldPageHeading($markup, $nodes, array_slice($siblings, $from, $to - $from + 1)) || self::nodesHoldMain($markup, $nodes, array_slice($siblings, $from, $to - $from + 1)))) break;
            if (null === $parent || count($refs) > 1) return null;
            $parent = $nodes[$parent]['parent'];
        }
        $start = $nodes[$siblings[$from]]['offset'];
        $end = $nodes[$siblings[$to]]['end'];
        $opening = substr($markup, 0, $start);
        $closing = substr($markup, $end);
        $content = substr($markup, $start, $end - $start);
        if ('' === trim($content) || str_contains($content, 'wp:template-part')) return null;
        if (preg_match('/"tagName":"main"/', $opening . $closing)) return null;
        // A frame belongs to one captured device document. When the shells sit
        // in one of several device documents, the others are page content, not
        // chrome around it.
        preg_match_all('/' . '(?:' . preg_quote(EngineMarker::DEVICE_SCOPE_ATTRIBUTE, '/') . '|' . preg_quote(CaptureEvidence::DEVICE_DOCUMENT, '/') . ')' . '(?:":"|=")([^"]+)"/', $opening . $closing, $documents);
        if (count(array_unique($documents[1])) > 1) return null;
        return array('opening' => $opening, 'closing' => $closing, 'content' => $content);
    }

    /**
     * Block tree of a markup string: every block with its offsets, parent and children.
     *
     * @return array<int,array{name:string,attributes:string,offset:int,end:int,parent:?int,children:array<int,int>}>
     */
    private static function blockNodes(\Automattic\BlocksEngine\PhpTransformer\Support\BlockMarkupIndex $index): array
    {
        $markup = $index->markup;
        $nodes = array(); $stack = array();
        foreach ($index->tokens() as $token) {
            $end = $token['offset'] + strlen($token['token']);
            if ($token['closing']) {
                $open = array_pop($stack);
                if (null === $open) return array();
                $nodes[$open]['end'] = $end;
                continue;
            }
            $id = count($nodes);
            $parent = array() === $stack ? null : $stack[count($stack) - 1];
            $nodes[$id] = array('name' => $token['name'], 'attributes' => $token['attributes'], 'offset' => $token['offset'], 'end' => $end, 'parent' => $parent, 'children' => array());
            if (null !== $parent) $nodes[$parent]['children'][] = $id;
            if (!$token['self_closing']) $stack[] = $id;
        }
        return array() === $stack ? $nodes : array();
    }

    /** @param array<int,array<string,mixed>> $nodes @return array<int,int> */
    private static function topLevelNodeIds(array $nodes): array
    {
        return array_keys(array_filter($nodes, static fn(array $node): bool => null === $node['parent']));
    }

    /** @param array<int,array<string,mixed>> $nodes @param array<int,int> $ids */
    private static function nodesHoldMain(string $markup, array $nodes, array $ids): bool
    {
        foreach ($ids as $id) if (preg_match('/"tagName":"main"/', substr($markup, $nodes[$id]['offset'], $nodes[$id]['end'] - $nodes[$id]['offset']))) return true;
        return false;
    }

    /** @param array<int,array<string,mixed>> $nodes @param array<int,int> $ids */
    private static function nodesHoldPageHeading(string $markup, array $nodes, array $ids): bool
    {
        foreach ($ids as $id) if (preg_match('/<h1\\b/i', substr($markup, $nodes[$id]['offset'], $nodes[$id]['end'] - $nodes[$id]['offset']))) return true;
        return false;
    }

    /** Whether a recognized occurrence has an actual block-tree ancestor. */
    private function hasLayoutAncestor(\Automattic\BlocksEngine\PhpTransformer\Support\BlockMarkupIndex $index, array $candidate, string $area): bool
    {
        $markup = $index->markup;
        if (empty($candidate['nested_shell'])) return false;
        $roots = array();
        foreach ($index->topLevel() as $range) $roots[$range['offset'] . ':' . $range['length']] = true;
        if (isset($candidate['shared_leading'])) return !isset($roots[$candidate['offset'] . ':' . $candidate['length']]);
        foreach ($this->nestedShellRanges($markup, $candidate, $area) as $range) {
            if (!isset($roots[$range['offset'] . ':' . $range['length']])) return true;
        }
        return false;
    }

    /** Replace the exact shell occurrence without changing its layout ancestry. */
    private function replaceShellInPlace(string $markup, array $candidate, string $area): ?string
    {
        $reference = '<!-- wp:template-part {"slug":"' . $area . '","area":"' . $area . '","tagName":"div","className":"blocks-engine-shell-transport"} /-->';
        if (!empty($candidate['nested_shell'])) return $this->withoutNestedShell($markup, $candidate, $reference);
        $range = $this->topLevelShellRange(new \Automattic\BlocksEngine\PhpTransformer\Support\BlockMarkupIndex($markup), $area, $candidate['markup'], $candidate['offset'] ?? null);
        if (null === $range) return null;
        return substr($markup, 0, $range['offset']) . $reference . substr($markup, $range['offset'] + $range['length']);
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
        $documentIndex = null;
        foreach ($declarations as $declarationIndex => $declaration) foreach ($this->entities($declaration) as $entityIndex => $entity) foreach (is_array($entity) ? ($entity['bindings'] ?? array()) : array() as $bindingIndex => $binding) {
            // A binding stored as a manifest record is not rewritten here, so
            // chrome holding one stays page-owned rather than moving.
            $movable = RuntimeEntityManifest::SCHEMA !== ($declaration['payload']['schema'] ?? null);
            $position = $binding['position'] ?? null;
            if (($binding['source_path'] ?? null) !== ($page['source_path'] ?? null)) continue;
            $search = $binding['search_block_markup'] ?? null;
            if (!is_string($search) || '' === $search) continue;
            $documentIndex ??= new \Automattic\BlocksEngine\PhpTransformer\Support\BlockMarkupIndex($page['canonical_block_markup']);
            $block = WordPressSitePlan::bindingPosition($position, $documentIndex, $search) ? ($documentIndex->ranges()[$position['block_index']] ?? null) : null;
            foreach ($ranges as $range) {
                if (is_array($block) && $block['offset'] >= $range['offset'] && $block['offset'] + $block['length'] <= $range['offset'] + $range['length']) {
                    if ($movable) $refs[] = array('declaration' => $declarationIndex, 'entity' => $entityIndex, 'binding' => $bindingIndex, 'offset' => $block['offset'], 'range' => $range);
                    else $blocked = true;
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
                $key = EntityEquivalence::key($declarations[$ref['declaration']]['payload']['entities'][$ref['entity']], (string) ($declarations[$ref['declaration']]['type'] ?? ''));
                if (null === $key) return null;
                $pageKeys[] = $key;
            }
            if (null !== $keys && $keys !== $pageKeys) return null;
            $keys = $pageKeys;
        }
        $keep = array();
        $partRanges = (new \Automattic\BlocksEngine\PhpTransformer\Support\BlockMarkupIndex($partMarkup))->ranges();
        $pageMarkup = $pages[$canonical]['canonical_block_markup'];
        $pageRanges = (new \Automattic\BlocksEngine\PhpTransformer\Support\BlockMarkupIndex($pageMarkup))->ranges();
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
        $index = new \Automattic\BlocksEngine\PhpTransformer\Support\BlockMarkupIndex($page['canonical_block_markup']);
        foreach ($declarations as $declaration) foreach ($this->entities($declaration) as $entity) foreach (is_array($entity) ? ($entity['bindings'] ?? array()) : array() as $binding) {
            $position = $binding['position'] ?? null;
            if (($binding['source_path'] ?? null) !== ($page['source_path'] ?? null)) continue;
            $search = $binding['search_block_markup'] ?? null;
            if (!is_string($search) || !WordPressSitePlan::bindingPosition($position, $index, $search)) continue;
            $range = $index->ranges()[$position['block_index']] ?? null;
            if (is_array($range) && ($range['offset'] < $offset || $range['offset'] + $range['length'] > $offset + $length)) return true;
        }
        return false;
    }

    /** @param array<int,array<int,array<string,mixed>>> $candidates @return array<string,mixed> */
    private function shellProvenance(string $area, string $decision, string $reason, array $candidates = array(), ?string $identity = null): array
    {
        $sources = array();
        foreach ($candidates as $rows) foreach ($rows as $candidate) if (is_array($candidate) && is_string($candidate['source_path'] ?? null)) $sources[$candidate['source_path']] = ArrayField::string($candidate, 'source_hash');
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
            $templateSlugs = WordPressSitePlan::NATIVE_TEMPLATE_SLUGS;
        } else {
            $templateSlugs = WordPressSitePlan::NATIVE_QUERY_TEMPLATE_SLUGS;
            foreach ($applicable as $index => $page) {
                $selected = isset($withoutShells[$index]);
                if (!empty($page['entrypoint'])) { if ($selected) $templateSlugs[] = 'front-page'; continue; }
                // The generic fallback templates always carry the dominant
                // shell; excluded routes get their own override templates.
                $templateSlugs[] = 'page' === ($page['post_type'] ?? null) ? 'page' : 'single';
                if (!$selected) {
                    $slug = WordPressSitePlan::routeTemplateKey($page);
                    if (isset($overrides[$slug])) return null;
                    $overrides[$slug] = $index;
                }
            }
            $templateSlugs = array_values(array_unique($templateSlugs));
            $excludedTemplateSlugs = array_keys($overrides);
        }
        foreach ($runtimeDeclarations as &$declaration) unset($declaration['reconciliation_identity'], $declaration['payload_hash'], $declaration['content_hash']);
        unset($declaration);
        $runtimeDeclarations = RuntimeDeclarations::normalizeForComposition($runtimeDeclarations);
        $sourcePath = 'wordpress-site-plan/shared/' . $area;
        $partMarkup = self::landmarkPartMarkup($absorbed['markup'], $area);
        $ancestorContext = is_array($absorbed['ancestor_context'] ?? null) ? $absorbed['ancestor_context'] : null;
        $provenanceCandidates = array();
        foreach (array_keys($withoutShells) as $index) $provenanceCandidates[$index] = array(array('source_path' => (string) $pages[$index]['source_path'], 'source_hash' => ''));
        $part = array('source_path' => $sourcePath . '#' . $area, 'slug' => $area, 'title' => ucfirst($area), 'post_type' => 'wp_template_part', 'parent_source_path' => '', 'entrypoint' => false, 'area' => $area, 'tag_name' => 'div', 'placement' => array_filter(array('kind' => 'shared_shell', 'source_path' => $sourcePath, 'template_slugs' => $templateSlugs, 'excluded_template_slugs' => $excludedTemplateSlugs), static fn(mixed $value): bool => array() !== $value && null !== $value), 'canonical_block_markup' => $partMarkup, 'metadata' => array(), 'document_metadata' => array('source_context' => array('source_path' => $sourcePath . '#' . $area, 'kind' => 'template_part'), 'title' => ucfirst($area), 'title_declaration' => array('order' => 0, 'placement' => 'head'), 'meta' => array(), 'links' => array(), 'scripts' => array()), 'provenance' => $this->shellProvenance($area, 'extracted', 'responsive_variant_partition', $provenanceCandidates, hash('sha256', implode("\0", $absorbed['identities']))), 'reconciliation_identity' => WordPressSitePlan::identity('template-part', $sourcePath . '#' . $area, 'parts/' . $area . '.html'), 'content_hash' => WordPressSitePlan::contentHash($partMarkup)) + (is_array($ancestorContext) ? array('ancestor_context' => $ancestorContext) : array());
        $diagnostic = array('code' => 'wordpress_site_plan_shell_extracted', 'severity' => 'info', 'message' => "Extracted the dominant semantically equivalent {$area} shell cluster.", 'area' => $area, 'page_count' => count($withoutShells), 'applicable_page_count' => count($applicable), 'exclusions' => array_map(static fn(int $index, string $reason): array => array('source_path' => $pages[$index]['source_path'], 'reason' => $reason), array_keys($excluded), $excluded));
        return array('pages' => $withoutShells, 'part' => $part, 'diagnostic' => $diagnostic, 'runtime_declarations' => $runtimeDeclarations);
    }

    private function retainsResponsiveVariantLandmark(string $markup, string $area): bool
    {
        if (1 !== preg_match('/(?:' . preg_quote(CaptureEvidence::ATTRIBUTE_PREFIX, '/') . '[a-z][a-z0-9_-]{0,63}-document|' . preg_quote(CaptureEvidence::DESKTOP_ONLY_CLASS, '/') . '|' . preg_quote(CaptureEvidence::MOBILE_ONLY_CLASS, '/') . '|site-document-variant-[a-z][a-z0-9_-]{0,31})/', $markup)) {
            return false;
        }
        return array() !== $this->nestedLandmarkCandidates(new \Automattic\BlocksEngine\PhpTransformer\Support\BlockMarkupIndex($markup), '', $area);
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
            $sourceIdentities = $pages[$index]['_source_shared_variant_identities'][$area] ?? array();
            foreach ($scoped as &$rows) {
                foreach ($rows as &$row) $row = self::withVariantSourceIdentity($row, $sourceIdentities);
                unset($row);
            }
            unset($rows);
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
        $backdropIds = array();
        $staticStartIds = array();
        $sourceIndex = $dominant['indexes'][0];
        foreach ($variantClasses as $class) {
            $scoped = $byPage[$sourceIndex][$class];
            $candidate = $candidates[$sourceIndex][0] ?? null;
            $owned = is_array($candidate) && $this->candidateOwnsVariant($candidate, $scoped);
            $inner = self::withoutCurrentNavigationState($scoped[0]['template_part_markup'] ?? ($owned ? (string) $candidate['markup'] : (string) $scoped[0]['markup']));
            if ('' === trim($inner)) return false;
            if (null === $primaryContext && 0 === self::responsiveVariantRank($class)) $primaryContext = $scoped[0]['ancestor_context'] ?? null;
            foreach ((array) ($scoped[0]['ancestor_context']['backdrop_ids'] ?? array()) as $id) $backdropIds[(string) $id] = true;
            foreach ((array) ($scoped[0]['ancestor_context']['static_start_ids'] ?? array()) as $id) $staticStartIds[(string) $id] = true;
            $scopeClasses = $scoped[0]['ancestor_context']['variant_classes'][$class] ?? array($class);
            $pieces[$class] = self::variantVisibilityGroup(implode(' ', $scopeClasses), $inner, $scoped[0]['ancestor_context']['variant_shells'][$class] ?? null);
        }
        if (null === $primaryContext) {
            $firstClass = $variantClasses[0];
            $primaryContext = $byPage[array_key_first($byPage)][$firstClass][0]['ancestor_context'] ?? null;
        }
        $cleaned = array();
        foreach (array_keys($kept) as $index) {
            $fromOriginal = !empty($cleanOriginal[$index]);
            $markup = $this->stripMatchingVariantLandmarks($fromOriginal ? $pages[$index]['canonical_block_markup'] : $withoutShells[$index], $area, $identities, $fromOriginal, $pages[$index]['_source_shared_variant_identities'][$area] ?? array());
            if (null === $markup || $this->retainsResponsiveVariantLandmark($markup, $area)) return false;
            $cleaned[$index] = $markup;
        }
        // The part renders every variant, so the backdrop ancestors of each
        // variant document are detached from it.
        $context = is_array($primaryContext) ? $primaryContext : array();
        if (array() !== $backdropIds) $context['backdrop_ids'] = array_map('strval', array_keys($backdropIds));
        if (array() !== $staticStartIds) $context['static_start_ids'] = array_map('strval', array_keys($staticStartIds));
        return array('pages' => $cleaned, 'markup' => implode("\n", $pieces), 'identities' => $identities, 'ancestor_context' => $context);
    }

    /**
     * @param array<string,string> $identities
     */
    private function stripMatchingVariantLandmarks(string $markup, string $area, array $identities, bool $requireAll, array $sourceIdentities = array()): ?string
    {
        $rows = $this->nestedLandmarkCandidates(new \Automattic\BlocksEngine\PhpTransformer\Support\BlockMarkupIndex($markup), '', $area);
        $remove = array();
        $present = array();
        $scopeless = array() !== $rows;
        foreach ($rows as $row) if (null !== self::responsiveVariantClass($row)) { $scopeless = false; break; }
        if ($scopeless) {
            // A plain document carries no capture-document scopes (as when one
            // viewport capture fails). It is adopted when its landmark equals
            // the rank-0 (primary) variant identity.
            $classes = array_keys($identities);
            usort($classes, static fn(string $left, string $right): int => self::responsiveVariantRank($left) <=> self::responsiveVariantRank($right) ?: strcmp($left, $right));
            $primary = array() === $classes ? null : $identities[$classes[0]];
            foreach ($rows as $row) {
                $edge = $this->routeEdge(new \Automattic\BlocksEngine\PhpTransformer\Support\BlockMarkupIndex($markup), array(), $area, array());
                $at = $this->edgeLandmarkIndex($markup, $edge, $row['markup']);
                if (null !== $at) {
                    $range = self::edgeRange($edge, $at + 1);
                    $edgeMarkup = substr($markup, $range['offset'], $range['length']);
                    if (self::captureEdgeIdentity((string) $primary) === self::captureEdgeIdentity($edgeMarkup)) $row = array_replace($row, $range, array('markup' => $edgeMarkup, 'identity_markup' => $primary));
                }
                if (null === $primary || $primary !== $row['identity_markup']) return null;
                $remove[] = $row;
            }
        } else {
            foreach ($rows as $row) {
                $row = self::withVariantSourceIdentity($row, $sourceIdentities);
                $class = self::responsiveVariantClass($row);
                if (null === $class) return null;
                if (!isset($identities[$class]) || $identities[$class] !== $row['identity_markup']) return null;
                $present[$class] = true;
                $remove[] = $row;
            }
            if ($requireAll) foreach (array_keys($identities) as $class) if (!isset($present[$class])) return null;
        }
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
        foreach ($this->nestedLandmarkCandidates(new \Automattic\BlocksEngine\PhpTransformer\Support\BlockMarkupIndex($page['canonical_block_markup']), '', $area) as $row) {
            if (null === self::responsiveVariantClass($row)) continue;
            if ($this->shellContainsRuntimeBinding($runtimeDeclarations, $page, $row['offset'], $row['length'])) return true;
        }
        return false;
    }

    /** @return array<string,array<int,array<string,mixed>>>|null */
    private function scopedVariantLandmarks(string $markup, string $area): ?array
    {
        $scoped = array();
        foreach ($this->nestedLandmarkCandidates(new \Automattic\BlocksEngine\PhpTransformer\Support\BlockMarkupIndex($markup), '', $area) as $row) {
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
        $variantScopes = $row['ancestor_context']['variant_classes'] ?? array();
        if (is_array($variantScopes) && array() !== $variantScopes) return (string) array_key_last($variantScopes);
        foreach ($classes as $class) if (self::isResponsiveVariantClass((string) $class)) return (string) $class;
        return null;
    }

    public static function isResponsiveVariantClass(string $class): bool
    {
        return CaptureEvidence::isDeviceDocumentClass($class)
            || CaptureEvidence::isDeviceIslandClass($class)
            || 1 === preg_match('/^site-document-variant-[a-z][a-z0-9_-]{0,31}$/', $class);
    }

    private static function responsiveVariantRank(string $class): int
    {
        if (in_array($class, array(CaptureEvidence::DESKTOP_DOCUMENT_CLASS, CaptureEvidence::DESKTOP_ONLY_CLASS, 'site-document-variant-default'), true)) return 0;
        if (in_array($class, array(CaptureEvidence::MOBILE_DOCUMENT_CLASS, CaptureEvidence::MOBILE_ONLY_CLASS), true)) return 1;
        return 2;
    }

    /**
     * A hoisted variant's device wrapper: its device document, nested
     * device-only gate or root device island. Its rules and visibility
     * CSS select the device attributes, so a class-only group would detach
     * every device-gated rule from the part.
     *
     * @param array{block:string,wrapper:array{tagName:string,attributes:array<string,mixed>}}|null $shell
     */
    private static function variantVisibilityGroup(string $variantClass, string $innerMarkup, ?array $shell = null): string
    {
        if (null !== $shell) {
            foreach (array_merge(array($shell), array_reverse($shell['outer'] ?? array())) as $wrap) {
                $tag = $wrap['wrapper']['tagName'];
                $opening = '<' . $tag;
                foreach ($wrap['wrapper']['attributes'] as $name => $value) {
                    $opening .= true === $value ? ' ' . $name . '=""' : ' ' . $name . '="' . htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '"';
                }
                $innerMarkup = '<!-- wp:' . $wrap['block'] . ' ' . BlockAttributes::encode(array('wrappers' => array($wrap['wrapper']))) . ' -->' . $opening . '>' . $innerMarkup . '</' . $tag . '><!-- /wp:' . $wrap['block'] . ' -->';
            }
            return $innerMarkup;
        }
        $class = htmlspecialchars($variantClass, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $attrs = json_encode(array('className' => $variantClass), JSON_UNESCAPED_SLASHES);
        return '<!-- wp:group ' . $attrs . ' -->' . "\n" . '<div class="wp-block-group ' . $class . '">' . $innerMarkup . '</div>' . "\n" . '<!-- /wp:group -->';
    }

    /**
     * The layout-shell wrapper that carries a capture device document or a
     * root device island (desktop-only/mobile-only), as a
     * shared part may repeat it: its tag, classes and device attributes. The
     * document id and scope marker stay with the page's own document.
     *
     * @param array<string,mixed> $attrs
     * @return array{block:string,wrapper:array{tagName:string,attributes:array<string,mixed>}}|null
     */
    private static function deviceDocumentShell(string $blockName, array $attrs, string $scopeClass): ?array
    {
        if (!str_ends_with($blockName, '/layout-shell')) return null;
        foreach ((array) ($attrs['wrappers'] ?? array()) as $wrapper) {
            $attributes = is_array($wrapper) && is_array($wrapper['attributes'] ?? null) ? $wrapper['attributes'] : array();
            if (!in_array($scopeClass, preg_split('/\s+/', trim((string) ($attributes['class'] ?? ''))) ?: array(), true)) continue;
            $kept = array_intersect_key($attributes, array_flip(array('class', EngineMarker::DEVICE_SCOPE_ATTRIBUTE, CaptureEvidence::DEVICE_DOCUMENT)));
            // A device island's class is itself the gate its visibility CSS selects.
            if (!isset($kept[EngineMarker::DEVICE_SCOPE_ATTRIBUTE]) && !isset($kept[CaptureEvidence::DEVICE_DOCUMENT]) && !CaptureEvidence::isDeviceIslandClass($scopeClass)) return null;
            return array('block' => $blockName, 'wrapper' => array('tagName' => is_string($wrapper['tagName'] ?? null) ? $wrapper['tagName'] : 'div', 'attributes' => $kept));
        }
        return null;
    }

    /** @param array<string,mixed> $candidate */
    private function withoutNestedShell(string $markup, array $candidate, string $replacement = ''): ?string
    {
        $identity = (string) ($candidate['identity_markup'] ?? '');
        $area = (string) ($candidate['area'] ?? '');
        $matches = array();
        if ('' !== $identity && '' !== $area) {
            foreach ($this->nestedLandmarkCandidates(new \Automattic\BlocksEngine\PhpTransformer\Support\BlockMarkupIndex($markup), (string) ($candidate['source_path'] ?? ''), $area) as $row) {
                if ($identity === ($row['identity_markup'] ?? null)) $matches[] = $row;
            }
            if (array() === $matches) {
                $headers = array();
                $footers = array();
                $index = new \Automattic\BlocksEngine\PhpTransformer\Support\BlockMarkupIndex($markup);
                $this->collectNestedChrome($index, $index->topLevel(), $headers, $footers);
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
            $leading = $candidate['shared_leading'] ?? null;
            if (is_array($leading)) {
                $box = $this->nestedShellRange($markup, $leading['markup'], $candidate['offset']);
                if (null === $box) return array();
                $shift = $box['offset'] - $candidate['offset'];
                // Unwrap the landmark as its chrome leaves, retaining native route blocks.
                return array(
                    array('offset' => $box['offset'], 'length' => $leading['end'] - $candidate['offset']),
                    array('offset' => $leading['closing'] + $shift, 'length' => $candidate['offset'] + $candidate['length'] - $leading['closing']),
                );
            }
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
        $range = $this->topLevelShellRange(new \Automattic\BlocksEngine\PhpTransformer\Support\BlockMarkupIndex($markup), $area, (string) ($candidate['markup'] ?? ''), isset($candidate['offset']) && is_int($candidate['offset']) ? $candidate['offset'] : null);
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
        $range = $this->topLevelShellRange(new \Automattic\BlocksEngine\PhpTransformer\Support\BlockMarkupIndex($markup), $area, $candidateMarkup, $offset);
        return is_array($range) ? substr($markup, 0, $range['offset']) . $replacement . substr($markup, $range['offset'] + $range['length']) : null;
    }

    /**
     * @return array{offset:int,length:int}|null
     */
    private function topLevelShellRange(\Automattic\BlocksEngine\PhpTransformer\Support\BlockMarkupIndex $index, string $area, string $candidateMarkup = '', ?int $offset = null): ?array
    {
        $markup = $index->markup;
        $tokens = array_column($index->tokens(), null, 'offset');
        if ('' !== $candidateMarkup) {
            // Trust an already-known block-tree position over a fresh search: a
            // byte-identical top-level sibling elsewhere in the page must not
            // manufacture ambiguity for a candidate whose position is already
            // structurally proven.
            if (null !== $offset && $offset >= 0 && $candidateMarkup === substr($markup, $offset, strlen($candidateMarkup))) {
                return array('offset' => $offset, 'length' => strlen($candidateMarkup));
            }
            $matches = array();
            foreach ($index->topLevel() as $range) if ($candidateMarkup === substr($markup, $range['offset'], $range['length'])) $matches[] = $range;
            if (1 === count($matches)) return $matches[0];
            if (1 < count($matches)) return null;
        }
        $candidate = null;
        foreach ($index->topLevel() as $range) {
            $token = $tokens[$range['offset']] ?? null;
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

    private static function isGroupBlock(string $markup): bool
    {
        if (1 !== preg_match('/^<!--\s*wp:group(?:\s+(\{.*?\}))?\s*-->/s', ltrim($markup), $match)) return false;
        // Runtime-owned dialog boundaries were opaque companion blocks before
        // their contents became core Groups. They are still not layout shells.
        return !isset((json_decode($match[1] ?? '{}', true) ?: array())['metadata']['blocksEngineDialog']);
    }

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

    /** An element named footer by one of its classes or by its id. */
    private static function footerAreaFromName(string $className, string $anchor): ?string
    {
        // Non-primary document ids carry an engine suffix; it is not part
        // of the authored landmark name (block JSON has already been decoded).
        $anchor = preg_replace('/' . preg_quote(CaptureEvidence::VARIANT_ID_SUFFIX_PREFIX, '/') . '[a-z][a-z0-9_-]*$/', '', $anchor) ?? $anchor;
        foreach (preg_split('/\s+/', trim($className . ' ' . $anchor)) ?: array() as $class) {
            if (in_array(strtolower($class), array('footer', 'site-footer', 'widget-footer', 'colophon', 'site-info'), true)) return 'footer';
        }
        return null;
    }

    /** @param array<string,mixed> $candidate @param array<string,mixed> $current */
    private static function prefersScrollStateCarrier(array $candidate, array $current): bool
    {
        return str_contains((string) ($candidate['markup'] ?? ''), '/scroll-state') && !str_contains((string) ($current['markup'] ?? ''), '/scroll-state');
    }

    /**
     * Wordmark evidence is cross-route, not a class-name convention. Only the
     * comparison copy ignores route state; the emitted leaf is a live site title.
     */
    private static function withWordmarkRouteState(array $pages, array $assets): array
    {
        $properties = array('color', 'text-decoration', 'text-decoration-line', 'font-size', 'font-family');
        $analysis = (new \Automattic\BlocksEngine\PhpTransformer\Css\CssRuleAnalyzer())->analyze(
            array_values(array_filter($assets, static fn(array $asset): bool => 'css' === ($asset['kind'] ?? null))),
            '', $properties, 4000000, 50000, 100000, 16
        );
        if ($analysis['truncated']) return $pages;
        $entry = null;
        foreach ($pages as $page) if (!empty($page['entrypoint'])) $entry = $page['route']['path'] ?? '/';
        if (null === $entry) return $pages;
        $subjectIndex = \Automattic\BlocksEngine\PhpTransformer\Css\CssSubjectIndex::build($analysis['rules']);
        $fragments = array();
        $evidence = array(); $applicable = 0;
        foreach ($pages as $index => $page) {
            if (!empty($page['synthetic'])) continue;
            ++$applicable;
            foreach ($page['shell_candidates'] ?? array() as $candidateIndex => $candidate) {
                // A bundled part has multiple presentation owners; rewriting
                // just its primary range would discard the other device scopes.
                if ('header' !== $candidate['area'] || !empty($candidate['device_document_bundle'])) continue;
                $markup = $candidate['rest_markup'] ?? $candidate['markup'];
                $nodes = self::blockNodes(new \Automattic\BlocksEngine\PhpTransformer\Support\BlockMarkupIndex($markup));
                foreach ($nodes as $node) {
                    if (!in_array($node['name'], array('group', 'heading', 'paragraph'), true)) continue;
                    $fragment = substr($markup, $node['offset'], $node['end'] - $node['offset']);
                    if (array_key_exists($fragment, $fragments)) {
                        if (false === $fragments[$fragment]) continue;
                        [$text, $classes, $styles, $links, $presentation, $valid] = $fragments[$fragment];
                    } else {
                        $fragments[$fragment] = false;
                        $dom = new \DOMDocument();
                        @$dom->loadHTML('<?xml encoding="UTF-8"><body>' . $fragment . '</body>', LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
                        $body = $dom->getElementsByTagName('body')->item(0);
                        if (null === $body) continue;
                        $text = trim(preg_replace('/\s+/u', ' ', $body->textContent) ?? '');
                        if ('' === $text) continue;
                        $classes = array(); $styles = array(); $links = array(); $valid = true;
                        $presentation = array('text-decoration-line' => 'none');
                        foreach ($body->getElementsByTagName('*') as $element) {
                            if (!in_array($element->tagName, array('div', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'p', 'a'), true)) { $valid = false; break; }
                            // A wordmark is a single text leaf, possibly wrapped in a
                            // logo container. Never swallow sibling copy or navigation.
                            $children = 0;
                            foreach ($element->childNodes as $child) if ($child instanceof \DOMElement || ($child instanceof \DOMText && '' !== trim($child->textContent))) ++$children;
                            if ($children > 1) { $valid = false; break; }
                            foreach (preg_split('/\s+/', trim($element->getAttribute('class'))) ?: array() as $class) {
                                if ('' !== $class && !str_starts_with($class, 'wp-block-') && !str_starts_with($class, 'blocks-engine-synthetic-') && !str_starts_with($class, 'blocks-engine-propagated-') && !preg_match('/^has-(?:.+-font-(?:size|family)|text-color|.+-color)$/', $class)) $classes[$class] = true;
                            }
                            foreach (explode(';', $element->getAttribute('style')) as $style) {
                                $style = trim($style);
                                // Bare links are lowered to a zero-margin paragraph;
                                // headings can materialize their default bold weight.
                                if ('font-weight:700' !== $style && '' !== $style && !preg_match('/^(?:color|text-decoration(?:-line)?|font-size|font-family)\s*:/', $style) && !preg_match('/^margin-(?:top|right|bottom|left):0$/', $style)) $styles[$style] = true;
                            }
                            $presentation = self::wordmarkPresentation($element, $presentation, $subjectIndex, $properties);
                            if ('a' === $element->tagName) $links[] = html_entity_decode($element->getAttribute('href'));
                        }
                        ksort($classes); ksort($styles);
                        $fragments[$fragment] = array($text, $classes, $styles, $links, $presentation, $valid);
                    }
                    if (!$valid || count($links) > 1 || (!empty($links) && $links[0] !== $entry) || (empty($page['entrypoint']) && array($entry) !== $links)) continue;
                    $signature = hash('sha256', json_encode(array($text, array_keys($classes), array_keys($styles), $presentation)));
                    $leaf = $node;
                    while (1 === count($leaf['children'])) $leaf = $nodes[$leaf['children'][0]];
                    $attrs = json_decode($leaf['attributes'], true) ?: array();
                    unset($attrs['level'], $attrs['content']);
                    $attrs['level'] = 0;
                    $attrs['isLink'] = true;
                    $attrs['metadata']['blocksEngineWordmark'] = true;
                    foreach (array('font-size' => 'fontSize', 'font-family' => 'fontFamily', 'text-decoration-line' => 'textDecoration') as $property => $attribute) {
                        if (isset($presentation[$property])) {
                            unset($attrs[$attribute]);
                            $attrs['style']['typography'][$attribute] = $presentation[$property];
                        }
                    }
                    if (isset($presentation['color'])) {
                        $color = json_decode($presentation['color'], true);
                        $attrs['style']['color']['text'] = is_array($color)
                            ? sprintf('rgba(%s,%s,%s,%s)', ...$color) : $presentation['color'];
                        unset($attrs['textColor']);
                    }
                    // Core's link must not acquire the theme's generic anchor
                    // colour or underline after moving presentation to the title.
                    $attrs['style']['elements']['link']['color']['text'] = 'inherit';
                    $attrs['style']['elements']['link']['typography']['textDecoration'] = $presentation['text-decoration-line'] ?? 'none';
                    $evidence[$signature][$index][] = array('candidate' => $candidateIndex, 'offset' => $node['offset'], 'length' => $node['end'] - $node['offset'], 'leaf_offset' => $leaf['offset'], 'leaf_length' => $leaf['end'] - $leaf['offset'], 'attributes' => $attrs, 'entrypoint_heading' => !empty($page['entrypoint']) && 'heading' === $leaf['name']);
                }
            }
        }
        foreach ($evidence as $signature => $routes) {
            if ($applicable < 2 || count($routes) !== $applicable) continue;
            // Ambiguous repeated labels are not structural wordmark evidence.
            if (array_filter($routes, static fn(array $matches): bool => 1 !== count($matches))) continue;
            // Every copy of the shared part carries the entrypoint's evidence,
            // irrespective of which route supplies the extracted template part.
            $entrypointHeading = false;
            foreach ($routes as $matches) if ($matches[0]['entrypoint_heading']) $entrypointHeading = true;
            foreach ($routes as $index => $matches) {
                $match = $matches[0];
                $attrs = $match['attributes'];
                $attrs['metadata']['blocksEngineWordmarkHeading'] = $entrypointHeading;
                $replacement = '<!-- wp:site-title ' . BlockAttributes::encode($attrs) . ' /-->';
                $candidate = &$pages[$index]['shell_candidates'][$match['candidate']];
                $markup = $candidate['rest_markup'] ?? $candidate['markup'];
                $identity = substr_replace($markup, '<!-- shared-wordmark:' . $signature . ' -->', $match['offset'], $match['length']);
                $candidate['identity_markup'] = self::normalizeNestedChromeMarkup($identity);
                $candidate['wordmark_route_state'] = true;
                $part = substr_replace($markup, $replacement, $match['leaf_offset'], $match['leaf_length']);
                $candidate['template_part_markup'] = self::landmarkPartMarkup(self::withoutCurrentNavigationState($part), 'header');
                unset($candidate);
            }
        }
        return $pages;
    }

    /**
     * A menu can mark the served route's link with nothing but a class swap:
     * no aria-current, no current/active token. Cross-route evidence names it
     * anyway: a link's look on the routes it does not name is its resting
     * presentation. The comparison and part copies give the link naming the
     * served route that resting look; the navigation keeps its current-item
     * markers, so WordPress paints whichever item is current per request.
     *
     * A submenu whose descendant link names the served route is its current
     * ancestor and can carry an active look of its own; on the routes outside
     * it, the same submenu rests, so it is given that resting look too. Links
     * and submenus are compared by area, label, URL and occurrence, so a menu
     * repeated in one header (a bar and its drawer) keeps each copy's look.
     * Resting looks are read among candidates of one design, the same markup
     * apart from classes and route numbers, so a route with another header
     * does not contradict the evidence of the routes that share one.
     *
     * @param array<int,array<string,mixed>> $pages
     * @return array<int,array<string,mixed>>
     */
    private static function withRouteCurrentLinksAtRest(array $pages): array
    {
        $item = '/<!--\s*(\/)?wp:navigation-(link|submenu)(?:\s+(\{.*?\}))?\s*(\/)?-->/s';
        $isMarker = static fn (string $class): bool => 1 === preg_match('/^blocks-engine-(?:current-navigation-|navigation-current-)/', $class);
        $classes = static fn (array $attrs): array => array_values(array_filter(preg_split('/\s+/', trim((string) ($attrs['className'] ?? ''))) ?: array()));
        $atRest = static function (array $attrs) use ($isMarker, $classes): array {
            $kept = array_values(array_filter($classes($attrs), static fn (string $class): bool => !$isMarker($class)));
            if (array() === $kept) unset($attrs['className']); else $attrs['className'] = implode(' ', $kept);
            return $attrs;
        };
        $names = static fn (array $attrs, string $route): bool => is_string($attrs['url'] ?? null) && !str_contains($attrs['url'], '#')
            && ('' === ($path = rtrim(explode('?', $attrs['url'])[0], '/')) ? '/' : $path) === $route;
        // An item's attributes as the shell identity sees them, without
        // generated markers (a label's per-document semantic class).
        $asIdentity = static fn (array $attrs): array => json_decode(preg_replace('/^<!-- wp:navigation-submenu (.*) -->$/s', '$1', self::normalizeNestedChromeMarkup('<!-- wp:navigation-submenu ' . BlockAttributes::encode($attrs) . ' -->')) ?? '', true) ?: array();
        // Each menu item of a candidate, in order: its key, attributes, comment
        // range, and whether it names the route or (a submenu) holds one that does.
        $items = static function (string $markup, string $area, string $route) use ($item, $names, $asIdentity): array {
            preg_match_all($item, $markup, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);
            $rows = array(); $open = array(); $seen = array();
            foreach ($matches as $match) {
                if ('' !== ($match[1][0] ?? '')) {
                    array_pop($open);
                    continue;
                }
                $attrs = json_decode($match[3][0] ?? '', true);
                if (!is_array($attrs)) $attrs = array();
                $identity = json_encode(array($match[2][0], isset($attrs['label']) ? ($asIdentity(array('label' => $attrs['label']))['label'] ?? null) : null, rtrim((string) ($attrs['url'] ?? ''), '/')));
                $seen[$identity] = ($seen[$identity] ?? 0) + 1;
                $current = $names($attrs, $route);
                $rows[] = array('kind' => $match[2][0], 'key' => $area . "\0" . $identity . "\0" . $seen[$identity], 'attrs' => $attrs, 'offset' => $match[0][1], 'length' => strlen($match[0][0]), 'current' => $current, 'holds' => false, 'closed' => '' !== ($match[4][0] ?? ''), 'valid' => isset($match[3][0]) && '' !== $match[3][0] && is_array(json_decode($match[3][0], true)));
                $index = array_key_last($rows);
                if ($current) foreach ($open as $parent) $rows[$parent]['holds'] = true;
                if ('submenu' === $match[2][0] && !$rows[$index]['closed']) $open[] = $index;
            }
            return $rows;
        };
        $design = static fn (array $candidate): string => hash('sha256', (string) preg_replace(array('/"className"\s*:\s*"[^"]*"|\sclass="[^"]*"/', '/[0-9]+/'), array('', '#'), (string) ($candidate['identity_markup'] ?? $candidate['markup'])));
        $resting = array(); $held = array();
        foreach ($pages as $page) {
            if (!empty($page['synthetic'])) continue;
            $route = (string) ($page['route']['path'] ?? '');
            foreach ($page['shell_candidates'] ?? array() as $candidate) {
                foreach ($items($candidate['markup'], $design($candidate) . "\0" . $candidate['area'], $route) as $row) {
                    if (!$row['valid'] || $row['current']) continue;
                    $rest = $atRest($row['attrs']);
                    if ($row['holds']) $held[$row['key']][BlockAttributes::encode($rest)] = $rest;
                    else $resting[$row['key']][BlockAttributes::encode($rest)] = $rest;
                }
            }
        }
        // A submenu holding the served route on every route (an overflow menu
        // listing all items) never rests. Route state only adds classes, to
        // the item or its anchor, so when one observed look is contained in
        // every other, it is the rest. Looks compare as the shell identity
        // sees them, without generated markers.
        $contained = static function (array $look, array $other) use ($classes, $asIdentity): bool {
            $parts = static function (array $attrs) use ($classes, $asIdentity): array {
                $attrs = $asIdentity($attrs);
                $anchor = $classes($attrs['metadata']['blocksEngineNavigationAnchor'] ?? array());
                $item = $classes($attrs);
                unset($attrs['className'], $attrs['metadata']['blocksEngineNavigationAnchor']['className']);
                return array($item, $anchor, $attrs);
            };
            [$lookItem, $lookAnchor, $lookRest] = $parts($look);
            [$otherItem, $otherAnchor, $otherRest] = $parts($other);
            return array() === array_diff($lookItem, $otherItem) && array() === array_diff($lookAnchor, $otherAnchor) && $lookRest === $otherRest;
        };
        foreach ($held as $key => $looks) {
            if (isset($resting[$key]) || count($looks) < 2) continue;
            $base = array_filter($looks, static function (array $look) use ($looks, $contained): bool {
                foreach ($looks as $other) if (!$contained($look, $other)) return false;
                return true;
            });
            if (1 === count($base)) $resting[$key] = $base;
        }
        foreach ($pages as &$page) {
            if (!empty($page['synthetic']) || !is_array($page['shell_candidates'] ?? null)) continue;
            $route = (string) ($page['route']['path'] ?? '');
            foreach ($page['shell_candidates'] as &$candidate) {
                $markup = $candidate['markup'];
                $rested = $markup;
                foreach (array_reverse($items($markup, $design($candidate) . "\0" . $candidate['area'], $route)) as $row) {
                    if (!$row['valid'] || !($row['current'] || ('submenu' === $row['kind'] && $row['holds']))) continue;
                    // Only one resting look is evidence of the item's own presentation.
                    $rest = $resting[$row['key']] ?? array();
                    if (1 !== count($rest)) continue;
                    $rest = reset($rest);
                    // The item keeps its own label; only its markers are per document.
                    if (isset($row['attrs']['label'])) $rest['label'] = $row['attrs']['label'];
                    if ($rest === $atRest($row['attrs'])) continue;
                    $markers = array_values(array_filter($classes($row['attrs']), $isMarker));
                    if (array() !== $markers) $rest['className'] = trim(($rest['className'] ?? '') . ' ' . implode(' ', $markers));
                    $comment = '<!-- wp:navigation-' . $row['kind'] . ' ' . BlockAttributes::encode($rest) . ($row['closed'] ? ' /-->' : ' -->');
                    $rested = substr_replace($rested, $comment, $row['offset'], $row['length']);
                }
                if ($rested === $markup) continue;
                // Recompute only copies this candidate's own derivation reproduces.
                $identity = match ($candidate['identity_markup'] ?? null) {
                    self::normalizeNestedChromeMarkup($markup) => self::normalizeNestedChromeMarkup($rested),
                    self::nestedChromeIdentity($markup) => self::nestedChromeIdentity($rested),
                    default => null,
                };
                if (null === $identity || ($candidate['template_part_markup'] ?? null) !== self::landmarkPartMarkup(self::withoutCurrentNavigationState($markup), $candidate['area'])) continue;
                $candidate['rest_markup'] = $rested;
                $candidate['identity_markup'] = $identity;
                $candidate['template_part_markup'] = self::landmarkPartMarkup(self::withoutCurrentNavigationState($rested), $candidate['area']);
            }
            unset($candidate);
        }
        unset($page);
        return $pages;
    }

    /** Resolve the single text leaf through its wrappers, not block-support placement. */
    private static function wordmarkPresentation(\DOMElement $element, array $inherited, array $subjectIndex, array $properties): array
    {
        $facts = array();
        $rules = array_column(\Automattic\BlocksEngine\PhpTransformer\Css\CssSubjectIndex::entries($element, $subjectIndex), 'rule');
        $rules[] = array('declarations' => \Automattic\BlocksEngine\PhpTransformer\Css\CssRuleAnalyzer::declarations($element->getAttribute('style'), $properties), 'inline' => true, 'specificity' => array(0, 0, 0), 'order' => PHP_INT_MAX);
        foreach ($rules as $rule) {
            if (empty($rule['inline']) && (null !== $rule['condition'] || !\Automattic\BlocksEngine\PhpTransformer\Css\CssSelectorMatcher::matches($element, $rule['parsed_selector'])['matches'])) continue;
            foreach ($rule['declarations'] as $declaration) {
                $value = $declaration['value'];
                $important = (bool) preg_match('/\s*!important\s*$/i', $value);
                $value = trim(preg_replace('/\s*!important\s*$/i', '', $value) ?? $value);
                $property = 'text-decoration' === $declaration['name'] ? 'text-decoration-line' : $declaration['name'];
                \Automattic\BlocksEngine\PhpTransformer\Css\DeclarationCascade::apply($facts, $property, array_merge($rule, array('important' => $important, 'value' => $value)));
            }
        }
        foreach ($facts as $property => $fact) {
            $value = $fact['value'];
            // Output presets retain the captured value as their fallback.
            $value = preg_replace('/var\(--wp--preset--[^,()]+,\s*([^()]+)\)/', '$1', $value) ?? $value;
            if (in_array(strtolower($value), array('inherit', 'unset', 'currentcolor'), true)) continue;
            if ('color' === $property) {
                $color = \Automattic\BlocksEngine\PhpTransformer\Css\CssColor::parse($value);
                if (null !== $color) $value = json_encode($color);
            }
            $inherited[$property] = $value;
        }
        ksort($inherited);
        return $inherited;
    }

    /**
     * Canonicalize route-numbered authored ids only when the same stem varies
     * across candidates and nothing references the ids. References inside the
     * shells (href="#…", aria-controls, for) keep their per-route targets, and
     * a stylesheet selector that names any candidate's id (`#top-202`,
     * `[id="top-202"]`) carries per-route presentation: the shared part keeps
     * one page's id, so the other page would lose the styling written for its
     * own. Stylesheet references are read with the shared-chrome scoping
     * inventory (stylesheetReferencedShellIds), not a second CSS parser.
     *
     * Ids are read from block anchors, rendered `id` attributes and the JSON
     * wrapper attributes of layout shells. Builders number tracking data
     * attributes per route too (`data-track="Logo.Link.75449.click"`); a data
     * value whose digit runs differ on every candidate, with identical text
     * around them, is the same route-numbered handle. Positions are compared
     * among candidates whose numbered values share one stem sequence, so a
     * structurally different candidate (another design) neither blocks nor
     * joins them.
     */
    private static function canonicalizeRouteNumberedShellIds(array $markups, array $stylesheetIds = array('ids' => array(), 'patterns' => array())): array
    {
        $rows = array(); $groups = array();
        foreach ($markups as $index => $markup) {
            preg_match_all('/"anchor"\s*:\s*"([^"]+)"|"id"\s*:\s*"([^"]+)"|\bid="([^"]+)"|"(data-[\w-]+)"\s*:\s*"([^"]*)"|\s(data-[\w-]+)="([^"]*)"/', $markup, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);
            $row = array();
            foreach ($matches as $match) {
                $data = '' !== ($match[4][0] ?? '') ? 4 : ('' !== ($match[6][0] ?? '') ? 6 : null);
                if (null !== $data) {
                    [$value, $offset] = $match[$data + 1];
                    if (!preg_match('/[0-9]/', $value)) continue;
                    $row[] = array('data', $value, $offset, $match[$data][0] . '=' . preg_replace('/[0-9]+/', '#', $value));
                    continue;
                }
                $group = '' !== $match[1][0] ? 1 : ('' !== ($match[2][0] ?? '') ? 2 : 3);
                [$value, $offset] = $match[$group];
                // The route number is the id's one digit run, whether a trailing
                // suffix (top-101, top_101, top101), a builder's instance prefix
                // (n-101-navId-mobile) or the whole id (`id="5"`). The stem keeps
                // its separators, so the placeholder stays a valid id.
                $row[] = array('id', $value, $offset, preg_match('/^([^0-9]*)[0-9]+([^0-9]*)$/', $value, $stem) ? $stem[1] . '#' . $stem[2] : $value);
            }
            $rows[$index] = $row;
            $groups[json_encode(array_column($row, 3))][] = $index;
        }
        $replace = array();
        foreach ($groups as $indexes) {
            if (count($indexes) < 2) continue;
            foreach ($rows[$indexes[0]] as $position => [$kind, , , $stem]) {
                if ($stem === $rows[$indexes[0]][$position][1]) continue;
                // A candidate whose id is referenced keeps it, and so stays out of
                // a part the others share; the rest canonicalize among themselves.
                $variants = array(); $kept = array();
                foreach ($indexes as $index) {
                    $value = $rows[$index][$position][1];
                    if ('data' !== $kind && self::stylesheetTargetsId($value, $stylesheetIds)) continue;
                    if ('data' !== $kind && preg_match('/(?:href\s*=\s*["\']#' . preg_quote($value, '/') . '["\']|(?:aria-controls|for)\s*=\s*["\']' . preg_quote($value, '/') . '["\'])/i', $markups[$index])) continue;
                    $variants[$value] = true;
                    $kept[] = $index;
                }
                if (count($variants) < 2 || ('data' === $kind && count($variants) !== count($indexes))) continue;
                foreach ($kept as $index) $replace[$index][$position] = str_replace('#', '__route__', 'data' === $kind ? substr($stem, strpos($stem, '=') + 1) : $stem);
            }
        }
        foreach ($replace as $index => $positions) {
            krsort($positions);
            $followed = array();
            foreach ($positions as $position => $to) {
                [$kind, $from, $offset] = $rows[$index][$position];
                $markups[$index] = substr_replace($markups[$index], $to, $offset, strlen($from));
                if ('id' === $kind) $followed[] = array($from, $to);
            }
            // A value naming a canonicalized id (a builder's `toggleid`, a
            // dialog's trigger list) is the same route number, so it follows.
            foreach ($followed as [$from, $to]) {
                $markups[$index] = preg_replace('/(="|(?<=[\[,:])")' . preg_quote($from, '/') . '"/', '${1}' . $to . '"', $markups[$index]) ?? $markups[$index];
            }
        }
        return $markups;
    }

    /** @param array<int,array<string,mixed>> $pages @return list<string> */
    private static function pageSourcePaths(array $pages): array
    {
        return array_values(array_filter(array_map(static fn(array $page): string => (string) ($page['source_path'] ?? ''), $pages)));
    }

    /**
     * The id hooks (`#id`, `[id…]`) that stylesheets applying to any of the
     * given pages name. This is the shared-chrome scoping inventory: plan
     * assets already carry their route instances and scopes, and rule preludes
     * are read with the same style-rule visitor the scoping uses.
     *
     * @param array<int,array<string,mixed>> $assets @param list<string> $sourcePaths @return array{ids:array<string,true>,patterns:list<array{0:string,1:string}>}
     */
    private static function stylesheetReferencedShellIds(array $assets, array $sourcePaths): array
    {
        $inventory = array('ids' => array(), 'patterns' => array());
        if (array() === $sourcePaths) return $inventory;
        foreach ($assets as $asset) {
            if ('css' !== ($asset['kind'] ?? null) || !is_string($asset['content'] ?? null) || '' === trim($asset['content']) || !StylesheetActivation::active($asset)) continue;
            if (!self::stylesheetAppliesToPages($asset, $sourcePaths)) continue;
            (new CssStylesheetTransformer())->visitStyleRules($asset['content'], static function (string $prelude) use (&$inventory): void {
                foreach (self::selectorIdReferences($prelude) as $reference) {
                    if (is_string($reference)) $inventory['ids'][$reference] = true;
                    else $inventory['patterns'][] = $reference;
                }
            });
        }
        return $inventory;
    }

    /** Whether a stylesheet asset styles one of the pages: a route instance, a scope naming one, or a global resource. */
    private static function stylesheetAppliesToPages(array $asset, array $sourcePaths): bool
    {
        $instances = array_column($asset['stylesheet_instances'] ?? array(), 'source_path');
        if (array() !== array_intersect($instances, $sourcePaths)) return true;
        $global = false;
        foreach ($asset['scopes'] ?? array() as $scope) {
            if (!is_array($scope)) continue;
            if ('global' === ($scope['kind'] ?? null) || !isset($scope['source_path'])) $global = true;
            elseif (in_array((string) $scope['source_path'], $sourcePaths, true)) return true;
        }
        // A resource with no document occurrence and no page scope loads on every route.
        return $global || (array() === $instances && array() === ($asset['scopes'] ?? array()));
    }

    /**
     * Id references in one selector prelude: `#id` tokens (including inside
     * `:not()` and `:is()`, whose match also depends on the id), and `[id…]`
     * values — word lists for `~=`, match patterns for the substring forms.
     *
     * @return list<string|array{0:string,1:string}>
     */
    private static function selectorIdReferences(string $selector): array
    {
        $references = array();
        foreach ((bool) preg_match_all('/#(-?[_a-zA-Z][\w-]*)/', $selector, $matches) ? $matches[1] : array() as $id) $references[] = (string) $id;
        foreach ((bool) preg_match_all('/\[\s*id\s*([*~^$|]?=)\s*(?:"([^"]*)"|\'([^\']*)\'|([^\]\s]+))\s*[^\]]*\]/i', $selector, $matches, PREG_SET_ORDER) ? $matches : array() as $match) {
            $value = '' !== ($match[2] ?? '') ? (string) $match[2] : ('' !== ($match[3] ?? '') ? (string) $match[3] : (string) ($match[4] ?? ''));
            if ('~=' === $match[1]) {
                foreach (preg_split('/\s+/', trim($value)) ?: array() as $word) if ('' !== $word) $references[] = $word;
            } else {
                $references[] = array($match[1], $value);
            }
        }
        return $references;
    }

    /** Whether the stylesheet inventory names a candidate id: a literal `#id`, a `[id…]` value, or a substring of one. */
    private static function stylesheetTargetsId(string $id, array $inventory): bool
    {
        if (isset($inventory['ids'][$id])) return true;
        foreach ($inventory['patterns'] ?? array() as $pattern) {
            [$operator, $value] = $pattern;
            if ('' === $value) continue;
            if ('^=' === $operator) $matched = str_starts_with($id, $value);
            elseif ('$=' === $operator) $matched = str_ends_with($id, $value);
            elseif ('*=' === $operator) $matched = str_contains($id, $value);
            elseif ('|=' === $operator) $matched = $value === $id || str_starts_with($id, $value . '-');
            else $matched = $value === $id;
            if ($matched) return true;
        }
        return false;
    }

    private static function normalizeNestedChromeMarkup(string $markup): string
    {
        // Emission-only landmark transport must not prevent producer/capture matching.
        $markup = str_replace(array(',"className":"blocks-engine-shell-landmark"', ' blocks-engine-shell-landmark'), '', $markup);
        $markup = self::withoutCurrentNavigationState($markup, true);
        $markup = self::normalizeRenderIds($markup);
        $markup = self::withoutScrollStateCarrierIdentity($markup);
        $markup = preg_replace('/\s*' . EngineMarker::patternBody() . '/', '', $markup) ?? $markup;
        $markup = preg_replace('/\s*(?:' . EngineMarker::specificityPatternBody('specificity-class') . '|blocks-engine-disclosure-summary-[a-f0-9]{6,}(?:-\d+)?)/', '', $markup) ?? $markup;
        // A captured dialog's generated id, and its triggers' ids, carry a per-document hash;
        // the capture's own panel handle (id, panel attribute, aria-controls) carries a per-document ordinal.
        $markup = preg_replace('/\b' . preg_quote(CaptureEvidence::DIALOG_CLASS, '/') . '-\d+\b/', CaptureEvidence::DIALOG_CLASS, $markup) ?? $markup;
        $markup = preg_replace('/blocks-engine-dialog-trigger-[a-f0-9]+-(\d+)/', 'blocks-engine-dialog-trigger-$1', $markup) ?? $markup;
        $markup = preg_replace('/blocks-engine-dialog-[a-f0-9]{16}\b/', 'blocks-engine-dialog', $markup) ?? $markup;
        $markup = preg_replace('/\s*be-inline-geometry-[a-f0-9]{16}(?:-[a-f0-9]{16})?/', '', $markup) ?? $markup;
        $markup = preg_replace('/--blocks-engine-richtext-marker:\s*blocks-engine-richtext-[a-f0-9]+-\d+;?/', '', $markup) ?? $markup;
        $markup = preg_replace('/(?:\.\.\/)+assets\//', 'assets/', $markup) ?? $markup;
        if (!preg_match('/<pre\b|white-space\s*:\s*(?:pre|break-spaces)/i', $markup)) $markup = preg_replace('/\s*\n\s*/', "\n", $markup) ?? $markup;
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
        return ChromeStackingIdentity::normalize(ShellLandmarkPolicy::withoutResponsiveCorrespondenceMarkup($markup));
    }

    /**
     * A part's content is its landmark box: the part renders exactly one
     * area landmark and Core's template-part wrapper only transports it.
     * Chrome without that landmark (an edge of skip link, bar and nav, an
     * unlabeled masthead) gets a synthetic, frontend-boxless group with the area tag. Chrome that
     * already holds the landmark keeps it as authored: another landmark
     * around it would nest the two.
     */
    private static function landmarkPartMarkup(string $markup, string $area): string
    {
        if ('' === trim($markup) || self::containsAreaLandmark($markup, $area)) return $markup;
        return '<!-- wp:group {"tagName":"' . $area . '","className":"blocks-engine-shell-landmark"} --><' . $area . ' class="wp-block-group blocks-engine-shell-landmark">' . $markup . '</' . $area . '><!-- /wp:group -->';
    }

    private static function containsAreaLandmark(string $markup, string $area): bool
    {
        $role = 'header' === $area ? 'banner' : 'contentinfo';
        return str_contains($markup, '"tagName":"' . $area . '"') || str_contains($markup, '"role":"' . $role . '"') || 1 === preg_match('/<' . preg_quote($area, '/') . '[\s>]|\srole="' . $role . '"/i', $markup);
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
            // Rendered HTML a block carries as an attribute (its content, a
            // label's markup) reads like the HTML between block comments.
            foreach ($attrs as $name => $value) if (is_string($value) && ('content' === $name || preg_match('/<[a-zA-Z]/', $value))) $attrs[$name] = RenderEquivalentMarkup::canonical($value);
            if (is_string($attrs['className'] ?? null)) {
                $classes = array_values(array_filter(preg_split('/\s+/', trim($attrs['className'])) ?: array(), static fn(string $class): bool => '' !== $class && 'wp-block-group' !== $class && 'blocks-engine-empty-visual-group' !== $class && 'blocks-engine-css-owned-layout' !== $class && null === EngineMarker::editorAnchorId($class) && !self::isInheritedNavigationLinkColor($class)));
                sort($classes, SORT_STRING);
                if (array() === $classes) unset($attrs['className']); else $attrs['className'] = implode(' ', $classes);
            }
            self::canonicalizeIdentityStyles($attrs);
            self::ksortRecursive($attrs);
            $encoded = BlockAttributes::encode($attrs);
            return '' !== $encoded ? '<!-- wp:' . $parts[1] . ' ' . $encoded . ' ' . $parts[3] . '-->' : $match[0];
        }, $markup) ?? $markup;
    }

    /** Canonicalize declaration strings, leaving Core's structured style objects intact.
     * @param array<string,mixed> $attrs
     */
    private static function canonicalizeIdentityStyles(array &$attrs): void
    {
        foreach ($attrs as $name => &$value) {
            if (is_array($value)) self::canonicalizeIdentityStyles($value);
            elseif (is_string($value) && str_contains($value, ':') && ('style' === $name || str_ends_with((string) $name, 'Style'))) $value = RenderEquivalentMarkup::canonicalStyle($value);
        }
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
            $encoded = BlockAttributes::encode($attrs);
            $html = preg_replace('/\sstyle="[^"]*"/', '', $match[2]) ?? $match[2];
            return '' !== $encoded ? '<!-- wp:group ' . $encoded . ' -->' . $html . '<!-- /wp:group -->' : $match[0];
        }, $markup) ?? $markup;
    }

    /** Timestamp-like builder ids and React useId tokens are render noise, not authored anchors. */
    private static function normalizePerRenderIdTokens(string $value): string
    {
        return preg_replace('/(?:^id[0-9]{13}$|:r[0-9a-z]+:|«r[0-9a-z]+»|_r_[0-9a-z]+_)/iu', 'id-render', $value) ?? $value;
    }

    private static function normalizeRenderIds(string $markup): string
    {
        $html = static fn(string $value): string => preg_replace_callback('/\\b(id|for|aria-controls|aria-labelledby|aria-describedby|data-element-id)=("|\')([^"\']*)\\2/i', static fn(array $m): string => $m[1] . '=' . $m[2] . self::normalizePerRenderIdTokens($m[3]) . $m[2], $value) ?? $value;
        $normalize = static function (array $attrs) use (&$normalize, $html): array {
            foreach ($attrs as $key => $value) {
                if (is_array($value)) $attrs[$key] = $normalize($value);
                elseif (is_string($value)) $attrs[$key] = in_array($key, array('anchor', 'id', 'for', 'aria-controls', 'aria-labelledby', 'aria-describedby', 'data-element-id'), true) ? self::normalizePerRenderIdTokens($value) : $html($value);
            }
            return $attrs;
        };
        $markup = preg_replace_callback('/<!--\\s*wp:(\\S+)\\s+(\\{.*?\\})\\s*(\\/?)-->/s', static function (array $m) use ($normalize): string {
            $attrs = json_decode($m[2], true);
            return is_array($attrs) ? '<!-- wp:' . $m[1] . ' ' . BlockAttributes::encode($normalize($attrs)) . ' ' . $m[3] . '-->' : $m[0];
        }, $markup) ?? $markup;
        return $html($markup);
    }

    /** Normalize route-specific current state inside captured dialog snapshot HTML. */
    private static function normalizeExistingPanelSnapshots(string $markup): string
    {
        $normalizeSnapshot = static function (string $snapshot): string {
            $data = json_decode($snapshot, true);
            if (!is_array($data)) return $snapshot;
            foreach (array('resting', 'opened') as $state) {
                if (!is_string($data[$state]['html'] ?? null)) continue;
                $html = $data[$state]['html'];
                $dom = new \DOMDocument();
                HtmlSerialization::loadHtml($dom, '<?xml encoding="UTF-8"><div id="be-snapshot-root">' . $html . '</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
                $xpath = new \DOMXPath($dom);
                foreach ($xpath->query('//*[@aria-current="page"]') as $current) {
                    $siblings = array();
                    foreach ($current->parentNode->childNodes as $sibling) if ($sibling instanceof \DOMElement && $sibling->tagName === $current->tagName) $siblings[] = $sibling;
                    $shared = array();
                    foreach ($siblings as $sibling) if ($sibling !== $current) foreach (preg_split('/\\s+/', trim($sibling->getAttribute('class'))) ?: array() as $class) $shared[$class] = true;
                    $classes = array_filter(preg_split('/\\s+/', trim($current->getAttribute('class'))) ?: array(), static fn(string $class): bool => isset($shared[$class]));
                    if ($classes) $current->setAttribute('class', implode(' ', $classes)); else $current->removeAttribute('class');
                    $current->removeAttribute('aria-current');
                }
                $root = $dom->getElementById('be-snapshot-root');
                $out = ''; if ($root) foreach ($root->childNodes as $child) $out .= $dom->saveHTML($child);
                $data[$state]['html'] = $out;
            }
            return json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: $snapshot;
        };
        $markup = preg_replace_callback('/<!--\\s*wp:[^ ]+\\s+(\\{.*?\\})\\s*-->/s', static function (array $m) use ($normalizeSnapshot): string {
            $attrs = json_decode($m[1], true);
            if (!is_array($attrs)) return $m[0];
            $walk = static function (&$value) use (&$walk, $normalizeSnapshot): void {
                if (!is_array($value)) return;
                foreach ($value as $key => &$item) { if (CaptureEvidence::EXISTING_PANEL === $key && is_string($item)) $item = $normalizeSnapshot($item); else $walk($item); }
            };
            $walk($attrs);
            return '<!-- wp:' . preg_replace('/^<!--\\s*wp:([^ ]+).*/s', '$1', $m[0]) . ' ' . BlockAttributes::encode($attrs) . ' -->';
        }, $markup) ?? $markup;
        return preg_replace_callback('/(' . preg_quote(CaptureEvidence::EXISTING_PANEL, '/') . '=")([^"]*)(")/', static fn(array $m): string => $m[1] . htmlspecialchars($normalizeSnapshot(html_entity_decode($m[2], ENT_QUOTES | ENT_HTML5, 'UTF-8')), ENT_QUOTES | ENT_HTML5, 'UTF-8') . $m[3], $markup) ?? $markup;
    }

    public static function identityMarkup(string $markup): string
    {
        return self::normalizeNestedChromeMarkup($markup);
    }

    public static function withoutCurrentNavigationState(string $markup, bool $semanticIdentity = false): string
    {
        $markup = self::normalizeExistingPanelSnapshots($markup);
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
        $routeState = self::navigationRouteState($markup);
        $navigationIndex = -1; $submenuDepth = 0;
        $blockIndex = -1;
        $markup = preg_replace_callback('/<!--\s*(?:\/wp:navigation-submenu|wp:(navigation(?:-link|-submenu)?)\s+(\{.*?\})\s*(\/)?)\s*-->/s', static function (array $match) use ($semanticIdentity, $stateCarrierCounts, $sharedLinkColors, $restingColorBySignature, $restingPeers, $routeState, &$navigationIndex, &$blockIndex, &$submenuDepth): string {
            if (!isset($match[1])) { $submenuDepth = max(0, $submenuDepth - 1); return $match[0]; }
            ++$blockIndex;
            if ('navigation' === $match[1]) { ++$navigationIndex; $submenuDepth = 0; }
            $depth = $submenuDepth;
            if ('navigation-submenu' === $match[1] && empty($match[3])) ++$submenuDepth;
            $attrs = json_decode($match[2], true);
            if (!is_array($attrs)) return $match[0];
            $classes = preg_split('/\s+/', trim((string) ($attrs['className'] ?? ''))) ?: array();
            $selfClosing = '' !== ($match[3] ?? '');
            if ('navigation' === $match[1] && isset($routeState['current_navigations'][$navigationIndex])) {
                // A menu that holds the served route is disclosed for that route only.
                $resting = self::withoutDisclosureClasses($classes);
                if ($resting !== $classes) {
                    $classes = $resting;
                    if (array() === array_filter($classes)) {
                        unset($attrs['className']);
                    } else {
                        $attrs['className'] = implode(' ', $classes);
                    }
                    $match[0] = self::navigationBlockOpening($match[1], $attrs, $selfClosing);
                }
            }
            $subject = $routeState['current_link_subjects'][$blockIndex] ?? null;
            if (is_array($subject) && !isset($attrs['metadata']['blocksEngineNavigationAnchor'])) {
                $classes = self::adoptedAnchorSubjectClasses($classes, $subject, $semanticIdentity);
                $attrs['className'] = implode(' ', $classes);
                $attrs['metadata']['blocksEngineNavigationAnchor'] = $subject;
                $match[0] = self::navigationBlockOpening($match[1], $attrs, $selfClosing);
            }
            $current = in_array('blocks-engine-current-navigation-item', $classes, true);
            $peer = $restingPeers[$navigationIndex . ':' . $depth] ?? null;
            $peerClasses = is_array($peer) ? (preg_split('/\\s+/', trim((string) ($peer['className'] ?? ''))) ?: array()) : array();
            if (is_array($peer) && 'navigation' !== $match[1] && ($semanticIdentity || $current || isset($routeState['current_submenus'][$blockIndex]))) {
                // A site builder paints the served route's item, and the submenu
                // holding it, with its disclosure classes. The chrome reads as it
                // rests: disclosure absent from the resting peers is route state,
                // not authored presentation, so it never reaches the shared part.
                $resting = self::withoutDisclosureClasses($classes, $peerClasses);
                if ($resting !== $classes) {
                    $classes = $resting;
                    if (array() === array_filter($classes)) unset($attrs['className']);
                    else $attrs['className'] = implode(' ', $classes);
                    $match[0] = self::navigationBlockOpening($match[1], $attrs, $selfClosing);
                }
            }
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
                // A current item may swap arbitrary author utilities rather
                // than name its state. Match the producer's structural proof:
                // shared presentation plus missing resting classes identifies
                // the item's peer-unique replacement classes as route state.
                $replaces = array() !== array_intersect($classes, $peerClasses) && array() !== array_diff($peerClasses, $classes);
                $stable = array_values(array_filter($classes, static fn(string $class): bool => '' !== $class && (!$replaces || str_starts_with($class, 'blocks-engine-') || str_starts_with($class, 'be-')) && !self::isCurrentPageClass($class, $peerClasses) && !preg_match('/^blocks-engine-navigation-(?:current|link)-color-[a-f0-9]{64}$/', $class) && !preg_match('/^blocks-engine-navigation-link-color-states-\d+$/', $class) && !preg_match('/^be-inline-geometry-[a-f0-9]{16}(?:-[a-f0-9]{16})?$/', $class) && !in_array($class, $peerClasses, true)));
                // The part keeps the item's own link-state carrier, which the
                // navigation-root current-color rule resolves its state against.
                $stateCarriers = $semanticIdentity ? array() : array_values(array_filter($classes, static fn(string $class): bool => 1 === preg_match('/^blocks-engine-navigation-link-color-states-\d+$/', $class)));
                $merged = array_values(array_unique(array_filter(array_merge($peerClasses, $stable, $stateCarriers), static fn(string $class): bool => '' !== $class)));
                if (array() === $merged) unset($attrs['className']); else $attrs['className'] = implode(' ', $merged);
                return '<!-- wp:' . $match[1] . ' ' . BlockAttributes::encode($attrs) . ' ' . (($match[3] ?? '') ? '/' : '') . '-->';
            }
            if (!$current && !$semanticIdentity) return $match[0];
            $isLink = 'navigation' !== $match[1];
            $peerClasses = is_array($peer) ? (preg_split('/\s+/', trim((string) ($peer['className'] ?? ''))) ?: array()) : array();
            // A link colour on a current item is the colour it rests at on every
            // route (an inferred current item keeps its base colour, an authored
            // one carries its resting colour while its current presentation is
            // lifted onto the runtime current item), and the item's state carrier
            // belongs to that colour. A carrier unique to a current item without
            // one paints only the served route.
            $restingColor = array() !== preg_grep('/^blocks-engine-navigation-link-color-[a-f0-9]{64}$/', $classes);
            $classes = array_values(array_filter($classes, static function (string $class) use ($current, $semanticIdentity, $stateCarrierCounts, $peerClasses, $restingColor): bool {
                if ($current && self::isCurrentPageClass($class, $peerClasses)) return false;
                if (($current || $semanticIdentity) && preg_match('/^blocks-engine-navigation-current-color-[a-f0-9]{64}$/', $class)) return false;
                if ($semanticIdentity && $current && !$restingColor && 1 === ($stateCarrierCounts[$class] ?? 0)) return false;
                if ($semanticIdentity && $current && preg_match('/^be-inline-geometry-[a-f0-9]{16}(?:-[a-f0-9]{16})?$/', $class)) return false;
                return true;
            }));
            if ($semanticIdentity && $current && $isLink) {
                $resting = $restingColorBySignature[self::navigationClassSignature($classes)] ?? null;
                $classes = array_values(array_unique(array_merge($classes, $sharedLinkColors, is_string($resting) ? array($resting) : array())));
            }
            $attrs['className'] = implode(' ', $classes);
            if ('' === $attrs['className']) unset($attrs['className']);
            // A link with no resting peer beside it (a lone top-level link) cannot
            // take its presentation from them on the page where it is current,
            // and the current item's own colour and type are not kept. Identity
            // ignores them for such a link on every page, so one header is not
            // split by which page happens to be current.
            // A linked submenu (a featured card in a mega menu) is the served
            // route on its own page, where its current presentation is dropped
            // with the resting type it shares with every other route. Identity
            // ignores its own presentation on every page for the same reason.
            $linkedSubmenu = 'navigation-submenu' === $match[1] && '' !== (string) ($attrs['url'] ?? '');
            if ($current || ($semanticIdentity && $isLink && (false === $peer || $linkedSubmenu))) unset($attrs['color'], $attrs['style'], $attrs['typography']);
            if ($current || ($semanticIdentity && $isLink)) unset($attrs['anchor'], $attrs['anchorClassName']);
            return '<!-- wp:' . $match[1] . ' ' . BlockAttributes::encode($attrs) . ' ' . (($match[3] ?? '') ? '/' : '') . '-->';
        }, $markup) ?? $markup;
        // A menu authored as plain links (list items, rich text) marks the
        // served route with aria-current="page" in saved content. One shared
        // part serves every route, so that page-scoped state is neither part of
        // the chrome's identity nor frozen into the part.
        $markup = preg_replace('/(<a\b[^>]*?)\s+aria-current\s*=\s*(["\'])page\2/i', '$1', $markup) ?? $markup;
        // Rich mega-menu panels can retain a rendered navigation root rather
        // than navigation-link blocks. Its lifted current colour is still
        // page state, just like the marker on a core navigation comment.
        if ($semanticIdentity) {
            $markup = preg_replace('/\s*blocks-engine-navigation-current-color-[a-f0-9]{64}\b/', '', $markup) ?? $markup;
        }
        $markup = self::withoutListItemDisclosureState($markup);
        return self::withoutMenuSelectionState($markup, true);
    }

    /**
     * A menu authored as list items converts to li groups, list items or
     * navigation links. The source paints the item holding the served route (and, in a submenu,
     * its parent item) with a disclosure class its sibling items do not
     * carry. That class is route state, so it is neither part of the
     * chrome's identity nor frozen into the shared part.
     */
    private static function withoutListItemDisclosureState(string $markup): string
    {
        // Block attributes never hold `-->`: serialization escapes `--`.
        if (!preg_match_all('/<!-- (\/)?wp:([a-z0-9-]+(?:\/[a-z0-9-]+)?)(?: (\{.*?\}))? (\/)?-->(?:<li class="([^"]*)")?/s', $markup, $tokens, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) return $markup;
        // Li items keyed by the block that holds them: siblings of one list.
        $stack = array(); $lists = array();
        foreach ($tokens as $index => $token) {
            if ('' !== ($token[1][0] ?? '')) { array_pop($stack); continue; }
            $name = $token[2][0];
            $attrs = json_decode((string) ($token[3][0] ?? ''), true);
            $attrs = is_array($attrs) ? $attrs : array();
            if ('' !== ($token[5][0] ?? '') && ('list-item' === $name || ('group' === $name && 'li' === ($attrs['tagName'] ?? null)))) {
                $lists[(string) end($stack)][] = array('token' => $index, 'name' => $name, 'attrs' => $attrs, 'classes' => preg_split('/\s+/', trim($token[5][0])) ?: array(), 'li' => true);
            } elseif (in_array($name, array('navigation-link', 'navigation-submenu'), true)) {
                $lists[(string) end($stack)][] = array('token' => $index, 'name' => $name, 'attrs' => $attrs, 'classes' => preg_split('/\s+/', trim((string) ($attrs['className'] ?? ''))) ?: array(), 'li' => false, 'self_closing' => '' !== ($token[4][0] ?? ''));
            }
            if ('' === ($token[4][0] ?? '')) $stack[] = $index;
        }
        $replacements = array();
        foreach ($lists as $items) {
            if (2 > count($items)) continue;
            $counts = array();
            foreach ($items as $item) foreach (array_intersect($item['classes'], SourceClassIdentity::DISCLOSURE_CLASSES) as $class) $counts[$class] = ($counts[$class] ?? 0) + 1;
            $routeState = array_keys(array_filter($counts, static fn(int $count): bool => 1 === $count));
            if (array() === $routeState) continue;
            foreach ($items as $item) {
                if (array() === array_intersect($item['classes'], $routeState)) continue;
                $attrs = $item['attrs'];
                $kept = array_values(array_diff(preg_split('/\s+/', trim((string) ($attrs['className'] ?? ''))) ?: array(), $routeState));
                if (array() === $kept) unset($attrs['className']); else $attrs['className'] = implode(' ', $kept);
                $token = $tokens[$item['token']];
                $comment = '<!-- wp:' . $item['name'] . (array() === $attrs ? '' : ' ' . BlockAttributes::encode($attrs)) . ($item['li'] || empty($item['self_closing']) ? ' -->' : ' /-->');
                $replacements[] = array($token[0][1], strlen($token[0][0]), $comment . ($item['li'] ? '<li class="' . implode(' ', array_values(array_diff($item['classes'], $routeState))) . '"' : ('' === ($token[5][0] ?? '') ? '' : '<li class="' . $token[5][0] . '"')));
            }
        }
        usort($replacements, static fn(array $left, array $right): int => $right[0] <=> $left[0]);
        foreach ($replacements as [$offset, $length, $replacement]) $markup = substr_replace($markup, $replacement, $offset, $length);
        return $markup;
    }

    /**
     * Route state of the navigation blocks, indexed in the order
     * withoutCurrentNavigationState() visits them:
     *
     * - current_navigations: navigation blocks (counted like its
     *   $navigationIndex) that contain the current item.
     * - current_submenus: submenu blocks that contain the current item.
     * - current_link_subjects: for each current link (counted like its
     *   $blockIndex), the anchor subject its resting siblings in the same
     *   navigation or submenu share.
     *
     * @return array{current_navigations:array<int,true>,current_submenus:array<int,true>,current_link_subjects:array<int,array<string,mixed>>}
     */
    private static function navigationRouteState(string $markup): array
    {
        $currentNavigations = array();
        $currentSubmenus = array();
        $currentLinkContainers = array();
        $subjectCounts = array();
        // Open navigation and submenu blocks, innermost last.
        $containers = array();
        $navigationIndex = -1;
        $blockIndex = -1;
        preg_match_all('/<!--\s*(?:wp:(navigation(?:-link|-submenu)?)(?:\s+(\{.*?\}))?\s*(\/)?|(\/)wp:navigation(?:-submenu)?\s*)-->/s', $markup, $matches, PREG_SET_ORDER);
        foreach ($matches as $match) {
            if ('' !== ($match[4] ?? '')) {
                array_pop($containers);
                continue;
            }
            $name = $match[1];
            $attributes = $match[2] ?? '';
            $selfClosing = '' !== ($match[3] ?? '');
            // Only blocks with attributes are visited by withoutCurrentNavigationState().
            if ('' !== $attributes) {
                ++$blockIndex;
            }
            if ('navigation' === $name) {
                $navigation = '' !== $attributes ? ++$navigationIndex : null;
                if (!$selfClosing) {
                    $containers[] = array('navigation' => $navigation, 'key' => 'navigation-' . $blockIndex);
                }
                continue;
            }
            $container = end($containers);
            if ('navigation-submenu' === $name && !$selfClosing) {
                $containers[] = array('navigation' => null, 'key' => 'submenu-' . $blockIndex);
            }
            $attrs = '' !== $attributes ? json_decode($attributes, true) : null;
            if (!is_array($container) || !is_array($attrs)) {
                continue;
            }
            if (str_contains((string) ($attrs['className'] ?? ''), 'blocks-engine-current-navigation-item')) {
                foreach ($containers as $ancestor) {
                    if (null !== $ancestor['navigation']) {
                        $currentNavigations[$ancestor['navigation']] = true;
                    } elseif (str_starts_with($ancestor['key'], 'submenu-')) {
                        $currentSubmenus[(int) substr($ancestor['key'], 8)] = true;
                    }
                }
                if ('navigation-link' === $name) {
                    $currentLinkContainers[$blockIndex] = $container['key'];
                }
                continue;
            }
            $subject = 'navigation-link' === $name ? self::sharedAnchorSubject($attrs) : null;
            if (null === $subject) {
                continue;
            }
            $subjectKey = (string) json_encode($subject);
            $subjectCounts[$container['key']][$subjectKey]['count'] = ($subjectCounts[$container['key']][$subjectKey]['count'] ?? 0) + 1;
            $subjectCounts[$container['key']][$subjectKey]['subject'] = $subject;
        }
        $currentLinkSubjects = array();
        foreach ($currentLinkContainers as $link => $containerKey) {
            $subject = self::dominantAnchorSubject($subjectCounts[$containerKey] ?? array());
            if (null !== $subject) {
                $currentLinkSubjects[$link] = $subject;
            }
        }
        return array('current_navigations' => $currentNavigations, 'current_submenus' => $currentSubmenus, 'current_link_subjects' => $currentLinkSubjects);
    }

    /**
     * The anchor subject a resting link renders through, without the parts
     * that belong to that link alone: its id and inline geometry markers.
     *
     * @param array<string,mixed> $attrs
     * @return array<string,mixed>|null
     */
    private static function sharedAnchorSubject(array $attrs): ?array
    {
        $subject = $attrs['metadata']['blocksEngineNavigationAnchor'] ?? null;
        if (!is_array($subject)) {
            return null;
        }
        unset($subject['id']);
        if (is_string($subject['className'] ?? null)) {
            $classes = preg_split('/\s+/', trim($subject['className'])) ?: array();
            $subject['className'] = implode(' ', array_filter($classes, static fn(string $class): bool => 1 !== preg_match('/^be-inline-geometry-[a-f0-9]{16}(?:-[a-f0-9]{16})?$/', $class)));
        }
        return $subject;
    }

    /**
     * The most common anchor subject, when at least two links share it.
     *
     * @param array<string,array{count:int,subject:array<string,mixed>}> $counts
     * @return array<string,mixed>|null
     */
    private static function dominantAnchorSubject(array $counts): ?array
    {
        uasort($counts, static fn(array $left, array $right): int => $right['count'] <=> $left['count']);
        $top = reset($counts);
        return is_array($top) && 2 <= $top['count'] ? $top['subject'] : null;
    }

    /**
     * Classes without disclosure state not shared by the resting peers.
     * With no peer classes, this preserves the navigation-root behavior.
     *
     * @param array<int,string> $classes
     * @param array<int,string> $peerClasses
     * @return array<int,string>
     */
    private static function withoutDisclosureClasses(array $classes, array $peerClasses = array()): array
    {
        return array_values(array_diff($classes, array_diff(SourceClassIdentity::DISCLOSURE_CLASSES, $peerClasses)));
    }

    /**
     * Classes of an authored current item that adopts its resting siblings'
     * anchor subject. The item rendered its anchor classes on itself; they
     * move back to the subject. Its link-state carrier and the anchor
     * line-height/box markers that stood in for the subject only paint the
     * served route, so they are not part of the chrome's identity.
     *
     * @param array<int,string> $classes
     * @param array<string,mixed> $subject
     * @return array<int,string>
     */
    private static function adoptedAnchorSubjectClasses(array $classes, array $subject, bool $semanticIdentity): array
    {
        $subjectClasses = preg_split('/\s+/', trim((string) ($subject['className'] ?? ''))) ?: array();
        $classes = array_diff($classes, $subjectClasses);
        if ($semanticIdentity) {
            $classes = array_filter($classes, static fn(string $class): bool => 1 !== preg_match('/^blocks-engine-navigation-(?:link-color-states-\d+|anchor-line-height-[a-f0-9]{64}|link-box-[a-f0-9]{64})$/', $class));
        }
        return array_values($classes);
    }

    /** @param array<string,mixed> $attrs */
    private static function navigationBlockOpening(string $name, array $attrs, bool $selfClosing): string
    {
        return '<!-- wp:' . $name . ' ' . BlockAttributes::encode($attrs) . ' ' . ($selfClosing ? '/' : '') . '-->';
    }

    private static function withoutMenuSelectionState(string $markup, bool $resting = false): string
    {
        $replacement = $resting ? 'menu false link' : 'menu link';
        $markup = preg_replace('/(data-state=)(\\\\u0022|"|&quot;)menu (?:selected|false)\s+link\2/', '$1$2' . $replacement . '$2', $markup) ?? $markup;
        return preg_replace('/\s*(aria-current=)(\\\\u0022|"|&quot;)page\2/', '', $markup) ?? $markup;
    }

    /**
     * Per navigation block (document order) and submenu depth, keyed
     * `index:depth`, the presentation shared by at least two of its non-current
     * links: className plus color/style attrs.
     *
     * @return array<string,array<string,mixed>|false|null> false marks a level with a single link
     */
    private static function restingNavigationPeers(string $markup): array
    {
        $peers = array(); $index = -1; $groups = array(); $currentSignatures = array(); $depth = 0; $linkCounts = array();
        preg_match_all('/<!--\s*(\/)?wp:(navigation(?:-link|-submenu)?)(?:\s+(\{.*?\}))?\s*(\/)?-->/s', $markup, $matches, PREG_SET_ORDER);
        foreach ($matches as $match) {
            if ('navigation-submenu' === $match[2]) {
                if ('' !== $match[1]) { $depth = max(0, $depth - 1); continue; }
                if (empty($match[4])) ++$depth;
                continue;
            }
            if ('' !== $match[1]) continue;
            if ('navigation' === $match[2]) { ++$index; $depth = 0; continue; }
            if ($index < 0) continue;
            // A link is only a peer of the links beside it. The items of a
            // submenu style differently from the top-level items.
            $level = $index . ':' . $depth;
            $linkCounts[$level] = ($linkCounts[$level] ?? 0) + 1;
            $attrs = json_decode($match[3] ?? '', true);
            if (!is_array($attrs)) continue;
            $classes = preg_split('/\s+/', trim((string) ($attrs['className'] ?? ''))) ?: array();
            if (in_array('blocks-engine-current-navigation-item', $classes, true)) {
                $currentSignatures[$level][] = self::navigationClassSignature($classes);
                continue;
            }
            $presentation = array_intersect_key($attrs, array_flip(array('className', 'style', 'color', 'typography')));
            if (is_string($presentation['className'] ?? null)) {
                $classes = array_values(array_filter($classes, static fn(string $class): bool => !preg_match('/^blocks-engine-attribute-[a-f0-9]{6,}(?:-\d+)?$/', $class)));
                sort($classes, SORT_STRING);
                $presentation['className'] = implode(' ', $classes);
            }
            $key = json_encode($presentation);
            $groups[$level][$key] = array('count' => ($groups[$level][$key]['count'] ?? 0) + 1, 'presentation' => $presentation);
        }
        foreach ($groups as $navigation => $candidates) {
            uasort($candidates, static fn(array $left, array $right): int => $right['count'] <=> $left['count']);
            $top = reset($candidates);
            $singlePeerMatches = is_array($top) && 1 === count($candidates)
                && array() !== ($currentSignatures[$navigation] ?? array())
                && array(self::navigationClassSignature(preg_split('/\s+/', trim((string) ($top['presentation']['className'] ?? ''))) ?: array())) === array_values(array_unique($currentSignatures[$navigation]));
            $peers[$navigation] = is_array($top) && (2 <= $top['count'] || $singlePeerMatches) ? $top['presentation'] : null;
        }
        // A level holding one link has no peers at all.
        foreach ($linkCounts as $level => $count) if (1 === $count && !is_array($peers[$level] ?? null)) $peers[$level] = false;
        return $peers;
    }

    /** @param array<int,string> $peerClasses */
    private static function isCurrentPageClass(string $class, array $peerClasses = array()): bool
    {
        if (in_array($class, array('blocks-engine-current-navigation-item', 'blocks-engine-current-navigation-underline'), true) || SourceClassIdentity::isCurrentState($class)) return true;
        if (1 !== preg_match('/(?:^|[-_])(?:is-)?(?:current|active|selected|on)$/', $class)) return false;
        return !in_array($class, $peerClasses, true);
    }

    /** @param array<int,string> $classes */
    private static function navigationClassSignature(array $classes): string
    {
        $classes = array_values(array_filter($classes, static function (string $class): bool {
            return !in_array($class, array('blocks-engine-current-navigation-item', 'blocks-engine-current-navigation-underline'), true) && !SourceClassIdentity::isCurrentState($class)
                && !preg_match('/^blocks-engine-navigation-(?:current|link)-color-[a-f0-9]{64}$/', $class)
                && !preg_match('/^be-inline-geometry-[a-f0-9]{16}(?:-[a-f0-9]{16})?$/', $class);
        }));
        sort($classes, SORT_STRING);
        return implode(' ', $classes);
    }
}
