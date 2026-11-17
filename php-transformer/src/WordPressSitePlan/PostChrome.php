<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan;

use Automattic\BlocksEngine\PhpTransformer\Support\ArrayField;
use Automattic\BlocksEngine\PhpTransformer\Support\BlockAttributes;
use Automattic\BlocksEngine\PhpTransformer\Contract\CaptureEvidence;
use Automattic\BlocksEngine\PhpTransformer\Support\HtmlTagScanner;
use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\RuntimeEntityManifest;

/**
 * Moves the chrome that every post repeats out of post content and into the
 * single-post template.
 *
 * Captured posts each carry the same byline, share bar, related-posts section
 * or comment form around their body. Rendering that chrome once from the
 * template, with core post blocks bound to each post, keeps post content to
 * the article itself and lets WordPress render the rest per post.
 *
 * One instance serves one site plan run.
 */
final class PostChrome
{
    /** URL attributes in HTML; serialized block JSON additionally uses "url". */
    private const URL_ATTRIBUTES = 'src|href|srcset|poster|action|formaction|cite|data|xlink:href';
    /**
     * Related-section card projections by shared heading, for the post group
     * being factored. A projection reads every post in the group, so it is
     * built once per group rather than once per post.
     *
     * @var array<string,array{template:string,fields:array<string,array<string,mixed>>,featured_images:array<string,array<string,mixed>>}|null>
     */
    private array $relatedSectionProjections = array();

    /**
     * Related-section term scopes by shared heading, then by post source
     * path. Decided once over a whole template's posts, before layout groups
     * split it, so each term's scoped query gives its posts their own group.
     *
     * @var array<string,array<string,array{taxonomy:string,slug:string,order_by:string,per_page:int}>|null>
     */
    private array $relatedTermScopes = array();

    /** @var array<int,array<string,mixed>> The plan's taxonomy entities. */
    private array $taxonomyEntities = array();

    /** Whether every post renders one single template, with no layout groups. */
    private bool $uniformTemplate = false;

    /** @var list<array<string,mixed>> Original site posts, across layout groups. */
    private array $allPosts = array();

    /** @var array<string,array<string,mixed>> Fields committed by successful projections. */
    private array $projectedFields = array();

    /** @var array<string,array<string,mixed>> Related-card images from successfully factored groups. */
    private array $projectedFeaturedImages = array();

    /** @var array<string,array<string,mixed>> Findings from the latest document pass only. */
    private array $orderRetained = array();

    /**
     * @param \Closure(string):string $chromeIdentity The site plan's shared chrome identity of a block.
     * @param \Closure(array<string,mixed>):void $recordFinding Records a unit the plan kept or rewrote instead of failing.
     */
    public function __construct(private readonly ListingQueryLoops $listings, private readonly \Closure $chromeIdentity, private readonly \Closure $recordFinding)
    {
    }

    /**
     * @param array<int,array<string,mixed>> $pages
     * @return array<int,array<string,mixed>>
     */
    public function extractCommentForms(array $pages): array
    {
        // Only factor a shared post primitive when every captured post has it
        // and nothing renderable follows any occurrence in its original context.
        $pattern = '~<!-- wp:comments -->\\s*<div class="wp-block-comments">\\s*<!-- wp:post-comments-form /-->\\s*</div>\\s*<!-- /wp:comments -->~';
        $posts = array_filter($pages, static fn(array $page): bool => 'post' === ($page['post_type'] ?? null) && empty($page['synthetic']));
        if (array() === $posts) return $pages;
        $closingOnly = '~\A(?:\s|<!--\s*/wp:[a-zA-Z0-9_/-]+\s*-->|</[a-zA-Z][a-zA-Z0-9:-]*\s*>)*\z~';
        foreach ($posts as $post) {
            $markup = $post['canonical_block_markup'];
            if (!preg_match_all($pattern, $markup, $matches, PREG_OFFSET_CAPTURE)) return $pages;
            foreach ($matches[0] as [$form, $offset]) {
                if (1 !== preg_match($closingOnly, substr($markup, $offset + strlen($form)))) return $pages;
            }
        }
        foreach ($posts as $index => $post) {
            $pages[$index]['canonical_block_markup'] = preg_replace($pattern, '', $post['canonical_block_markup']);
            $pages[$index]['metadata']['native_comment_form'] = true;
            $pages[$index]['content_hash'] = WordPressSitePlan::contentHash($pages[$index]['canonical_block_markup']);
        }
        return $pages;
    }

    /**
     * A captured WordPress entry-content boundary is stronger evidence than
     * cross-post similarity: everything outside it belongs to the template.
     * WordPress post primitives belong to the shared single lifecycle. Responsive
     * document copies keep their visibility wrappers around article bodies only.
     */
    public function extractBlockThemeChrome(array $pages, array $declarations, array $records): array
    {
        $templates = array();
        $shells = array();
        $capturedComments = null;
        foreach ($pages as $page) {
            if ('post' !== ($page['post_type'] ?? null)) continue;
            $source = $page['canonical_block_markup'];
            foreach ((new \Automattic\BlocksEngine\PhpTransformer\Support\BlockMarkupIndex($source))->ranges() as $range) {
                $slice = substr($source, $range['offset'], $range['length']);
                if (str_starts_with($slice, '<!-- wp:comments') && str_contains($slice, '<!-- wp:comment-template')) {
                    $capturedComments = $slice;
                    break 2;
                }
            }
        }
        // Prefer a unified document with both navigation directions as the
        // chrome owner, not an endpoint whose absent neighbor rendered empty.
        $ordered = $pages;
        uasort($ordered, static fn(array $a, array $b): int => (substr_count($a['canonical_block_markup'], 'wp-block-post-content') <=> substr_count($b['canonical_block_markup'], 'wp-block-post-content')) ?: (substr_count($b['canonical_block_markup'], '<!-- wp:post-navigation-link ') <=> substr_count($a['canonical_block_markup'], '<!-- wp:post-navigation-link ')));
        foreach ($ordered as $index => $post) {
            if ('post' !== ($post['post_type'] ?? null)) continue;
            $markup = $post['canonical_block_markup'];
            $markupIndex = new \Automattic\BlocksEngine\PhpTransformer\Support\BlockMarkupIndex($markup);
            $contents = array();
            foreach ($markupIndex->ranges() as $range) {
                $slice = substr($markup, $range['offset'], $range['length']);
                $attrs = PostBlocks::sourceAttributes($slice);
                if (in_array('wp-block-post-content', preg_split('/\s+/', (string) ($attrs['className'] ?? '')) ?: array(), true)) $contents[] = $range;
            }
            if (array() === $contents) continue;
            if (count($contents) > 1) {
                // A shared template renders post-content once. Retain each
                // captured document's visibility owner, but only its article
                // children, so desktop/mobile never duplicate the site shell.
                if (!isset($templates['single'])) continue;
                $bodies = array();
                $articles = array();
                $retained = array();
                foreach ($contents as $content) {
                    $children = $markupIndex->children($content);
                    if (array() === $children) continue;
                    $start = $children[0]['offset'];
                    $last = $children[count($children) - 1];
                    $length = $last['offset'] + $last['length'] - $start;
                    $owner = null;
                    foreach ($markupIndex->ranges() as $candidate) {
                        if ($candidate['offset'] >= $content['offset'] || $candidate['offset'] + $candidate['length'] <= $content['offset']) continue;
                        $slice = substr($markup, $candidate['offset'], $candidate['length']);
                        // The generated-block namespace is consumer-owned.
                        if (1 === preg_match('~^<!-- wp:[a-z0-9-]+/layout-shell ~', $slice) && array_key_exists(CaptureEvidence::DOCUMENT_SCOPE, PostBlocks::sourceAttributes($slice)['wrappers'][0]['attributes'] ?? array())) {
                            $owner = $candidate;
                            break;
                        }
                    }
                    if (null === $owner) continue;
                    $siblings = $markupIndex->children($owner);
                    if (array() === $siblings) continue;
                    $first = $siblings[0]['offset'];
                    $lastSibling = $siblings[count($siblings) - 1];
                    $end = $lastSibling['offset'] + $lastSibling['length'];
                    $articles[] = substr($markup, $start, $length);
                    $bodies[] = substr($markup, $owner['offset'], $first - $owner['offset']) . $articles[count($articles) - 1] . substr($markup, $end, $owner['offset'] + $owner['length'] - $end);
                    $retained[] = array('offset' => $start, 'length' => $length);
                }
                if (count($bodies) !== count($contents) || $this->factoringStrandsBindings($post, $retained, $declarations, $records)) continue;
                $pages[$index]['route_template'] = 'single';
                // Compare the same non-semantic per-document capture identity
                // as shell extraction, but retain the desktop payload verbatim
                // so its authored blocks and generated geometry keep their CSS.
                $identities = array_map(ChromeIdentity::document(...), $articles);
                $pages[$index]['canonical_block_markup'] = 1 === count(array_unique($identities)) ? $articles[0] : implode("\n", $bodies);
                $pages[$index]['content_hash'] = WordPressSitePlan::contentHash($pages[$index]['canonical_block_markup']);
                continue;
            }
            $content = $contents[0];
            $children = $markupIndex->children($content);
            if (array() === $children) continue;
            $start = $children[0]['offset'];
            $last = $children[count($children) - 1];
            $length = $last['offset'] + $last['length'] - $start;
            $retained = array(array('offset' => $start, 'length' => $length));
            if ($this->factoringStrandsBindings($post, $retained, $declarations, $records)) continue;
            $attrs = PostBlocks::sourceAttributes(substr($markup, $content['offset'], $content['length']));
            $classes = preg_split('/\s+/', trim((string) ($attrs['className'] ?? ''))) ?: array();
            $attrs['className'] = implode(' ', array_diff($classes, array('wp-block-post-content')));
            if (array_intersect($classes, array('has-global-padding', 'is-layout-constrained'))) $attrs['layout'] = array('type' => 'constrained');
            unset($attrs['tagName']);
            $template = substr_replace($markup, '<!-- wp:post-content ' . json_encode($attrs, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . ' /-->', $content['offset'], $content['length']);
            // Reuse the normal title/date conversion, but never infer article
            // body from arbitrary chrome siblings once WordPress named it.
            $replacements = array();
            $templateIndex = new \Automattic\BlocksEngine\PhpTransformer\Support\BlockMarkupIndex($template);
            foreach ($templateIndex->ranges() as $range) {
                $slice = substr($template, $range['offset'], $range['length']);
                if (array() !== $templateIndex->children($range)) continue;
                $kind = $this->classifyBlock($slice, $post, (string) ($post['route']['path'] ?? ''), $markupIndex);
                if (preg_match('/<time\b[^>]*datetime=/i', $slice)) $kind = 'date';
                if ('title' === $kind || 'date' === $kind) $replacements[] = $range + array('markup' => 'title' === $kind ? PostBlocks::title($slice) : PostBlocks::date($slice));
            }
            usort($replacements, static fn(array $a, array $b): int => $b['offset'] <=> $a['offset']);
            foreach ($replacements as $replacement) $template = substr_replace($template, $replacement['markup'], $replacement['offset'], $replacement['length']);
            $slug = 'single';
            $pages[$index]['route_template'] = $slug;
            $pages[$index]['canonical_block_markup'] = substr($markup, $start, $length);
            $pages[$index]['content_hash'] = WordPressSitePlan::contentHash($pages[$index]['canonical_block_markup']);
            // The first complete block-theme document supplies the shared
            // lifecycle, rather than freezing each post's captured date,
            // navigation availability and responsive header variant in a
            // route-specific template.
            if (isset($templates[$slug])) continue;
            $templates[$slug] = $template;
            preg_match_all('/<!-- wp:template-part (\{.*?\}) \/-->/', $template, $matches);
            $shells[$slug] = array_map(static fn(string $json): string => (string) (json_decode($json, true)['slug'] ?? ''), $matches[1]);
        }
        if (null !== $capturedComments && isset($templates['single'])) {
            $template = $templates['single'];
            $replacements = array();
            foreach ((new \Automattic\BlocksEngine\PhpTransformer\Support\BlockMarkupIndex($template))->ranges() as $range) {
                $slice = substr($template, $range['offset'], $range['length']);
                if (preg_match('/^<!-- wp:comments(?:\s|-->)/', $slice)) $replacements[] = $range;
            }
            if (array() === $replacements) $template .= $capturedComments;
            else foreach (array_reverse($replacements) as $range) $template = substr_replace($template, $capturedComments, $range['offset'], $range['length']);
            $templates['single'] = $template;
        }
        return array('pages' => $pages, 'templates' => $templates, 'shells' => $shells);
    }

    /**
     * @param array<int,array<string,mixed>> $pages
     * @param array<int,array<string,mixed>> $parts
     * @param array<int,array<string,mixed>> $runtimeDeclarations
     * @param array<int,array<string,mixed>> $runtimeEntityRecords
     * @return array{pages:array<int,array<string,mixed>>,single:?string}
     */
    public function extractArticleChrome(array $pages, array $parts, array $runtimeDeclarations = array(), array $runtimeEntityRecords = array(), ?array $templates = null, array $taxonomyEntities = array()): array
    {
        $this->allPosts = array_values(array_filter($pages, static fn(array $page): bool => 'post' === ($page['post_type'] ?? null) && empty($page['synthetic'])));
        $this->projectedFields = array();
        $this->projectedFeaturedImages = array();
        $this->orderRetained = array();
        $this->taxonomyEntities = $taxonomyEntities;
        $this->relatedTermScopes = array();
        $this->uniformTemplate = null === $templates;
        if (null === $templates) {
            $result = $this->factorArticleChrome($pages, $parts, $runtimeDeclarations, $runtimeEntityRecords);
            foreach ($this->orderRetained as $finding) ($this->recordFinding)($finding);
            return $result;
        }
        $bySlug = array_column($templates, null, 'slug');
        $bound = array();
        foreach ($pages as $index => $page) {
            if ('post' !== ($page['post_type'] ?? null) || !empty($page['synthetic'])) continue;
            $slug = $page['route_template'] ?? 'single';
            if (!isset($bySlug[$slug]) || isset($bySlug[$slug]['source_path']) || str_contains($bySlug[$slug]['canonical_block_markup'], '<!-- wp:post-title')) continue;
            $bound[$slug][$index] = $page;
        }
        foreach ($bound as $slug => $posts) {
            // Peers establish article boundaries even when optional chrome
            // later divides this template's posts into singleton layouts.
            if (count($posts) < 2) continue;
            $shared = $this->sharedIdentities($posts);
            $this->relatedSectionProjections = array();
            $this->relatedTermScopes = array();
            $groups = array();
            $finalFindings = $this->orderRetained;
            $hasUntitled = array() !== array_filter($posts, fn(array $post): bool => $this->hasDeclaredEmptyTitle($post));
            foreach ($posts as $index => $post) {
                $transformed = $this->transformDocument($post['canonical_block_markup'], $post, $post['route']['path'] ?? '', $shared, null, $posts);
                // Editor labels describe the source article, not its layout.
                $identityMarkup = preg_replace_callback('/<!-- wp:([a-z0-9\/-]+) (\{.*?\})(\s*\/?)-->/s', static function (array $match): string {
                    $attrs = json_decode($match[2], true);
                    if (!is_array($attrs)) return $match[0];
                    unset($attrs['metadata']);
                    return '<!-- wp:' . $match[1] . ' ' . BlockAttributes::encode($attrs) . ($match[3] ?? '') . '-->';
                }, $transformed['template']) ?? $transformed['template'];
                // Share actions may embed the current post URL; that is slot
                // data, not a different article structure.
                $identityMarkup = preg_replace_callback('/<!-- wp:social-link (\{.*?\}) \/-->/s', static function (array $match): string {
                    $attrs = json_decode($match[1], true);
                    if (!is_array($attrs)) return $match[0];
                    unset($attrs['url']);
                    return '<!-- wp:social-link ' . BlockAttributes::encode($attrs) . ' /-->';
                }, $identityMarkup) ?? $identityMarkup;
                // URL values are per-post data, not layout. Normalize both
                // rendered attributes and their serialized block counterparts;
                // keep element names, classes and tree shape significant.
                $identityMarkup = preg_replace('/(\s(?:' . self::URL_ATTRIBUTES . ')\s*=\s*)(?:"[^"]*"|\'[^\']*\'|[^\s>]+)/i', '$1""', $identityMarkup) ?? $identityMarkup;
                $identityMarkup = preg_replace('/("(?:url|' . self::URL_ATTRIBUTES . ')"\s*:\s*)"(?:\\\\.|[^"\\\\])*"/i', '$1""', $identityMarkup) ?? $identityMarkup;
                // An absent title on an explicitly untitled post is not a
                // different layout. The titled peer supplies the title slot.
                if ($hasUntitled) $identityMarkup = preg_replace('/<!-- wp:post-title(?:\s+\{.*?\})?\s*\/-->/', '', $identityMarkup) ?? $identityMarkup;
                $identity = ChromeIdentity::markup($identityMarkup);
                $groups[$identity][$index] = $post;
            }
            // Grouping is speculative; only the final factoring pass owns findings.
            $this->orderRetained = $finalFindings;
            foreach ($groups as $identity => $group) {
                // Peer evidence is only valid for a missing whole section, not
                // for a replacement menu, widget or header. A singleton keeps
                // its own template, but one layout must equal the other with
                // complete blocks removed and every remaining byte unchanged.
                // Either layout may be richer: a singleton can supply the
                // optional-section evidence for the smaller peer group.
                if (1 === count($group)) {
                    $matches = false;
                    foreach ($groups as $peerIdentity => $peerGroup) {
                        if ($peerIdentity !== $identity && (self::identityWithoutSections($identity, $peerIdentity) || self::identityWithoutSections($peerIdentity, $identity))) {
                            $matches = true;
                            break;
                        }
                    }
                    if (!$matches) continue;
                }
                $factored = $this->factorArticleChrome($group, $parts, $runtimeDeclarations, $runtimeEntityRecords, $posts);
                if (null === $factored['single']) continue;
                // A partial group cannot change the template used by the other
                // layouts (or by posts whose complete article must be retained).
                $target = count($group) === count($posts) ? $slug : 'single-post-article-' . substr(hash('sha256', $slug . "\0" . $identity), 0, 12);
                $template = $bySlug[$slug];
                $template['slug'] = $target;
                $template['target_path'] = 'templates/' . $target . '.html';
                $template['reconciliation_identity'] = WordPressSitePlan::identity('template', 'wordpress-site-plan/' . $template['target_path'], $template['target_path']);
                $template['canonical_block_markup'] = preg_replace_callback('/<!-- wp:post-content(?:\s+\{.*?\})?\s*\/-->/', static fn(array $match): string => $factored['single'], $template['canonical_block_markup'], 1);
                $template['content_hash'] = WordPressSitePlan::contentHash($template['canonical_block_markup']);
                $bySlug[$target] = $template;
                foreach ($factored['pages'] as $index => $page) {
                    $pages[$index] = $page;
                    if ($target === $slug) continue;
                    $pages[$index]['route_template'] = $target;
                    $pages[$index]['metadata']['post_meta']['_wp_page_template'] = $target;
                }
                if ($target === $slug) continue;
                foreach ($parts as &$part) {
                    foreach (array('template_slugs', 'excluded_template_slugs') as $field) {
                        if (in_array($slug, $part['placement'][$field] ?? array(), true)) $part['placement'][$field][] = $target;
                    }
                    if (isset($part['placement']['template_wrappers'][$slug])) $part['placement']['template_wrappers'][$target] = $part['placement']['template_wrappers'][$slug];
                }
                unset($part);
            }
        }
        foreach ($pages as $index => $page) {
            $source = $page['source_path'] ?? '';
            if (!isset($page['metadata']['featured_image']) && isset($this->projectedFeaturedImages[$source])) $pages[$index]['metadata']['featured_image'] = $this->projectedFeaturedImages[$source];
            if (isset($this->projectedFields[$source])) $pages[$index]['metadata']['post_meta'] = ($page['metadata']['post_meta'] ?? array()) + $this->projectedFields[$source];
        }
        foreach ($this->orderRetained as $finding) ($this->recordFinding)($finding);
        return array('pages' => $pages, 'parts' => $parts, 'templates' => array_values($bySlug));
    }

    /** Whether removing whole blocks from a layout yields this post's identity. */
    private static function identityWithoutSections(string $post, string $layout): bool
    {
        if ($post === $layout) return true;
        $postIndex = new \Automattic\BlocksEngine\PhpTransformer\Support\BlockMarkupIndex($post);
        $layoutIndex = new \Automattic\BlocksEngine\PhpTransformer\Support\BlockMarkupIndex($layout);
        // Compare the literal frame around children as well as their ordered
        // identities. Descending permits an absent section inside a wrapper,
        // but never permits different text or attributes in a retained block.
        $matches = static function (array $postChildren, array $layoutChildren, string $postFrame, string $layoutFrame) use ($postIndex, $layoutIndex): bool {
            foreach (array_reverse($postChildren) as $child) $postFrame = substr_replace($postFrame, '', $child['offset'], $child['length']);
            foreach (array_reverse($layoutChildren) as $child) $layoutFrame = substr_replace($layoutFrame, '', $child['offset'], $child['length']);
            if (trim($postFrame) !== trim($layoutFrame)) return false;
            $cursor = 0;
            foreach ($postChildren as $child) {
                $slice = substr($postIndex->markup, $child['offset'], $child['length']);
                $found = false;
                while (isset($layoutChildren[$cursor])) {
                    $candidate = $layoutChildren[$cursor++];
                    if (self::identityWithoutSections($slice, substr($layoutIndex->markup, $candidate['offset'], $candidate['length']))) {
                        $found = true;
                        break;
                    }
                }
                if (!$found) return false;
            }
            return true;
        };
        $postRoots = $postIndex->topLevel();
        $layoutRoots = $layoutIndex->topLevel();
        // Unwrap one root to compare its children without recursing on itself.
        if (1 === count($postRoots) && 1 === count($layoutRoots) && 0 === $postRoots[0]['offset'] && strlen($post) === $postRoots[0]['length'] && 0 === $layoutRoots[0]['offset'] && strlen($layout) === $layoutRoots[0]['length']) {
            return $matches($postIndex->children($postRoots[0]), $layoutIndex->children($layoutRoots[0]), $post, $layout);
        }
        return $matches($postRoots, $layoutRoots, $post, $layout);
    }

    private function hasDeclaredEmptyTitle(array $post): bool
    {
        return '' === ($post['metadata']['declared_content']['title'] ?? null);
    }

    private function factorArticleChrome(array $pages, array $parts, array $runtimeDeclarations, array $runtimeEntityRecords, array $peers = array()): array
    {
        $posts = array();
        $indexes = array();
        foreach ($pages as $index => $page) {
            if ('post' !== ($page['post_type'] ?? null) || !empty($page['synthetic']) || !is_string($page['canonical_block_markup'] ?? null) || '' === trim($page['canonical_block_markup'])) {
                continue;
            }
            $posts[] = $page;
            $indexes[] = $index;
        }
        if (count($posts) < 2 && count($peers) < 2) {
            return array('pages' => $pages, 'single' => null);
        }
        // Inline shell
        // references belong to the source documents, including their wrappers.
        // Keep the entire article in post-content rather than hoisting those
        // references (or splitting their authored ancestor context).
        foreach ($parts as $part) {
            if ('inline_shared_shell' !== ($part['placement']['kind'] ?? null)) continue;
            foreach ($posts as $post) {
                if (str_contains($post['canonical_block_markup'], '"slug":"' . $part['slug'] . '"')) {
                    return array('pages' => $pages, 'single' => null);
                }
            }
        }
        // A singleton layout may still have a proven article boundary among
        // its peers. Keep its own template rather than copying optional chrome
        // that cannot render empty for this post.
        $evidence = count($posts) < 2 ? $peers : $posts;
        $shared = $this->sharedIdentities($evidence);
        $this->relatedSectionProjections = array();
        // Only promote a common lead-image slot when every post owns one
        // ahead of its body, and the asset actually varies between posts.
        $documents = array();
        $leadImages = array();
        foreach ($posts as $postIndex => $post) {
            $route = (string) ($post['route']['path'] ?? '');
            $documents[$postIndex] = $this->transformDocument($post['canonical_block_markup'], $post, $route, $shared, null, $evidence);
            $retained = $documents[$postIndex]['retained'];
            if (count($retained) < 2) continue;
            $lead = $retained[0];
            $image = PostBlocks::leadImage(substr($post['canonical_block_markup'], $lead['offset'], $lead['length']));
            if (null !== $image) $leadImages[$postIndex] = $image;
        }
        // Prefer the depth-bounded encoding regardless of source post order.
        // Equivalent native wrapper chains compare equal, but must not replace
        // a folded template required by a deeper member's content.
        uasort($documents, static fn(array $a, array $b): int => preg_match_all('~<!-- wp:[a-z0-9-]+/layout-shell ~', $b['template']) <=> preg_match_all('~<!-- wp:[a-z0-9-]+/layout-shell ~', $a['template']));
        $promoteLead = count($leadImages) === count($posts) && count(array_unique(array_column($leadImages, 'asset_token'))) > 1;
        $single = null;
        $singleHasTitle = false;
        $singleMenus = array();
        $bodies = array();
        $postMeta = array();
        $featuredImages = array();
        foreach ($documents as $postIndex => $transformed) {
            $post = $posts[$postIndex];
            $route = is_string($post['route']['path'] ?? null) ? $post['route']['path'] : '';
            if ($promoteLead) {
                $transformed = $this->transformDocument($post['canonical_block_markup'], $post, $route, $shared, $transformed['retained'][0]['offset'], $posts);
            }
            if ((!$transformed['title'] && !$this->hasDeclaredEmptyTitle($post)) || (!$transformed['date'] && !$transformed['shared']) || '' === trim($transformed['body']) || 1 !== substr_count($transformed['template'], '<!-- wp:post-content')) {
                return array('pages' => $pages, 'single' => null);
            }
            // A runtime entity binding anchors one exact block of the document
            // its declaration still owns. Factoring keeps only post-content
            // there, so when an anchored region would move into the single
            // template the complete articles stay page-owned rather than
            // strand the binding on a source path that no longer renders it.
            if ($this->factoringStrandsBindings($post, $transformed['retained'], $runtimeDeclarations, $runtimeEntityRecords)) {
                return array('pages' => $pages, 'single' => null);
            }
            if (null === $single || (!$singleHasTitle && $transformed['title'])) {
                $singleHasTitle = $transformed['title'];
                $single = preg_replace_callback('/<!-- wp:([a-z0-9\/-]+) (\{.*?\})(\s*\/?)-->/s', static function (array $match) use ($posts): string {
                    $attrs = json_decode($match[2], true);
                    if (!is_array($attrs)) return $match[0];
                    foreach ($posts as $peer) if (($attrs['metadata']['name'] ?? null) === ($peer['title'] ?? null)) unset($attrs['metadata']['name']);
                    return '<!-- wp:' . $match[1] . ' ' . BlockAttributes::encode($attrs) . ($match[3] ?? '') . '-->';
                }, $this->listings->replaceArchives($transformed['template'], $posts)) ?? $transformed['template'];
                $singleMenus = NavigationEntityProjection::renderedMenus($single);
            }
            // After factoring, a post renders only the single template and its
            // body. Every menu the post rendered before must still be rendered
            // by one of them; otherwise it would be lost (and its entity left
            // with no reference), so the articles stay page-owned.
            $keptMenus = $singleMenus + NavigationEntityProjection::renderedMenus($transformed['body']);
            if (array() !== array_diff_key(NavigationEntityProjection::renderedMenus($post['canonical_block_markup']), $keptMenus)) {
                return array('pages' => $pages, 'single' => null);
            }
            $bodies[$indexes[$postIndex]] = $transformed['body'];
            if (null !== ($transformed['featured'] ?? null)) $featuredImages[$indexes[$postIndex]] = $transformed['featured'];
            foreach ($transformed['fields'] ?? array() as $source => $values) $postMeta[$source] = ($postMeta[$source] ?? array()) + $values;
        }
        // Commit fields only once the whole group can be factored. Related
        // cards can own fields on posts outside this layout group.
        foreach ($postMeta as $source => $values) $this->projectedFields[$source] = ($this->projectedFields[$source] ?? array()) + $values;
        foreach ($this->relatedSectionProjections as $projection) {
            foreach ($projection['featured_images'] ?? array() as $source => $image) $this->projectedFeaturedImages[$source] = $image;
        }
        foreach ($bodies as $index => $body) {
            $pages[$index]['canonical_block_markup'] = $body;
            $pages[$index]['content_hash'] = WordPressSitePlan::contentHash($body);
            if (isset($postMeta[$pages[$index]['source_path']])) $pages[$index]['metadata']['post_meta'] = ($pages[$index]['metadata']['post_meta'] ?? array()) + $postMeta[$pages[$index]['source_path']];
            if ($promoteLead) $pages[$index]['metadata']['featured_image'] = $leadImages[array_search($index, $indexes, true)];
            elseif (isset($featuredImages[$index])) $pages[$index]['metadata']['featured_image'] = $featuredImages[$index];
        }
        return array('pages' => $pages, 'single' => $single);
    }

    /**
     * Whether factoring this post's article chrome would move a runtime entity
     * binding's anchored region out of the document its declaration owns. The
     * body keeps only the retained post-content ranges, so a bound region that
     * survives in none of them would strand.
     *
     * @param array<string,mixed> $post
     * @param array<int,array{offset:int,length:int}> $retained
     * @param array<int,array<string,mixed>> $declarations
     * @param array<int,array<string,mixed>> $records
     */
    private function factoringStrandsBindings(array $post, array $retained, array $declarations, array $records): bool
    {
        $ranges = $this->postBoundBlockRanges($declarations, $records, $post);
        if (array() === $ranges) return false;
        foreach ($ranges as $range) {
            foreach ($retained as $kept) {
                if ($kept['offset'] <= $range['offset'] && $range['offset'] + $range['length'] <= $kept['offset'] + $kept['length']) continue 2;
            }
            return true;
        }
        return false;
    }

    /**
     * Byte ranges of a post's blocks that runtime entity bindings anchor on.
     *
     * @param array<int,array<string,mixed>> $declarations
     * @param array<int,array<string,mixed>> $records
     * @param array<string,mixed> $post
     * @return list<array{offset:int,length:int}>
     */
    private function postBoundBlockRanges(array $declarations, array $records, array $post): array
    {
        $markup = (string) ($post['canonical_block_markup'] ?? '');
        $documentIndex = new \Automattic\BlocksEngine\PhpTransformer\Support\BlockMarkupIndex($markup);
        $ranges = array();
        foreach ($declarations as $declaration) {
            $payload = $declaration['payload'] ?? array();
            $entities = RuntimeEntityManifest::SCHEMA === ($payload['schema'] ?? null)
                ? RuntimeEntityManifest::resolve($payload, $records)
                : (ArrayField::array($payload, 'entities'));
            foreach ($entities as $entity) foreach (is_array($entity) ? ($entity['bindings'] ?? array()) : array() as $binding) {
                if (($binding['source_path'] ?? null) !== ($post['source_path'] ?? null)) continue;
                $search = $binding['search_block_markup'] ?? null;
                if (!is_string($search) || '' === $search) continue;
                $position = $binding['position'] ?? null;
                if (BindingAnchors::positionMatches($position, $documentIndex, $search)) { $ranges[] = array('offset' => $position['offset'], 'length' => $position['length']); continue; }
                // A manifest record keeps the position it was compiled with, which
                // page canonicalization can shift; protect every block it may name.
                for ($offset = strpos($markup, $search); false !== $offset; $offset = strpos($markup, $search, $offset + strlen($search))) $ranges[] = array('offset' => $offset, 'length' => strlen($search));
            }
        }
        return $ranges;
    }

    private function sharedIdentities(array $posts): array
    {
        // Identity normalization rewrites attributes and block comments, never
        // rendered text. A range whose text or URL values differ cannot be
        // shared chrome, so only ranges with common content are normalized:
        // that skips every container holding a post's own article.
        $indexes = array();
        $texts = null;
        foreach ($posts as $key => $post) {
            $indexes[$key] = new \Automattic\BlocksEngine\PhpTransformer\Support\BlockMarkupIndex($post['canonical_block_markup']);
            $postTexts = array();
            foreach ($indexes[$key]->ranges() as $range) {
                $postTexts[self::chromeTextKey(substr($post['canonical_block_markup'], $range['offset'], $range['length']))] = true;
            }
            $texts = null === $texts ? $postTexts : array_intersect_key($texts, $postTexts);
        }
        $shared = null;
        $headings = array();
        foreach ($posts as $key => $post) {
            $identities = array();
            foreach ($indexes[$key]->ranges() as $range) {
                $slice = substr($post['canonical_block_markup'], $range['offset'], $range['length']);
                if ('heading' === PostBlocks::sourceBlockName($slice) && !$this->headingMatchesPostTitle($slice, $post)) $headings[($this->chromeIdentity)($slice)][$key] = true;
                if (!isset($texts[self::chromeTextKey($slice)])) continue;
                $identity = ($this->chromeIdentity)($slice);
                if ('' !== $identity) {
                    $identities[$identity] = true;
                }
            }
            $shared = null === $shared ? $identities : array_intersect_key($shared, $identities);
        }
        // Optional sections can be absent on some posts; a repeated heading
        // still establishes their shared section identity.
        foreach ($headings as $identity => $owners) if (count($owners) >= 2) $shared[$identity] = true;
        return $shared ?? array();
    }

    /** URL-bearing subtrees are per-post unless their full identity is shared. */
    private function hasVaryingUrlAttributes(string $markup, array $shared): bool
    {
        return !isset($shared[($this->chromeIdentity)($markup)])
            && 1 === preg_match('/(?:\\s(?:' . self::URL_ATTRIBUTES . ')\\s*=|"(?:url|' . self::URL_ATTRIBUTES . ')"\\s*:)/i', $markup);
    }

    /** Rendered text and URL attributes, which must agree before chrome normalization. */
    private static function chromeTextKey(string $markup): string
    {
        $text = strip_tags(preg_replace('/<!--.*?-->/s', '', $markup) ?? $markup);
        preg_match_all('/\\s(?:' . self::URL_ATTRIBUTES . ')\\s*=\\s*(?:"[^"]*"|\'[^\']*\'|[^\\s>]+)|"(?:url|' . self::URL_ATTRIBUTES . ')"\\s*:\\s*"(?:\\\\.|[^"\\\\])*"/i', $markup, $urls);
        return hash('xxh128', trim(preg_replace('/\s+/u', ' ', html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8')) ?? $text) . json_encode($urls[0]));
    }

    /**
     * Select the smallest post-title-following container owning the most varying
     * text. Only move the slot when every outside variation has a native home;
     * otherwise the original factoring retains all of the authored content.
     *
     * @return array{range:?array,slots:array<int,string>,fields:array}
     */
    private function bodySelection(\Automattic\BlocksEngine\PhpTransformer\Support\BlockMarkupIndex $index, array $post, string $route, array $shared, array $posts, ?int $featuredOffset = null): array
    {
        $fallback = array('range' => null, 'slots' => array(), 'fields' => array(), 'featured' => null);
        $titleEnd = $this->hasDeclaredEmptyTitle($post) ? 0 : null;
        $varying = array();
        foreach ($index->ranges() as $range) {
            if (array() !== $index->children($range)) continue;
            $slice = substr($index->markup, $range['offset'], $range['length']);
            $kind = $this->classifyBlock($slice, $post, $route, $index);
            // Without a title, the publication date still anchors the article
            // start. Otherwise enclosing chrome can win by counting related
            // cards as body variation before their section is projected.
            // Listing self-links can also classify as titles; only the first
            // matching heading anchors a titled post's body.
            if ((null === $titleEnd && $this->headingMatchesPostTitle($slice, $post)) || (0 === $titleEnd && 'date' === $kind && $this->hasDeclaredEmptyTitle($post))) $titleEnd = $range['offset'] + $range['length'];
            if ($range['offset'] === $featuredOffset) continue;
            if (isset($shared[($this->chromeIdentity)($slice)]) || (!$this->hasVaryingUrlAttributes($slice, $shared) && in_array($kind, array('title', 'date', 'comments', 'chrome', 'share', 'skip'), true))) continue;
            $varying[] = $range;
        }
        if (null === $titleEnd) return $fallback;
        $best = null;
        $bestCount = 0;
        foreach ($index->ranges() as $candidate) {
            if ($candidate['offset'] < $titleEnd || array() === $index->children($candidate)) continue;
            $count = 0;
            foreach ($varying as $leaf) {
                if ($leaf['offset'] < $candidate['offset'] || $leaf['offset'] + $leaf['length'] > $candidate['offset'] + $candidate['length']) continue;
                $slice = substr($index->markup, $leaf['offset'], $leaf['length']);
                // Unlinked media is article content; linked thumbnails belong
                // to cards/navigation and must not inflate the body count.
                if ('' !== ListingExcerptProjection::text($slice) || (in_array(PostBlocks::sourceBlockName($slice), array('image', 'gallery', 'video', 'audio', 'embed', 'media-text', 'cover'), true) && !preg_match('/<a\b[^>]*\bhref\s*=|"href"\s*:/i', $slice))) ++$count;
            }
            if ($count < 2 || $count < $bestCount) continue;
            if ($count > $bestCount || null === $best || $candidate['length'] < $best['length']) {
                $best = $candidate;
                $bestCount = $count;
            }
        }
        if (null === $best) return $fallback;
        // Ties select the smallest wrapper. Keep authored empty siblings and
        // their enclosing body anchor with the body, not in shared chrome.
        while (true) {
            $parent = null;
            foreach ($index->ranges() as $candidate) {
                if ($candidate['offset'] < $titleEnd || $candidate['offset'] > $best['offset'] || $candidate['offset'] + $candidate['length'] < $best['offset'] + $best['length'] || $candidate['length'] <= $best['length']) continue;
                if (null === $parent || $candidate['length'] < $parent['length']) $parent = $candidate;
            }
            if (null === $parent) break;
            foreach ($index->ranges() as $sibling) {
                if ($sibling['offset'] < $parent['offset'] || $sibling['offset'] + $sibling['length'] > $parent['offset'] + $parent['length']) continue;
                // Inspect only blocks outside the chosen body, not ancestors.
                if ($sibling['offset'] < $best['offset'] + $best['length'] && $sibling['offset'] + $sibling['length'] > $best['offset']) continue;
                $slice = substr($index->markup, $sibling['offset'], $sibling['length']);
                if ('' !== ListingExcerptProjection::text($slice) || in_array(PostBlocks::sourceBlockName($slice), array('image', 'gallery', 'video', 'audio', 'embed', 'media-text', 'cover'), true) || preg_match('/<(?:img|video|iframe|picture|audio|svg|object|embed)\b/i', $slice)) break 2;
            }
            $best = $parent;
        }
        $slots = array();
        $fields = array();
        $featured = null;
        // Project corresponding related sections together, just like index cards.
        // This supplies the card classifier with variation across all posts.
        // A section is a container led by a shared heading.
        $sectionItems = function (\Automattic\BlocksEngine\PhpTransformer\Support\BlockMarkupIndex $sectionIndex, array $owner, array $shared): ?array {
            $children = $sectionIndex->children($owner);
            if (count($children) < 2) return null;
            $heading = substr($sectionIndex->markup, $children[0]['offset'], $children[0]['length']);
            if ('heading' !== PostBlocks::sourceBlockName($heading) || !isset($shared[($this->chromeIdentity)($heading)])) return null;
            $items = array_slice($children, 1);
            $following = $items;
            // Follow wrapper chains, allowing textless carousel controls beside
            // the card list. Controls stay outside the replaced item range.
            // Stop at the list so a single li remains an item, not its contents.
            while (array() !== $following) {
                if (1 !== count($following)) {
                    $content = array_values(array_filter($following, static fn(array $child): bool => '' !== ListingExcerptProjection::text(substr($sectionIndex->markup, $child['offset'], $child['length']))));
                    if (1 !== count($content)) {
                        // Some native containers expose their cards directly,
                        // with the list wrapper carried in their saved HTML.
                        if (isset($slice) && 1 === preg_match('~^<!-- wp:[a-z0-9-]+/authored-carousel ~', ltrim($slice))) $items = $following;
                        break;
                    }
                    $following = $content;
                }
                $slice = substr($sectionIndex->markup, $following[0]['offset'], $following[0]['length']);
                $nested = $sectionIndex->children($following[0]);
                if ('list' === PostBlocks::sourceBlockName($slice) || in_array(PostBlocks::sourceAttributes($slice)['tagName'] ?? null, array('ul', 'ol'), true) || array() !== array_filter(PostBlocks::sourceAttributes($slice)['wrappers'] ?? array(), static fn(array $wrapper): bool => in_array($wrapper['tagName'] ?? null, array('ul', 'ol'), true))) {
                    if (array() !== $nested) $items = $nested;
                    break;
                }
                $following = $nested;
            }
            return array('heading' => ($this->chromeIdentity)($heading), 'items' => $items);
        };
        $sections = array();
        foreach ($index->ranges() as $owner) {
            if ($owner['offset'] < $best['offset'] + $best['length']) continue;
            $section = $sectionItems($index, $owner, $shared);
            if (null !== $section) $sections[] = $section + array('owner' => $owner);
        }
        foreach ($sections as $section) {
            if (!array_key_exists($section['heading'], $this->relatedSectionProjections)) {
                $this->relatedSectionProjections[$section['heading']] = $this->relatedSectionProjection($section['heading'], $sectionItems, $posts, $shared);
            }
            $projection = $this->relatedSectionProjections[$section['heading']];
            if (null === $projection) continue;
            $items = $section['items'];
            $start = $items[0]['offset'];
            $end = $items[count($items) - 1]['offset'] + $items[count($items) - 1]['length'];
            $slots[$section['owner']['offset']] = substr($index->markup, $section['owner']['offset'], $start - $section['owner']['offset']) . $this->relatedQuery($section['heading'], $post, count($items), $projection['template']) . substr($index->markup, $end, $section['owner']['offset'] + $section['owner']['length'] - $end);
            $this->listings->registerQueryContainer($slots[$section['owner']['offset']]);
            foreach ($projection['fields'] as $source => $values) $fields[$source] = ($fields[$source] ?? array()) + $values;
        }
        // A textless counterpart of a proven related section is its empty
        // loading state, not a second article body. Use the same query slot.
        foreach ($posts as $peer) {
            $peerIndex = new \Automattic\BlocksEngine\PhpTransformer\Support\BlockMarkupIndex($peer['canonical_block_markup']);
            foreach ($peerIndex->ranges() as $owner) {
                $section = $sectionItems($peerIndex, $owner, $shared);
                if (null === $section) continue;
                if (!array_key_exists($section['heading'], $this->relatedSectionProjections)) $this->relatedSectionProjections[$section['heading']] = $this->relatedSectionProjection($section['heading'], $sectionItems, $posts, $shared);
                $projection = $this->relatedSectionProjections[$section['heading']];
                if (null === $projection) continue;
                $slice = substr($peerIndex->markup, $owner['offset'], $owner['length']);
                $class = PostBlocks::sourceAttributes($slice)['className'] ?? '';
                $class = preg_replace('/\s*be-inline-geometry-[\w-]+/', '', $class);
                if ('' === $class) continue;
                foreach ($index->ranges() as $candidate) {
                    if ($candidate['offset'] < $best['offset'] + $best['length'] || isset($slots[$candidate['offset']])) continue;
                    $empty = substr($index->markup, $candidate['offset'], $candidate['length']);
                    $emptyClass = preg_replace('/\s*be-inline-geometry-[\w-]+/', '', PostBlocks::sourceAttributes($empty)['className'] ?? '');
                    if ($class !== $emptyClass || '' !== ListingExcerptProjection::text($empty)) continue;
                    $items = $section['items'];
                    $start = $items[0]['offset'] - $owner['offset'];
                    $last = $items[array_key_last($items)];
                    $end = $last['offset'] + $last['length'] - $owner['offset'];
                    $slots[$candidate['offset']] = substr($slice, 0, $start) . $this->relatedQuery($section['heading'], $post, count($items), $projection['template']) . substr($slice, $end);
                    $this->listings->registerQueryContainer($slots[$candidate['offset']]);
                }
            }
        }
        foreach ($varying as $leaf) {
            if ($leaf['offset'] >= $best['offset'] && $leaf['offset'] < $best['offset'] + $best['length']) continue;
            foreach ($slots as $offset => $unused) {
                // An enclosing related section already owns this leaf.
                foreach ($index->ranges() as $owner) if ($owner['offset'] === $offset && $leaf['offset'] >= $offset && $leaf['offset'] < $offset + $owner['length']) continue 3;
            }
            $slice = substr($index->markup, $leaf['offset'], $leaf['length']);
            if ('paragraph' === PostBlocks::sourceBlockName($slice)) {
                // Slot identity is structural, never a byte offset or source text.
                $path = array();
                $node = $leaf;
                do {
                    $parent = $index->parent($node);
                    $siblings = null === $parent ? $index->topLevel() : $index->children($parent);
                    $path[] = array_search($node, $siblings, true);
                    $node = $parent;
                } while (null !== $node);
                $key = 'blocks_engine_post_field_' . substr(hash('sha256', json_encode($path)), 0, 12);
                $fields[$post['source_path']][$key] = ListingFieldProjection::content(array($slice));
                $slots[$leaf['offset']] = ListingFieldProjection::paragraph($slice, PostBlocks::sourceAttributes($slice), $key, '');
                continue;
            }
            // A varying lead image ahead of the body is the post's featured image.
            if ($leaf['offset'] < $best['offset'] && null === $featured && 'image' === PostBlocks::sourceBlockName($slice) && null !== ($image = PostBlocks::leadImage($slice))) {
                $featured = $image;
                $slots[$leaf['offset']] = PostBlocks::featuredImage($slice);
                continue;
            }
            $this->orderRetained[$post['source_path']] = array('code' => 'wordpress_site_plan_post_chrome_order_retained', 'source_path' => $post['source_path'], 'message' => 'Outside body variation has no native slot; retained original post-content placement to avoid dropping content.');
            return $fallback;
        }
        return array('range' => $best, 'slots' => $slots, 'fields' => $fields, 'featured' => $featured);
    }

    /**
     * A related section's query for one post: scoped to the post's term when
     * the group's cards prove one, else the latest posts it listed.
     */
    private function relatedQuery(string $heading, array $post, int $count, string $template): string
    {
        $scope = $this->relatedTermScopes[$heading][$post['source_path'] ?? ''] ?? null;
        return null === $scope ? PostBlocks::query($count, $template) : PostBlocks::query($scope['per_page'], $template, relatedTerm: $scope);
    }

    /**
     * Project a related section across every post in the group: the items
     * each post lists under the shared heading become one listing whose
     * cards yield the section's query template and per-post fields.
     *
     * @param array<int,array<string,mixed>> $posts
     * @param array<string,true> $shared
     * @return array{template:string,fields:array<string,array<string,mixed>>,featured_images:array<string,array<string,mixed>>}|null
     */
    private function relatedSectionProjection(string $heading, callable $sectionItems, array $posts, array $shared): ?array
    {
        $cardMarkup = '';
        $seen = array();
        // Each post's card keys in order, and the card markup offset of each key.
        $keysByPost = array();
        $keyOffsets = array();
        foreach ($posts as $peer) {
            $peerIndex = new \Automattic\BlocksEngine\PhpTransformer\Support\BlockMarkupIndex($peer['canonical_block_markup']);
            foreach ($peerIndex->ranges() as $owner) {
                $peerSection = $sectionItems($peerIndex, $owner, $shared);
                if (null === $peerSection || $heading !== $peerSection['heading']) continue;
                foreach ($peerSection['items'] as $child) {
                    $slice = substr($peerIndex->markup, $child['offset'], $child['length']);
                    // Runtime slider classes and geometry vary by position;
                    // repeated linked routes are still the same card evidence.
                    $key = $this->listings->cardRouteIdentity($slice);
                    if (is_string($peer['source_path'] ?? null)) $keysByPost[$peer['source_path']][] = $key;
                    if (isset($seen[$key])) continue;
                    $seen[$key] = true;
                    $keyOffsets[strlen($cardMarkup)] = $key;
                    $cardMarkup .= $slice;
                }
                break;
            }
        }
        $cardIndex = new \Automattic\BlocksEngine\PhpTransformer\Support\BlockMarkupIndex($cardMarkup);
        $cards = $this->listings->cardRanges($cardIndex, $this->allPosts, 1);
        if (null === $cards) return null;
        // Never replace a whole section if some authored child has no card
        // projection. Let the normal fallback retain its source order.
        foreach ($cardIndex->topLevel() as $child) {
            foreach ($cards as $card) {
                if ($child['offset'] >= $card['offset'] && $child['offset'] + $child['length'] <= $card['offset'] + $card['length']) continue 2;
            }
            return null;
        }
        $cardFields = array();
        $bound = array();
        foreach ($cards as $card) {
            $ordinal = 0;
            foreach ($cardIndex->ranges() as $range) {
                if ($range['offset'] < $card['offset'] || $range['offset'] + $range['length'] > $card['offset'] + $card['length']) continue;
                $slice = substr($cardMarkup, $range['offset'], $range['length']);
                if ('paragraph' !== PostBlocks::sourceBlockName($slice)) continue;
                if ('title' === $this->listings->classifyCardBlock($slice, $card['post'], $card['route'], $cardIndex)) continue;
                $key = 'blocks_engine_post_field_' . substr(hash('sha256', $heading . ':' . $ordinal++), 0, 12);
                $cardFields[$card['post']['source_path']][$key] = ListingFieldProjection::content(array($slice));
                $bound[$range['offset']] = ListingFieldProjection::paragraph($slice, PostBlocks::sourceAttributes($slice), $key, '');
            }
        }
        $template = $this->listings->cardTemplate($cardIndex, $cards, $cardFields, $bound);
        if (null === $template) return null;
        if (!array_key_exists($heading, $this->relatedTermScopes)) {
            $sourceByKey = array();
            foreach ($cards as $card) {
                $owner = null;
                foreach ($keyOffsets as $offset => $key) if ($offset <= $card['offset']) $owner = $key;
                if (null !== $owner) $sourceByKey[$owner] = $card['post']['source_path'] ?? null;
            }
            $cardsByPost = array_map(static fn(array $keys): array => array_map(static fn(string $key): ?string => $sourceByKey[$key] ?? null, $keys), $keysByPost);
            $this->relatedTermScopes[$heading] = ListingQueryLoops::relatedTermScopes($cardsByPost, $this->allPosts, $this->taxonomyEntities, $this->uniformTemplate);
        }
        $featuredImages = array();
        if (str_contains($template, '<!-- wp:post-featured-image')) {
            foreach ($cards as $card) {
                $markup = substr($cardMarkup, $card['offset'], $card['length']);
                $image = $this->listings->cardFeaturedImage($markup, $card['post'], $card['route']);
                if (null !== $image) $featuredImages[$card['post']['source_path']] = $image;
            }
        }
        return array('template' => $template, 'fields' => $cardFields, 'featured_images' => $featuredImages);
    }

    /**
     * @param array<string,mixed> $post
     * @param array<string,true> $shared
     * @return array{template:string,body:string,title:bool,date:bool,shared:bool,content:bool,retained:array<int,array{offset:int,length:int}>}
     */
    private function transformDocument(string $markup, array $post, string $route, array $shared, ?int $featuredOffset = null, array $posts = array()): array
    {
        unset($this->orderRetained[$post['source_path']]);
        $index = new \Automattic\BlocksEngine\PhpTransformer\Support\BlockMarkupIndex($markup);
        $postIndex = new \Automattic\BlocksEngine\PhpTransformer\Support\BlockMarkupIndex((string) ($post['canonical_block_markup'] ?? ''));
        $top = $index->topLevel();
        if (array() === $top) {
            return array('template' => '', 'body' => '', 'title' => false, 'date' => false, 'shared' => false, 'content' => false, 'retained' => array());
        }
        // A content-only main landmark is stronger evidence than varying
        // media leaves. Materialized SVG attachment identities are route-local,
        // and must never turn header logos or metadata icons into body slots.
        $contentRange = null;
        foreach ($index->ranges() as $candidate) {
            $slice = substr($markup, $candidate['offset'], $candidate['length']);
            $attrs = PostBlocks::sourceAttributes($slice);
            // An explicitly untitled post cannot prove that this landmark
            // excludes article metadata and surrounding layout by title absence.
            if ($this->hasDeclaredEmptyTitle($post) || 'main' !== ($attrs['tagName'] ?? null) || array() === $index->children($candidate)) continue;
            $hasTitle = false;
            foreach ($index->ranges() as $descendant) {
                if ($descendant['offset'] < $candidate['offset'] || $descendant['offset'] + $descendant['length'] > $candidate['offset'] + $candidate['length']) continue;
                if ($this->headingMatchesPostTitle(substr($markup, $descendant['offset'], $descendant['length']), $post)) { $hasTitle = true; break; }
            }
            if ($hasTitle) continue;
            if (null !== $contentRange) { $contentRange = null; break; }
            $contentRange = $candidate;
        }
        $slots = array();
        $fields = array();
        $featured = null;
        if (null === $contentRange) {
            $selection = $this->bodySelection($index, $post, $route, $shared, $posts, $featuredOffset);
            $contentRange = $selection['range'];
            $slots = $selection['slots'];
            $fields = $selection['fields'];
            $featured = $selection['featured'];
        }
        $template = '';
        $body = '';
        $title = false;
        $date = false;
        $sharedChrome = false;
        $content = false;
        $retained = array();
        $cursor = 0;
        foreach ($top as $range) {
            if ($range['offset'] > $cursor) {
                $template .= substr($markup, $cursor, $range['offset'] - $cursor);
            }
            $transformed = $this->transformRange($index, $postIndex, $range, $post, $route, $shared, false, $featuredOffset, $contentRange, $slots);
            $template .= $transformed['template'];
            $body .= $transformed['body'];
            $title = $title || $transformed['title'];
            $date = $date || $transformed['date'];
            $sharedChrome = $sharedChrome || $transformed['shared'];
            $content = $content || $transformed['content'];
            $retained = array_merge($retained, $transformed['retained']);
            $cursor = $range['offset'] + $range['length'];
        }
        if ($cursor < strlen($markup)) {
            $template .= substr($markup, $cursor);
        }
        return array('template' => $template, 'body' => $body, 'title' => $title, 'date' => $date, 'shared' => $sharedChrome, 'content' => $content, 'retained' => $retained, 'fields' => $fields, 'featured' => $featured);
    }

    /**
     * @param array{offset:int,length:int} $range
     * @param array<string,mixed> $post
     * @param array<string,true> $shared
     * @return array{template:string,body:string,title:bool,date:bool,shared:bool,content:bool,retained:array<int,array{offset:int,length:int}>}
     */
    private function transformRange(\Automattic\BlocksEngine\PhpTransformer\Support\BlockMarkupIndex $index, \Automattic\BlocksEngine\PhpTransformer\Support\BlockMarkupIndex $postIndex, array $range, array $post, string $route, array $shared, bool $dateContext = false, ?int $featuredOffset = null, ?array $contentRange = null, array $slots = array()): array
    {
        $markup = $index->markup;
        $slice = substr($markup, $range['offset'], $range['length']);
        $empty = array('template' => $slice, 'body' => '', 'title' => false, 'date' => false, 'shared' => false, 'content' => false, 'retained' => array());
        if (isset($slots[$range['offset']])) {
            $empty['template'] = $slots[$range['offset']];
            return $empty;
        }
        if (null !== $contentRange && $range === $contentRange) {
            // An id names one post's element. A shared template would repeat
            // it on every post, so an anchored container moves with its body.
            if (isset(PostBlocks::sourceAttributes($slice)['anchor'])) {
                return array('template' => '<!-- wp:post-content /-->', 'body' => $slice, 'title' => false, 'date' => false, 'shared' => false, 'content' => true, 'retained' => array(array('offset' => $range['offset'], 'length' => $range['length'])));
            }
            $children = $index->children($range);
            $start = $children[0]['offset'];
            $last = $children[count($children) - 1];
            $length = $last['offset'] + $last['length'] - $start;
            return array('template' => substr_replace($slice, '<!-- wp:post-content /-->', $start - $range['offset'], $length), 'body' => substr($markup, $start, $length), 'title' => false, 'date' => false, 'shared' => false, 'content' => true, 'retained' => array(array('offset' => $start, 'length' => $length)));
        }
        if ($range['offset'] === $featuredOffset) {
            $empty['template'] = PostBlocks::featuredImage($slice);
            return $empty;
        }
        $attrs = PostBlocks::sourceAttributes($slice);
        $className = (string) ($attrs['className'] ?? '');
        $anchor = (string) ($attrs['anchor'] ?? '');
        $varyingUrls = $this->hasVaryingUrlAttributes($slice, $shared);
        if (!$varyingUrls && (preg_match('/\b(?:social|share-button|twitter-share)\b/', $className) || preg_match('/\bcomments?\b/i', $className) || preg_match('/comment/i', $anchor))) {
            return $empty;
        }
        $identity = ($this->chromeIdentity)($slice);
        // Shared markup is not proof of chrome ownership for media alone,
        // including serialized responsive media inside a shared wrapper: each
        // post may render the same image. A shared section that also carries
        // its own text or controls is chrome, media included.
        $mediaOnly = preg_match('/<(?:img|video|picture|audio)\b/i', str_replace('\\u003c', '<', $slice)) && '' === ListingExcerptProjection::text($slice);
        if ('' !== $identity && isset($shared[$identity]) && !$mediaOnly) {
            return array('template' => $slice, 'body' => '', 'title' => false, 'date' => false, 'shared' => true, 'content' => false, 'retained' => array());
        }
        // A closed dialog opens on demand, so it can neither host the post
        // title nor place post-content: both would stay hidden with it. When
        // it varies by post it travels with the body, unplaced.
        if (self::isClosedDialogBlock($slice)) {
            return array('template' => '', 'body' => $slice, 'title' => false, 'date' => false, 'shared' => false, 'content' => false, 'retained' => array(array('offset' => $range['offset'], 'length' => $range['length'])));
        }
        $children = $index->children($range);
        if (array() === $children) {
            $kind = $this->classifyBlock($slice, $post, $route, $postIndex);
            if ($dateContext && is_string($post['publication_timestamp'] ?? null)
                && PublicationDate::fromVisibleText(ListingExcerptProjection::text($slice)) === $post['publication_timestamp']) {
                $kind = 'date';
            }
            if ('title' === $kind) {
                return array('template' => PostBlocks::title($slice), 'body' => '', 'title' => true, 'date' => false, 'shared' => false, 'content' => false, 'retained' => array());
            }
            if ('date' === $kind) {
                return array('template' => PostBlocks::date($slice), 'body' => '', 'title' => false, 'date' => true, 'shared' => false, 'content' => false, 'retained' => array());
            }
            if (!$varyingUrls && ('comments' === $kind || 'chrome' === $kind || 'share' === $kind || 'skip' === $kind)) {
                return $empty;
            }
            if (null !== $contentRange) return $empty;
            return array('template' => '<!-- wp:post-content /-->', 'body' => $slice, 'title' => false, 'date' => false, 'shared' => false, 'content' => true, 'retained' => array(array('offset' => $range['offset'], 'length' => $range['length'])));
        }
        $foundTitle = false;
        $foundDate = false;
        $foundShared = false;
        $emittedContent = false;
        $body = '';
        $retained = array();
        $replacements = array();
        // A date identity may belong to a shell rather than its bare text
        // leaf. Carry it only through single-content wrappers, retaining the
        // shell in the template instead of replacing it with the date block.
        $childDateContext = 1 === count($children)
            && ListingExcerptProjection::text($slice) === ListingExcerptProjection::text(substr($markup, $children[0]['offset'], $children[0]['length']))
            && ($dateContext || 'date' === $this->classifyBlock($slice, $post, $route, $postIndex));
        foreach ($children as $child) {
            $transformed = $this->transformRange($index, $postIndex, $child, $post, $route, $shared, $childDateContext, $featuredOffset, $contentRange, $slots);
            if ($transformed['content'] && $emittedContent) {
                // The subtree leaves the template, and with it any title or
                // date it placed: neither is found. Shared blocks inside it
                // leave too, so the body keeps the complete subtree.
                $body .= substr($markup, $child['offset'], $child['length']);
                $retained[] = array('offset' => $child['offset'], 'length' => $child['length']);
                $replacements[] = array('offset' => $child['offset'], 'length' => $child['length'], 'markup' => '');
                continue;
            }
            $foundShared = $foundShared || $transformed['shared'];
            $body .= $transformed['body'];
            $retained = array_merge($retained, $transformed['retained']);
            $foundTitle = $foundTitle || $transformed['title'];
            $foundDate = $foundDate || $transformed['date'];
            if ($transformed['content']) {
                $emittedContent = true;
            }
            $replacements[] = array('offset' => $child['offset'], 'length' => $child['length'], 'markup' => $transformed['template']);
        }
        usort($replacements, static fn(array $left, array $right): int => $right['offset'] <=> $left['offset']);
        foreach ($replacements as $replacement) {
            $relative = $replacement['offset'] - $range['offset'];
            $slice = substr($slice, 0, $relative) . $replacement['markup'] . substr($slice, $relative + $replacement['length']);
        }
        return array('template' => $slice, 'body' => $body, 'title' => $foundTitle, 'date' => $foundDate, 'shared' => $foundShared, 'content' => $emittedContent, 'retained' => $retained);
    }

    /** @param array<string,mixed> $post */
    private function classifyBlock(string $slice, array $post, string $route, \Automattic\BlocksEngine\PhpTransformer\Support\BlockMarkupIndex $postIndex): string
    {
        if ($this->headingMatchesPostTitle($slice, $post)) {
            return 'title';
        }
        if (self::isCommentFormChrome($slice)) {
            return 'comments';
        }
        $kind = $this->listings->classifyCardBlock($slice, $post, $route, $postIndex);
        return 'skip' === $kind ? 'share' : $kind;
    }

    /** @param array<string,mixed> $post */
    private function headingMatchesPostTitle(string $slice, array $post): bool
    {
        $title = trim((string) ($post['title'] ?? ''));
        if ('' === $title || !in_array(PostBlocks::sourceBlockName($slice), array('heading', 'post-title'), true)) {
            return false;
        }
        $normalize = static fn(string $value): string => trim(preg_replace('/\s+/', ' ', str_replace("\xc2\xa0", ' ', html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8'))) ?? '');
        return $normalize(strip_tags($slice)) === $normalize($title);
    }

    /** Whether a block renders a dialog that is hidden until opened. */
    private static function isClosedDialogBlock(string $slice): bool
    {
        if (!preg_match('/^\s*<!--\s*wp:.*?-->\s*(<(?!!)[^>]*>)/s', $slice, $match)) {
            return false;
        }
        $attributes = HtmlTagScanner::attributes($match[1]);
        if (preg_match('/^<dialog\b/i', $match[1])) {
            return !array_key_exists('open', $attributes);
        }
        if (!array_key_exists('hidden', $attributes) && 'true' !== strtolower($attributes['aria-hidden'] ?? '')) {
            return false;
        }
        return 'dialog' === strtolower($attributes['role'] ?? '')
            || 'true' === strtolower($attributes['aria-modal'] ?? '')
            || array_key_exists(CaptureEvidence::DIALOG_PANEL, $attributes);
    }

    private static function isCommentFormChrome(string $slice): bool
    {
        return str_contains($slice, 'Leave a Reply') || 1 === preg_match('/<iframe\b[^>]*(?:comment|Comment)/', $slice);
    }
}
