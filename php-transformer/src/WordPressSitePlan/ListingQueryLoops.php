<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan;

use Automattic\BlocksEngine\PhpTransformer\Support\HtmlMarkupParser;
use Automattic\BlocksEngine\PhpTransformer\Support\ArrayField;
use Automattic\BlocksEngine\PhpTransformer\Support\BlockAttributes;

/**
 * Turns a listing page's repeated post cards into a core query loop.
 *
 * A listing page that renders one card per post (title, date, excerpt,
 * featured image, read-more link) becomes a `wp:query` whose post template is
 * the first card with each per-post value bound to its core post block. The
 * cards' own excerpts, featured images and literal fields move to the posts as
 * metadata, so the loop renders what the source showed.
 *
 * One instance serves one site plan run; it records the source container of
 * every loop it materializes for the stylesheet that presents them.
 */
final class ListingQueryLoops
{
    /** @var list<string> */
    private array $queryContainers = array();
    /** @var array<string,list<array{href:string,text:string}>> Listing anchors by markup hash. */
    private array $anchorsByMarkup = array();

    /**
     * @param string $sourceOrigin The source site's origin, so absolute links to it count as routes.
     * @param \Closure(string):string $chromeIdentity The site plan's shared chrome identity of a block.
     */
    public function __construct(private readonly string $sourceOrigin, private readonly \Closure $chromeIdentity)
    {
    }

    /**
     * The source container of each query loop materialized so far.
     *
     * @return list<string>
     */
    public function queryContainers(): array
    {
        return $this->queryContainers;
    }

    /**
     * @param array<int,array<string,mixed>> $pages
     * @param bool $postChromeSettled Whether shared shells and article chrome
     *        have been factored out, so post content is final.
     * @return array<int,array<string,mixed>>
     */
    public function materialize(array $pages, array $runtimeDeclarations = array(), array $taxonomyEntities = array(), bool $postChromeSettled = false): array
    {
        $postsByParent = array();
        $postsByArchive = array();
        $excerpts = array();
        $featuredImages = array();
        $postMeta = array();
        $bindingsBySource = array();
        foreach ($runtimeDeclarations as $declaration) foreach ($declaration['payload']['entities'] ?? array() as $entity) foreach ($entity['bindings'] ?? array() as $binding) {
            $source = $binding['source_path'] ?? null;
            $search = $binding['search_block_markup'] ?? null;
            if (is_string($source) && is_string($search) && '' !== $search) $bindingsBySource[$source][] = $search;
        }
        foreach ($pages as $page) {
            if ('post' !== ($page['post_type'] ?? null) || !empty($page['synthetic']) || !is_string($page['route']['path'] ?? null)) {
                continue;
            }
            $postsByParent[WordPressSitePlan::parentRoutePath($page['route']['path'])][] = $page;
        }
        $pagesBySource = array_column($pages, null, 'source_path');
        $sections = TaxonomyProjection::sections($pages, $taxonomyEntities, $this->sourceOrigin);
        foreach ($taxonomyEntities as $entity) if (isset($entity['archive'])) foreach ($entity['membership_source_paths'] as $sourcePath) if (isset($pagesBySource[$sourcePath])) $postsByArchive[$entity['archive']['source_path']][] = $pagesBySource[$sourcePath];
        $candidates = array();
        foreach ($pages as $index => $page) {
            if (isset($postsByArchive[$page['source_path']]) || count($postsByParent) < 2) $candidates[] = array($index, null);
            else foreach ($sections as $route => $entity) $candidates[] = array($index, $route);
        }
        foreach ($candidates as [$index, $sectionRoute]) {
            $page = &$pages[$index];
            if ('page' !== ($page['post_type'] ?? null) || !empty($page['synthetic']) || !is_string($page['route']['path'] ?? null)) {
                continue;
            }
            $posts = $postsByArchive[$page['source_path']] ?? ($postsByParent[$sectionRoute ?? $page['route']['path']] ?? array());
            if (count($posts) < (isset($postsByArchive[$page['source_path']]) ? 1 : 2) || !is_string($page['canonical_block_markup'] ?? null) || (null === $sectionRoute && str_contains($page['canonical_block_markup'], '<!-- wp:query'))) {
                continue;
            }
            $sourceListingMarkup = $page['canonical_block_markup'];
            $fields = array();
            if (null !== $sectionRoute) {
                $cards = $this->cardRanges(new \Automattic\BlocksEngine\PhpTransformer\Support\BlockMarkupIndex($sourceListingMarkup), $posts);
                if (null === $cards || count($cards) !== count($posts)) continue;
                $previous = null;
                foreach ($cards as $cardIndex => $card) {
                    if ($cardIndex > 0) {
                        $last = $cards[$cardIndex - 1];
                        $gap = substr($sourceListingMarkup, $last['offset'] + $last['length'], $card['offset'] - $last['offset'] - $last['length']);
                        // Do not consume an interleaved curated card or other live content.
                        foreach ((new \Automattic\BlocksEngine\PhpTransformer\Support\BlockMarkupIndex($gap))->topLevel() as $range) if (!in_array(PostBlocks::sourceBlockName(substr($gap, $range['offset'], $range['length'])), array('separator', 'spacer'), true)) continue 3;
                    }
                    $date = $card['post']['publication_timestamp'] ?? null;
                    if (!is_string($date) || (null !== $previous && $date > $previous)) continue 2;
                    $previous = $date;
                }
            }
            $replaced = $this->replaceMarkup($sourceListingMarkup, $posts, $fields, isset($postsByArchive[$page['source_path']]), null !== $sectionRoute ? $sections[$sectionRoute]['slug'] : null);
            if (null === $replaced || $replaced === $page['canonical_block_markup']) {
                continue;
            }
            // A card that renders post content repeats whatever chrome the posts
            // still own. When a post still carries a block the listing page
            // already renders around its cards (a banner, sidebar or comment
            // form that could not move to the single template), every listing
            // item would repeat it, so the listing stays as captured.
            if ($postChromeSettled && substr_count($replaced, '<!-- wp:post-content') > substr_count($sourceListingMarkup, '<!-- wp:post-content')
                && $this->postsRepeatChrome($sourceListingMarkup, $posts)
            ) {
                continue;
            }
            // A template cannot replace a source region that owns a live entity.
            foreach ($bindingsBySource[$page['source_path']] ?? array() as $anchor) {
                if (substr_count($replaced, $anchor) !== substr_count($page['canonical_block_markup'], $anchor)) continue 2;
            }
            // The query loop repeats the first card. A card menu the loop does
            // not render would be lost, so the listing stays as captured.
            if (array() !== array_diff_key(NavigationEntityProjection::renderedMenus($page['canonical_block_markup']), NavigationEntityProjection::renderedMenus($replaced))) continue;
            $page['canonical_block_markup'] = $replaced;
            $page['content_hash'] = WordPressSitePlan::contentHash($replaced);
            $listingIndex = new \Automattic\BlocksEngine\PhpTransformer\Support\BlockMarkupIndex($sourceListingMarkup);
            $cards = $this->cardRanges($listingIndex, $posts, isset($postsByArchive[$page['source_path']]) ? 1 : 2) ?? array();
            $firstCard = $cards[0] ?? null;
            $parent = is_array($firstCard) ? $listingIndex->parent($firstCard) : null;
            if (is_array($parent)) $this->queryContainers[] = substr($sourceListingMarkup, $parent['offset'], $parent['length']);
            foreach ($fields as $source => $values) $postMeta[$source] = ($postMeta[$source] ?? array()) + $values;
            foreach ($cards as $card) {
                $cardMarkup = substr($sourceListingMarkup, $card['offset'], $card['length']);
                $cardIndex = new \Automattic\BlocksEngine\PhpTransformer\Support\BlockMarkupIndex($cardMarkup);
                if (str_contains($replaced, 'blocks-engine-listing-background')) {
                    foreach ((new \Automattic\BlocksEngine\PhpTransformer\Support\BlockMarkupIndex($card['post']['canonical_block_markup']))->ranges() as $leadRange) {
                        $lead = substr($card['post']['canonical_block_markup'], $leadRange['offset'], $leadRange['length']);
                        if ('image' !== PostBlocks::sourceBlockName($lead)) continue;
                        $image = PostBlocks::leadImage($lead);
                        if (null !== $image) $featuredImages[$card['post']['source_path']] = $image;
                        break;
                    }
                }
                if (str_contains($replaced, '<!-- wp:post-featured-image')) {
                    $postIndex = new \Automattic\BlocksEngine\PhpTransformer\Support\BlockMarkupIndex($card['post']['canonical_block_markup']);
                    foreach ($cardIndex->ranges() as $leaf) {
                        $slice = substr($cardMarkup, $leaf['offset'], $leaf['length']);
                        if ('featured-image' !== $this->classifyCardBlock($slice, $card['post'], $card['route'], $postIndex) && !('image' === PostBlocks::sourceBlockName($slice) && str_contains($cardMarkup, '"wrappers"'))) continue;
                        $image = PostBlocks::leadImage($slice);
                        if (null !== $image) $featuredImages[$card['post']['source_path']] = $image;
                        break;
                    }
                }
                $description = ListingExcerptProjection::description($card['post']);
                if (null === $description) continue;
                foreach ($cardIndex->ranges() as $leaf) {
                    $slice = substr($cardMarkup, $leaf['offset'], $leaf['length']);
                    if ('paragraph' === PostBlocks::sourceBlockName($slice) && ListingExcerptProjection::matches($slice, $card['post'])) { $excerpts[$card['post']['source_path']] = $description; break; }
                }
            }
        }
        unset($page);
        foreach ($pages as &$page) if (isset($excerpts[$page['source_path']])) $page['metadata']['excerpt'] = $excerpts[$page['source_path']];
        unset($page);
        foreach ($pages as &$page) if (isset($featuredImages[$page['source_path']]) && !isset($page['metadata']['featured_image'])) $page['metadata']['featured_image'] = $featuredImages[$page['source_path']];
        unset($page);
        foreach ($pages as &$page) if (isset($postMeta[$page['source_path']])) $page['metadata']['post_meta'] = ($page['metadata']['post_meta'] ?? array()) + $postMeta[$page['source_path']];
        unset($page);
        return $pages;
    }

    /**
     * Whether any post still owns a container block that the listing page
     * renders outside its cards.
     *
     * @param array<int,array<string,mixed>> $posts
     */
    private function postsRepeatChrome(string $markup, array $posts): bool
    {
        $markupIndex = new \Automattic\BlocksEngine\PhpTransformer\Support\BlockMarkupIndex($markup);
        $cards = $this->cardRanges($markupIndex, $posts) ?? array();
        $chrome = array();
        foreach ($markupIndex->ranges() as $range) {
            $end = $range['offset'] + $range['length'];
            foreach ($cards as $card) {
                if ($range['offset'] < $card['offset'] + $card['length'] && $card['offset'] < $end) continue 2;
            }
            if (array() === $markupIndex->children($range)) continue;
            $identity = ($this->chromeIdentity)(substr($markup, $range['offset'], $range['length']));
            if ('' !== $identity) $chrome[$identity] = true;
        }
        if (array() === $chrome) return false;
        foreach ($posts as $post) {
            $postMarkup = (string) ($post['canonical_block_markup'] ?? '');
            $postIndex = new \Automattic\BlocksEngine\PhpTransformer\Support\BlockMarkupIndex($postMarkup);
            foreach ($postIndex->ranges() as $range) {
                if (array() === $postIndex->children($range)) continue;
                if (isset($chrome[($this->chromeIdentity)(substr($postMarkup, $range['offset'], $range['length']))])) return true;
            }
        }
        return false;
    }

    /** @param array<int,array<string,mixed>> $posts */
    private function replaceMarkup(string $markup, array $posts, array &$fields = array(), bool $inheritQuery = false, ?string $sectionSlug = null): ?string
    {
        $documentIndex = new \Automattic\BlocksEngine\PhpTransformer\Support\BlockMarkupIndex($markup);
        $cards = $this->cardRanges($documentIndex, $posts, $inheritQuery ? 1 : 2);
        if (null === $cards) {
            return $this->replaceArchives($markup, $posts);
        }
        $template = $this->cardTemplate($documentIndex, $cards, $fields);
        if (null === $template) {
            return $this->replaceArchives($markup, $posts);
        }
        $start = $cards[0]['offset'];
        $end = $cards[count($cards) - 1]['offset'] + $cards[count($cards) - 1]['length'];
        $query = PostBlocks::query(count($cards), $template, $inheritQuery, $sectionSlug);
        $parent = $documentIndex->parent($cards[0]);
        if (is_array($parent) && 'columns' === PostBlocks::sourceBlockName(substr($markup, $parent['offset'], $parent['length']))) {
            $query = '<!-- wp:column --><div class="wp-block-column">' . $query . '</div><!-- /wp:column -->';
        }
        $replaced = substr($markup, 0, $start) . $query . substr($markup, $end);
        return $this->replaceArchives($replaced, $posts);
    }

    /**
     * @param array<int,array<string,mixed>> $posts
     * @return array<int,array{offset:int,length:int,route:string,post:array<string,mixed>}>|null
     */
    public function cardRanges(\Automattic\BlocksEngine\PhpTransformer\Support\BlockMarkupIndex $documentIndex, array $posts, int $minimumCards = 2): ?array
    {
        $markup = $documentIndex->markup;
        $routes = array();
        foreach ($posts as $post) {
            if (is_string($post['route']['path'] ?? null)) {
                $routes[$post['route']['path']] = $post;
            }
        }
        $ranges = $documentIndex->ranges();
        // A range's anchors do not depend on the route being matched.
        $rangeAnchors = array();
        foreach (array() === $routes ? array() : $ranges as $index => $range) {
            $slice = substr($markup, $range['offset'], $range['length']);
            // A single reciprocal card must not swallow the archive heading.
            if (1 === count($routes) && preg_match('/<h1\b/i', $slice)) continue;
            $rangeAnchors[$index] = $this->anchors($slice);
        }
        // A range is a card for a route when every anchor that addresses any
        // listed route addresses that route. Resolve each anchor to the routes
        // it addresses once, so a listing of N posts costs one pass over its
        // ranges instead of N passes that each rescan every route.
        $exclusive = array();
        foreach ($rangeAnchors as $index => $anchors) {
            $shared = null;
            foreach ($anchors as $anchor) {
                $addressed = array_intersect_key(array_flip(self::routeKeys($anchor['href'])), $routes);
                if (array() === $addressed) continue;
                $shared = null === $shared ? $addressed : array_intersect_key($shared, $addressed);
            }
            foreach (null === $shared ? array() : array_keys($shared) as $route) {
                $range = $ranges[$index];
                if (!isset($exclusive[$route]) || $range['length'] > $exclusive[$route]['length']) {
                    $exclusive[$route] = array('offset' => $range['offset'], 'length' => $range['length'], 'route' => $route, 'post' => $routes[$route]);
                }
            }
        }
        $cards = array_values(array_intersect_key(array_replace($routes, $exclusive), $exclusive));
        if (count($cards) < $minimumCards) {
            return null;
        }
        usort($cards, static fn(array $left, array $right): int => $left['offset'] <=> $right['offset']);
        $parent = $documentIndex->parent($cards[0]);
        for ($index = 0; $index < count($cards); ++$index) {
            if ($index > 0 && $cards[$index]['offset'] < $cards[$index - 1]['offset'] + $cards[$index - 1]['length']) {
                return null;
            }
            $cardParent = $documentIndex->parent($cards[$index]);
            if ((null === $parent) !== (null === $cardParent) || (is_array($parent) && is_array($cardParent) && ($parent['offset'] !== $cardParent['offset'] || $parent['length'] !== $cardParent['length']))) {
                return null;
            }
        }
        return $cards;
    }

    /**
     * @param array<int,array{offset:int,length:int,route:string,post:array<string,mixed>}> $cards
     */
    public function cardTemplate(\Automattic\BlocksEngine\PhpTransformer\Support\BlockMarkupIndex $documentIndex, array $cards, array &$fields, array $boundGroups = array()): ?string
    {
        $markup = $documentIndex->markup;
        // A hidden empty binding may have been dropped during lowering. Use
        // the fullest card as the template so its optional slot still exists.
        $blocks = static fn(array $card): int => count(array_filter($documentIndex->ranges(), static fn(array $range): bool => $range['offset'] >= $card['offset'] && $range['offset'] + $range['length'] <= $card['offset'] + $card['length']));
        $templateCards = $cards;
        usort($templateCards, static fn(array $a, array $b): int => $blocks($b) <=> $blocks($a));
        $first = $templateCards[0];
        // Backgrounds are per-post only with evidence from every card, not
        // merely because the template card happens to use its lead image.
        foreach ($cards as $card) {
            $cardMarkup = substr($markup, $card['offset'], $card['length']);
            foreach ((new \Automattic\BlocksEngine\PhpTransformer\Support\BlockMarkupIndex($cardMarkup))->ranges() as $range) {
                foreach (PostBlocks::sourceAttributes(substr($cardMarkup, $range['offset'], $range['length']))['wrappers'] ?? array() as $wrapper) {
                    $image = self::backgroundImage($wrapper);
                    if (null !== $image && !self::postHasLeadImage($card['post'], $image)) return null;
                }
            }
        }
        $groups = $this->linkedMetadataGroups($documentIndex, $first);
        foreach ($groups as $index => $group) {
            $key = 'blocks_engine_listing_labels_' . substr(hash('sha256', $group['markup']), 0, 12);
            $content = ListingFieldProjection::content($group['paragraphs']);
            $paragraph = ListingFieldProjection::paragraph($group['paragraphs'][0], PostBlocks::sourceAttributes($group['paragraphs'][0]), $key, $content);
            $bound = $group['markup'];
            foreach (array_reverse($group['ranges'], true) as $childIndex => $child) {
                $relative = $child['offset'] - $group['offset'];
                $bound = substr($bound, 0, $relative) . (0 === $childIndex ? $paragraph : '') . substr($bound, $relative + $child['length']);
            }
            $boundGroups[$group['offset']] = $bound;
            foreach ($cards as $card) {
                $row = $this->linkedMetadataGroups($documentIndex, $card)[$index] ?? null;
                $fields[$card['post']['source_path']][$key] = null === $row ? '' : ListingFieldProjection::content($row['paragraphs']);
            }
        }
        $literalFields = $this->literalFields($markup, $templateCards, $fields);
        if (null === $literalFields) return null;
        $boundGroups += $literalFields;
        $compact = !array_filter($cards, fn(array $card): bool => !$this->isExcerptCard($markup, $card));
        $sharedText = null;
        foreach ($cards as $card) {
            $texts = array();
            $cardMarkup = substr($markup, $card['offset'], $card['length']);
            $cardIndex = new \Automattic\BlocksEngine\PhpTransformer\Support\BlockMarkupIndex($cardMarkup);
            foreach ($cardIndex->ranges() as $leaf) {
                if (array() !== $cardIndex->children($leaf)) continue;
                $texts[self::leafIdentity(substr($cardMarkup, $leaf['offset'], $leaf['length']))] = true;
            }
            $sharedText = null === $sharedText ? $texts : array_intersect_key($sharedText, $texts);
        }
        $transformed = $this->transformRange($documentIndex, new \Automattic\BlocksEngine\PhpTransformer\Support\BlockMarkupIndex($first['post']['canonical_block_markup']), $first, $first['post'], $first['route'], $compact ? ListingExcerptProjection::length($cards) : 0, $boundGroups, false, $sharedText ?? array());
        if (null === $transformed || !$transformed['title'] || '' === $transformed['markup']) {
            return null;
        }
        $template = $transformed['markup'];
        $gaps = array();
        for ($index = 1; $index < count($cards); ++$index) {
            $gaps[] = substr($markup, $cards[$index - 1]['offset'] + $cards[$index - 1]['length'], $cards[$index]['offset'] - ($cards[$index - 1]['offset'] + $cards[$index - 1]['length']));
        }
        $uniqueGaps = array_unique($gaps);
        if (1 === count($uniqueGaps) && '' !== trim($uniqueGaps[0]) && !str_contains($uniqueGaps[0], '<!-- wp:')) {
            $template .= $uniqueGaps[0];
        } elseif (1 === count($uniqueGaps) && 1 === preg_match('/^<!-- wp:(?:separator|spacer)\b/', trim($uniqueGaps[0]))) {
            $template .= $uniqueGaps[0];
        }
        return $template;
    }

    /**
     * Bind short, plain paragraph literals only at corresponding structural slots.
     * Empty labels are values too; they must not freeze the first card's label.
     *
     * @param list<array<string,mixed>> $cards
     * @param array<string,array<string,string>> $fields
     * @return array<int,string>|null Null when rows cannot be safely aligned.
     */
    private function literalFields(string $markup, array $cards, array &$fields): ?array
    {
        $rows = array();
        $trees = array();
        foreach ($cards as $card) {
            $row = array();
            $slice = substr($markup, $card['offset'], $card['length']);
            $sliceIndex = new \Automattic\BlocksEngine\PhpTransformer\Support\BlockMarkupIndex($slice);
            foreach ($sliceIndex->ranges() as $range) {
                $leaf = substr($slice, $range['offset'], $range['length']);
                $children = $sliceIndex->children($range);
                // HTML lowering represents an empty source div as a group,
                // but its text-bearing counterpart as a synthetic paragraph.
                $attrs = PostBlocks::sourceAttributes($leaf);
                if (array() === $children && 'group' === PostBlocks::sourceBlockName($leaf) && str_contains((string) ($attrs['className'] ?? ''), 'blocks-engine-empty-visual-group') && '' === ListingExcerptProjection::text($leaf)) {
                    $attrs['className'] = trim(str_replace('blocks-engine-empty-visual-group', 'blocks-engine-synthetic-paragraph blocks-engine-source-box-paragraph', $attrs['className']));
                    $leaf = '<!-- wp:paragraph ' . BlockAttributes::encode($attrs) . ' --><p class="' . htmlspecialchars($attrs['className'], ENT_QUOTES, 'UTF-8') . '"></p><!-- /wp:paragraph -->';
                }
                $row[$range['offset']] = array('markup' => $leaf, 'offset' => $card['offset'] + $range['offset'], 'children' => array_column($children, 'offset'));
            }
            $trees[] = $row;
        }
        // Align siblings, never a flat leaf sequence: an omitted label must
        // occupy the same position under the same corresponding parent.
        $align = function (array $expected, array $actual, array $template, array $tree) use (&$align): ?array {
            if (array() === $expected) return array() === $actual ? array() : null;
            $node = $template[$expected[0]];
            $matches = array();
            if (array() !== $actual) {
                $other = $tree[$actual[0]];
                if (PostBlocks::sourceBlockName($node['markup']) === PostBlocks::sourceBlockName($other['markup']) && (array() === $node['children']) === (array() === $other['children'])) {
                    $children = $align($node['children'], $other['children'], $template, $tree);
                    $rest = $align(array_slice($expected, 1), array_slice($actual, 1), $template, $tree);
                    if (null !== $children && null !== $rest) {
                        $matches[] = array_merge(array() === $node['children'] ? array($other) : $children, $rest);
                    }
                }
            }
            if (array() === $node['children'] && 'paragraph' === PostBlocks::sourceBlockName($node['markup']) && preg_match('~<p\\b[^>]*>[^<]*</p>~s', $node['markup']) && mb_strlen(ListingExcerptProjection::text($node['markup'])) <= 160) {
                $rest = $align(array_slice($expected, 1), $actual, $template, $tree);
                if (null !== $rest) $matches[] = array_merge(array(null), $rest);
            }
            // Ambiguous omissions are not evidence of a corresponding slot.
            return 1 === count($matches) ? $matches[0] : null;
        };
        foreach ($trees as $tree) {
            $row = $align(array(array_key_first($trees[0])), array(array_key_first($tree)), $trees[0], $tree);
            if (null === $row) return null;
            $rows[] = $row;
        }
        $bound = array();
        foreach ($rows[0] as $index => $first) {
            $optional = in_array(null, array_column($rows, $index), true);
            $values = array();
            $signature = null;
            foreach ($rows as $cardIndex => $row) {
                if (null === $row[$index]) {
                    $values[] = '';
                    continue;
                }
                $leaf = $row[$index]['markup'];
                if ('paragraph' !== PostBlocks::sourceBlockName($leaf) || !preg_match('~<p\\b([^>]*)>([^<]*)</p>~s', $leaf, $match)) {
                    if ($optional) return null;
                    continue 2;
                }
                $text = ListingExcerptProjection::text($leaf);
                if (mb_strlen($text) > 160 || ListingExcerptProjection::matches($leaf, $cards[$cardIndex]['post'])) {
                    if ($optional) return null;
                    continue 2;
                }
                $postIndex = new \Automattic\BlocksEngine\PhpTransformer\Support\BlockMarkupIndex((string) ($cards[$cardIndex]['post']['canonical_block_markup'] ?? ''));
                $kind = $this->classifyCardBlock($leaf, $cards[$cardIndex]['post'], $cards[$cardIndex]['route'], $postIndex);
                if (!in_array($kind, array('unbound', 'chrome', 'body'), true)) {
                    if ($optional) return null;
                    continue 2;
                }
                // Do not turn an actual article paragraph into listing metadata.
                foreach ($postIndex->ranges() as $bodyRange) {
                    $body = substr($postIndex->markup, $bodyRange['offset'], $bodyRange['length']);
                    if ('' !== $text && $text === ListingExcerptProjection::text($body)) {
                        if ($optional) return null;
                        continue 3;
                    }
                }
                $shape = array(PostBlocks::sourceAttributes($leaf), $match[1]);
                if (null !== $signature && $signature !== $shape) {
                    if ($optional) return null;
                    continue 2;
                }
                $signature = $shape;
                $values[] = $match[2];
            }
            if (count(array_unique($values)) < 2) continue;
            $key = 'blocks_engine_listing_labels_' . substr(hash('sha256', $first['markup'] . ':' . $index), 0, 12);
            $bound[$first['offset']] = ListingFieldProjection::paragraph($first['markup'], PostBlocks::sourceAttributes($first['markup']), $key, $values[0]);
            foreach ($cards as $cardIndex => $card) $fields[$card['post']['source_path']][$key] = $values[$cardIndex];
        }
        return $bound;
    }

    /** @param array<string,mixed> $card @return list<array{offset:int,markup:string,paragraphs:list<string>}> */
    private function linkedMetadataGroups(\Automattic\BlocksEngine\PhpTransformer\Support\BlockMarkupIndex $index, array $card): array
    {
        $markup = $index->markup;
        $groups = array();
        foreach ($index->ranges() as $range) {
            if ($range['offset'] <= $card['offset'] || $range['offset'] + $range['length'] >= $card['offset'] + $card['length']) continue;
            $slice = substr($markup, $range['offset'], $range['length']);
            if ('group' !== PostBlocks::sourceBlockName($slice)) continue;
            $children = $index->children($range);
            if (array() === $children) continue;
            $paragraphs = array();
            foreach ($children as $child) {
                $paragraph = substr($markup, $child['offset'], $child['length']);
                $anchors = $this->anchors($paragraph);
                if ('paragraph' !== PostBlocks::sourceBlockName($paragraph) || !ListingFieldProjection::isLinkedMetadata($paragraph) || array() === $anchors || ListingExcerptProjection::matches($paragraph, $card['post']) || array_filter($anchors, static fn(array $anchor): bool => self::hrefMatchesRoute($anchor['href'], $card['route']))) continue 2;
                $paragraphs[] = $paragraph;
            }
            $groups[] = array('offset' => $range['offset'], 'markup' => $slice, 'paragraphs' => $paragraphs, 'ranges' => $children);
        }
        return $groups;
    }

    /** @param array<string,mixed> $card */
    private function isExcerptCard(string $markup, array $card): bool
    {
        $description = false;
        $postIndex = new \Automattic\BlocksEngine\PhpTransformer\Support\BlockMarkupIndex($card['post']['canonical_block_markup']);
        $slice = substr($markup, $card['offset'], $card['length']);
        foreach ((new \Automattic\BlocksEngine\PhpTransformer\Support\BlockMarkupIndex($slice))->ranges() as $range) {
            $paragraph = substr($slice, $range['offset'], $range['length']);
            if ('paragraph' !== PostBlocks::sourceBlockName($paragraph)) continue;
            if (ListingExcerptProjection::matches($paragraph, $card['post'])) { $description = true; continue; }
            if ('body' === $this->classifyCardBlock($paragraph, $card['post'], $card['route'], $postIndex) && !ListingFieldProjection::isLinkedMetadata($paragraph)) return false;
        }
        return $description;
    }

    /**
     * @param array{offset:int,length:int} $range
     * @param array<string,mixed> $post
     * @param array<string,true> $sharedText
     * @return array{markup:string,title:bool,content:bool}|null
     */
    private function transformRange(\Automattic\BlocksEngine\PhpTransformer\Support\BlockMarkupIndex $index, \Automattic\BlocksEngine\PhpTransformer\Support\BlockMarkupIndex $postIndex, array $range, array $post, string $route, int $excerptLength = 0, array $boundGroups = array(), bool $linkedContainer = false, array $sharedText = array()): ?array
    {
        $markup = $index->markup;
        $slice = substr($markup, $range['offset'], $range['length']);
        if (isset($boundGroups[$range['offset']])) return array('markup' => $boundGroups[$range['offset']], 'title' => false, 'content' => false);
        $className = (string) (PostBlocks::sourceAttributes($slice)['className'] ?? '');
        if (preg_match('/\b(?:social|share-button|twitter-share)\b/', $className)) {
            return array('markup' => '', 'title' => false, 'content' => false);
        }
        if (preg_match('/\bseparator\b/', $className)) {
            return array('markup' => $slice, 'title' => false, 'content' => false);
        }
        $children = $index->children($range);
        if (isset(PostBlocks::sourceAttributes($slice)['wrappers'])) {
            foreach (PostBlocks::sourceAttributes($slice)['wrappers'] ?? array() as $wrapper) {
                if ('a' === ($wrapper['tagName'] ?? '') && self::hrefMatchesRoute((string) ($wrapper['attributes']['href'] ?? ''), $route)) $linkedContainer = true;
            }
        }
        if (array() === $children) {
            if ('paragraph' === PostBlocks::sourceBlockName($slice) && '' === ListingExcerptProjection::text($slice)) {
                foreach (HtmlMarkupParser::htmlMarkupNodes($slice) as $node) {
                    $identity = $node['attributes'] ?? array();
                    if ('a' === ($node['name'] ?? '') && self::hrefMatchesRoute((string) ($identity['href'] ?? ''), $route) && 'true' === ($identity['aria-hidden'] ?? '') && '-1' === ($identity['tabindex'] ?? '')) {
                        $attrs = array('className' => trim((string) ($identity['class'] ?? '') . ' blocks-engine-listing-overlay'), 'content' => '<span aria-hidden="true"></span>');
                        return array('markup' => '<!-- wp:read-more ' . BlockAttributes::encode($attrs) . ' /-->', 'title' => false, 'content' => false);
                    }
                }
            }
            if ($excerptLength > 0 && 'paragraph' === PostBlocks::sourceBlockName($slice) && ListingExcerptProjection::matches($slice, $post)) {
                $attrs = PostBlocks::sourceAttributes($slice);
                $blockVisibility = $attrs['metadata']['blockVisibility'] ?? null;
                unset($attrs['metadata']);
                if (null !== $blockVisibility) $attrs['metadata']['blockVisibility'] = $blockVisibility;
                $attrs['excerptLength'] = $excerptLength;
                $attrs['className'] = trim((string) ($attrs['className'] ?? '') . ' blocks-engine-authored-excerpt');
                return array('markup' => '<!-- wp:post-excerpt ' . BlockAttributes::encode($attrs) . ' /-->', 'title' => false, 'content' => false);
            }
            $kind = $this->classifyCardBlock($slice, $post, $route, $postIndex);
            // An unbound media leaf cannot be replaced by post-content: the
            // card may own media which the article body does not render.
            if ('body' === $kind && !in_array(PostBlocks::sourceBlockName($slice), array('image', 'gallery', 'video', 'audio', 'embed', 'media-text', 'cover'), true) && preg_match('/<(?:img|video|picture|audio)\b/i', $slice . (string) (PostBlocks::sourceAttributes($slice)['content'] ?? ''))) $kind = 'chrome';
            if ($linkedContainer && 'heading' === PostBlocks::sourceBlockName($slice)) {
                $kind = 'title';
            } elseif ($linkedContainer && 'image' === PostBlocks::sourceBlockName($slice) && null !== PostBlocks::leadImage($slice)) {
                $kind = 'featured-image';
            }
            if ('title' === $kind) {
                return array('markup' => PostBlocks::title($slice), 'title' => true, 'content' => false);
            }
            if ('date' === $kind) {
                return array('markup' => PostBlocks::date($slice), 'title' => false, 'content' => false);
            }
            if ('featured-image' === $kind) {
                return array('markup' => PostBlocks::featuredImage($slice), 'title' => false, 'content' => false);
            }
            if ('unbound' === $kind) {
                // Repeating an unbound per-post literal is no more truthful than
                // rendering the full article in its place. Decline the listing.
                if (!isset($sharedText[self::leafIdentity($slice)])) return null;
                $kind = 'chrome';
            }
            // Media and other textless leaves carry no visible text to compare,
            // so they are shared only when every card renders the same markup.
            if ('chrome' === $kind && !isset($sharedText[self::leafIdentity($slice)])) return null;
            if ('comments' === $kind || 'chrome' === $kind) {
                return array('markup' => $slice, 'title' => false, 'content' => false);
            }
            if ('skip' === $kind) {
                return array('markup' => '', 'title' => false, 'content' => false);
            }
            return array('markup' => '<!-- wp:post-content /-->', 'title' => false, 'content' => true);
        }
        $foundTitle = false;
        $emittedContent = false;
        $replacements = array();
        foreach ($children as $child) {
            $transformed = $this->transformRange($index, $postIndex, $child, $post, $route, $excerptLength, $boundGroups, $linkedContainer, $sharedText);
            if (null === $transformed) return null;
            $foundTitle = $foundTitle || $transformed['title'];
            if ($transformed['content'] && $emittedContent) {
                $replacements[] = array('offset' => $child['offset'], 'length' => $child['length'], 'markup' => '');
                continue;
            }
            if ($transformed['content']) {
                $emittedContent = true;
            }
            $replacements[] = array('offset' => $child['offset'], 'length' => $child['length'], 'markup' => $transformed['markup']);
        }
        usort($replacements, static fn(array $left, array $right): int => $right['offset'] <=> $left['offset']);
        foreach ($replacements as $replacement) {
            $relative = $replacement['offset'] - $range['offset'];
            $slice = substr($slice, 0, $relative) . $replacement['markup'] . substr($slice, $relative + $replacement['length']);
        }
        // Native query slots own their per-post links. A retained source card
        // shell must not wrap them in the first post's static destination.
        $attrs = PostBlocks::sourceAttributes($slice);
        if (isset($attrs['wrappers'])) {
            $changed = false;
            $linkedWrappers = array();
            foreach ($attrs['wrappers'] as $index => &$wrapper) {
                if ('a' === ($wrapper['tagName'] ?? '') && self::hrefMatchesRoute((string) ($wrapper['attributes']['href'] ?? ''), $route)) {
                    $linkedWrappers[$index] = true;
                    $wrapper['tagName'] = 'div';
                    unset($wrapper['attributes']['href'], $wrapper['attributes']['target'], $wrapper['attributes']['rel']);
                    $changed = true;
                }
            }
            unset($wrapper);
            if ($changed) {
                // Rebuild from the declared wrappers and transformed children.
                // Replacing the first <a> with wrapper[0] duplicates outer boxes.
                $sliceIndex = new \Automattic\BlocksEngine\PhpTransformer\Support\BlockMarkupIndex($slice);
                $ranges = $sliceIndex->ranges();
                $inner = '';
                foreach ($sliceIndex->children($ranges[0]) as $child) $inner .= substr($slice, $child['offset'], $child['length']);
                preg_match('/^<!-- wp:([^ ]+)/', $slice, $name);
                foreach (array_reverse($attrs['wrappers'], true) as $index => $wrapper) {
                    $image = self::backgroundImage($wrapper);
                    if (null !== $image && self::postHasLeadImage($post, $image)) {
                        // Keep the authored box/styles on a shell. Core Cover's
                        // flex layout and minimum height would change this box.
                        // A native featured-image slot is positioned as its background.
                        $wrapper['attributes']['style'] = trim(preg_replace('/background-image\\s*:\\s*url\\(.*?\\)\\s*;?/i', '', $wrapper['attributes']['style']) ?? '');
                        $inner = '<!-- wp:post-featured-image {"className":"blocks-engine-listing-background"} /-->' . $inner;
                    }
                    if (isset($linkedWrappers[$index])) $wrapper['attributes']['class'] = trim((string) ($wrapper['attributes']['class'] ?? '') . ' blocks-engine-listing-card');
                    if (isset($linkedWrappers[$index])) $inner .= '<!-- wp:read-more {"className":"blocks-engine-listing-overlay","content":"<span aria-hidden=\\"true\\"></span>"} /-->';
                    $tag = $wrapper['tagName'];
                    $inner = '<!-- wp:' . $name[1] . ' ' . BlockAttributes::encode(array('wrappers' => array($wrapper))) . ' --><' . $tag . self::wrapperAttributes(array('wrappers' => array($wrapper))) . '>' . $inner . '</' . $tag . '><!-- /wp:' . $name[1] . ' -->';
                }
                $slice = $inner;
            }
        }
        return array('markup' => $slice, 'title' => $foundTitle, 'content' => $emittedContent && !$foundTitle);
    }

    /** @param array<string,mixed> $wrapper */
    private static function backgroundImage(array $wrapper): ?string
    {
        $style = (string) ($wrapper['attributes']['style'] ?? '');
        if (!preg_match('/background-image\\s*:\\s*url\\(\\s*["\' ]?([^"\')]+)["\' ]?\\s*\\)/i', $style, $match)) return null;
        return trim($match[1]);
    }

    /** @param array<string,mixed> $post */
    private static function postHasLeadImage(array $post, string $image): bool
    {
        $featured = $post['metadata']['featured_image']['asset_token'] ?? null;
        if (is_string($featured) && WordPressSitePlan::TOKEN_PREFIX . $featured . '}}' === $image) return true;
        foreach ((new \Automattic\BlocksEngine\PhpTransformer\Support\BlockMarkupIndex((string) ($post['canonical_block_markup'] ?? '')))->ranges() as $range) {
            $slice = substr($post['canonical_block_markup'], $range['offset'], $range['length']);
            if ('image' !== PostBlocks::sourceBlockName($slice)) continue;
            $lead = PostBlocks::leadImage($slice);
            return null !== $lead && WordPressSitePlan::TOKEN_PREFIX . $lead['asset_token'] . '}}' === $image;
        }
        return false;
    }

    /** Visible text identifies a card leaf; a textless leaf is identified by its markup. */
    private static function leafIdentity(string $leaf): string
    {
        $text = ListingExcerptProjection::text($leaf);
        return '' !== $text ? $text : "\0" . $leaf;
    }

    /** @param array<string,mixed> $attrs */
    private static function wrapperAttributes(array $attrs): string
    {
        $html = '';
        foreach ($attrs['wrappers'][0]['attributes'] ?? array() as $name => $value) {
            $html .= ' ' . $name . '="' . htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '"';
        }
        return $html;
    }

    /** @param array<string,mixed> $post */
    public function classifyCardBlock(string $slice, array $post, string $route, \Automattic\BlocksEngine\PhpTransformer\Support\BlockMarkupIndex $postIndex): string
    {
        $anchors = $this->anchors($slice);
        foreach ($anchors as $anchor) {
            if (str_contains(strtolower($anchor['href']), '#comment') || preg_match('/\bcomments?\b/i', $anchor['text'])) {
                return 'comments';
            }
        }
        $hasRouteLink = false;
        foreach ($anchors as $anchor) {
            if (self::hrefMatchesRoute($anchor['href'], $route)) {
                $hasRouteLink = true;
                break;
            }
        }
        $name = PostBlocks::sourceBlockName($slice);
        if ($hasRouteLink && in_array($name, array('heading', 'post-title'), true)) {
            return 'title';
        }
        $timestamp = is_string($post['publication_timestamp'] ?? null) ? $post['publication_timestamp'] : null;
        // The entire visible leaf matching this post's timestamp is sufficient
        // evidence, even when the date class lives on a surrounding group.
        if (null !== $timestamp && PublicationDate::fromVisibleText(ListingExcerptProjection::text($slice)) === $timestamp) {
            return 'date';
        }
        foreach (HtmlMarkupParser::htmlMarkupNodes($slice) as $node) {
            if ('tag' !== ($node['kind'] ?? null) || !is_int($node['inner_offset'] ?? null)) {
                continue;
            }
            $attributes = ArrayField::array($node, 'attributes');
            $datedTime = null !== $timestamp && 'time' === ($node['name'] ?? '') && is_string($attributes['datetime'] ?? null);
            // A machine-readable datetime proves the slot even when the visible
            // text only carries the day of a timestamp that has a time of day.
            // The text must still be a date: post-date renders a date, so a
            // time of day stamped with the post's day (an event's start time)
            // stays authored content.
            if ($datedTime && PublicationDate::normalize((string) $attributes['datetime']) === $timestamp
                && null !== PublicationDate::fromVisibleText(HtmlMarkupParser::innerText($slice, 'time', $node['inner_offset']))) {
                return 'date';
            }
            if (!PublicationDate::isVisibleElement((string) ($node['name'] ?? ''), $attributes) && !$datedTime) {
                continue;
            }
            $parsed = PublicationDate::fromVisibleText(HtmlMarkupParser::innerText($slice, (string) $node['name'], $node['inner_offset']));
            if (null !== $parsed && (null === $timestamp || $parsed === $timestamp)) {
                return 'date';
            }
        }
        if (preg_match('/<(?:iframe|fb:like)\b/i', $slice) || preg_match('/\b(?:blog-social|share-button|twitter-share)\b/', $slice)) {
            return 'skip';
        }
        if ('image' === $name && $hasRouteLink && null !== PostBlocks::leadImage($slice)) {
            return 'featured-image';
        }
        if (in_array($name, array('image', 'gallery', 'video', 'audio', 'embed', 'media-text', 'cover'), true) || preg_match('/<(?:img|video|picture|audio)\b/i', $slice . (string) (PostBlocks::sourceAttributes($slice)['content'] ?? ''))) {
            return 'body';
        }
        $text = trim(preg_replace('/\s+/', ' ', html_entity_decode(strip_tags($slice), ENT_QUOTES | ENT_HTML5, 'UTF-8')) ?? '', " \t\n\r\0\x0B\xC2\xA0");
        // A separator class marks a divider only while it renders no words;
        // Blogger also names authored text wrappers "separator".
        if ('' === $text || in_array($name, array('separator', 'spacer'), true) || (preg_match('/\bseparator\b/', (string) (PostBlocks::sourceAttributes($slice)['className'] ?? '')) && !preg_match('/[\p{L}\p{N}]/u', $text))) {
            return 'chrome';
        }
        // Recognize actual article leaves before considering unbound card text.
        $postMarkup = $postIndex->markup;
        foreach ($postIndex->ranges() as $range) {
            if (array() !== $postIndex->children($range)) continue;
            if (ListingExcerptProjection::text(substr($postMarkup, $range['offset'], $range['length'])) === ListingExcerptProjection::text($slice)) return 'body';
        }
        // Keep the legacy content-listing contract for paragraphs without an
        // authored summary. Summary cards must never infer body ownership from
        // an otherwise unrecognized text leaf.
        return ListingExcerptProjection::matches($slice, $post) || ('paragraph' === $name && null === ListingExcerptProjection::description($post)) ? 'body' : 'unbound';
    }

    /** @param array<int,array<string,mixed>> $posts */
    public function replaceArchives(string $markup, array $posts): string
    {
        $months = $this->postMonths($posts);
        if (array() === $months) {
            return $markup;
        }
        $best = null;
        foreach ((new \Automattic\BlocksEngine\PhpTransformer\Support\BlockMarkupIndex($markup))->ranges() as $range) {
            $slice = substr($markup, $range['offset'], $range['length']);
            if (str_contains($slice, '<!-- wp:query') || str_contains($slice, '<!-- wp:post-template') || str_contains($slice, '<!-- wp:archives')) {
                continue;
            }
            $labels = array();
            $other = false;
            foreach ($this->anchors($slice) as $anchor) {
                if (1 === preg_match('/^[A-Z][a-z]+ \d{4}$/', $anchor['text'])) {
                    $labels[$anchor['text']] = true;
                    continue;
                }
                $other = true;
            }
            ksort($labels);
            if ($other || $labels !== $months) {
                continue;
            }
            if (null === $best || $range['length'] < $best['length']) {
                $best = $range;
            }
        }
        if (null === $best) {
            return $markup;
        }
        return substr($markup, 0, $best['offset']) . '<!-- wp:archives /-->' . substr($markup, $best['offset'] + $best['length']);
    }

    /** @param array<int,array<string,mixed>> $posts @return array<string,true> */
    private function postMonths(array $posts): array
    {
        $months = array();
        foreach ($posts as $post) {
            if (!is_string($post['publication_timestamp'] ?? null)) {
                continue;
            }
            try {
                $date = new \DateTimeImmutable($post['publication_timestamp']);
            } catch (\Exception) {
                continue;
            }
            $months[$date->setTimezone(new \DateTimeZone('UTC'))->format('F Y')] = true;
        }
        ksort($months);
        return $months;
    }

    /** @return array<int,array{href:string,text:string}> */
    private function anchors(string $html): array
    {
        // Card matching asks for every block range's anchors once per post
        // route, and listing passes revisit the same slices.
        return $this->anchorsByMarkup[hash('xxh128', $html)] ??= self::parseAnchors($html);
    }

    /** @return list<array{href:string,text:string}> */
    private static function parseAnchors(string $html): array
    {
        $anchors = array();
        foreach (HtmlMarkupParser::htmlMarkupNodes($html) as $node) {
            if ('tag' !== ($node['kind'] ?? null) || 'a' !== ($node['name'] ?? null) || !is_string($node['attributes']['href'] ?? null)) {
                continue;
            }
            $href = html_entity_decode($node['attributes']['href'], ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $text = is_int($node['inner_offset'] ?? null) ? HtmlMarkupParser::innerText($html, 'a', $node['inner_offset']) : '';
            $anchors[] = array('href' => $href, 'text' => $text);
        }
        return $anchors;
    }

    /** @param array<int,string> $routes */
    private static function hrefMatchesRoute(string $href, string $route): bool
    {
        return in_array($route, self::routeKeys($href), true);
    }

    /** @return list<string> The listing route paths an href addresses. */
    private static function routeKeys(string $href): array
    {
        $path = parse_url($href, PHP_URL_PATH);
        if (!is_string($path) || '' === $path) {
            $path = $href;
        }
        $path = '/' . trim($path, '/');
        return str_ends_with($path, '/index.html') ? array($path, substr($path, 0, -11)) : array($path);
    }
}
