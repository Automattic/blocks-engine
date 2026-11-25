<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan;

use Automattic\BlocksEngine\PhpTransformer\Support\ArrayField;
use Automattic\BlocksEngine\PhpTransformer\Support\HtmlSerialization;
use InvalidArgumentException;

/**
 * The block templates a site plan registers: one per route and archive, with
 * the shared parts each one places and the source content it carries. Override
 * templates that render the same markup collapse onto one shared template.
 */
final class ThemeTemplates
{
    /**
     * The template WordPress renders a page authored after the import with,
     * while captured pages share page.html and keep their content-owned layout.
     */
    public const AUTHORED_PAGE = 'authored-page';

    /**
     * The generated templates without those that repeat the template
     * WordPress falls back to.
     *
     * Without archive.html or search.html WordPress renders those lifecycles
     * through index.html; the entry route is the static front page
     * (show_on_front=page), so without front-page.html it renders through
     * page.html. A byte-identical copy only repeats its fallback. A page
     * template the entry route selects itself would outrank page.html, so
     * front-page.html stays then.
     *
     * @param array<int,array<string,mixed>> $templates
     * @param array<int,array<string,mixed>> $pages
     * @return array<int,array<string,mixed>>
     */
    private static function withoutRedundant(array $templates, array $pages): array
    {
        $fallbacks = array('archive' => 'index', 'search' => 'index');
        $entry = array_values(array_filter($pages, static fn(array $page): bool => !empty($page['entrypoint'])))[0] ?? null;
        if (null !== $entry && 'page' === ($entry['post_type'] ?? 'page') && !isset($entry['route_template']) && in_array($entry['metadata']['post_meta']['_wp_page_template'] ?? 'default', array('', 'default'), true)) $fallbacks['front-page'] = 'page';
        $markup = array_column($templates, 'canonical_block_markup', 'slug');
        return array_values(array_filter($templates, static fn(array $template): bool => !isset($fallbacks[$template['slug']], $markup[$fallbacks[$template['slug']]]) || $markup[$template['slug']] !== $markup[$fallbacks[$template['slug']]]));
    }

    /**
     * The markup WordPress renders for each lifecycle a plan's templates
     * serve, keyed by template slug and resolved through the template
     * hierarchy. The entry route is the static front page, so front-page
     * falls back to page.
     *
     * @param array<string,mixed> $plan
     * @return array<string,string>
     */
    public static function rendered(array $plan): array
    {
        $templates = array_column(ArrayField::array($plan, 'templates'), 'canonical_block_markup', 'slug');
        $archives = array('author', 'category', 'date', 'tag', 'taxonomy');
        $rendered = array();
        foreach (array_unique(array_merge(WordPressSitePlan::NATIVE_TEMPLATE_SLUGS, $archives, array('home'), array_map('strval', array_keys($templates)))) as $slug) {
            $base = explode('-', $slug)[0];
            $chain = array($slug, $base);
            if ('front' === $base || 'page' === $base) $chain[] = 'page';
            elseif ('single' === $base) $chain[] = 'single';
            elseif (in_array($base, $archives, true)) $chain[] = 'archive';
            $chain[] = 'index';
            foreach ($chain as $candidate) if (isset($templates[$candidate])) { $rendered[$slug] = (string) $templates[$candidate]; break; }
        }
        return $rendered;
    }

    /**
     * Templates whose block-theme chrome was extracted render inside that
     * chrome's shell frame; when any was extracted, the single template
     * takes the first.
     *
     * @param array<int,array<string,mixed>> $templates
     * @param array<string,mixed> $blockThemeChrome
     * @return array<int,array<string,mixed>>
     */
    public static function withBlockThemeShellFrames(array $templates, array $blockThemeChrome): array
    {
        foreach ($templates as &$template) {
            $chrome = $blockThemeChrome['templates'][$template['slug']] ?? null;
            if (!is_string($chrome)) continue;
            $template['shell_frame'] = array_values(array_unique(array_merge($template['shell_frame'] ?? array(), $blockThemeChrome['shells'][$template['slug']] ?? array())));
        }
        unset($template);
        if (array() !== $blockThemeChrome['templates']) foreach ($templates as &$template) {
            if ('single' === $template['slug']) $template['shell_frame'] = reset($blockThemeChrome['shells']);
        }
        unset($template);
        return $templates;
    }

    /**
     * Registers each page's route template as a theme.json custom template,
     * once per template, titled by its shared override when it has one.
     *
     * @param array<string,mixed> $themeProjection
     * @param array<int,array<string,mixed>> $pages
     * @param array<string,string> $sharedTemplateTitles
     * @return array<string,mixed>
     */
    public static function withCustomTemplates(array $themeProjection, array $pages, array $sharedTemplateTitles, array $templates = array()): array
    {
        // Authors can also assign the new-page template explicitly.
        if (in_array(self::AUTHORED_PAGE, array_column($templates, 'slug'), true)) $themeProjection['theme']['customTemplates'][] = array('name' => self::AUTHORED_PAGE, 'title' => 'New page', 'postTypes' => array('page'));
        $registeredTemplates = array();
        foreach ($pages as $page) if (isset($page['route_template']) && !isset($registeredTemplates[$page['route_template']])) {
            $registeredTemplates[$page['route_template']] = true;
            $themeProjection['theme']['customTemplates'][] = array('name' => $page['route_template'], 'title' => $sharedTemplateTitles[$page['route_template']] ?? $page['title'], 'postTypes' => array($page['post_type']));
        }
        return $themeProjection;
    }

    /**
     * Share generated route overrides only after their final markup is known.
     * Keep placement keys aligned with TemplateSlugs::forPage() for binding validation.
     *
     * @param array<int,array<string,mixed>> $pages
     * @param array<int,array<string,mixed>> $parts
     * @param array<int,array<string,mixed>> $templates
     * @return array<string,string> Shared template titles.
     */
    public static function shareOverrides(array &$pages, array &$parts, array &$templates): array
    {
        $bySlug = array_column($templates, null, 'slug');
        $groups = array(); $renames = array(); $redundant = array();
        foreach ($pages as $index => $page) {
            $key = TemplateSlugs::forPage($page);
            $template = $bySlug[$key] ?? null;
            if ('single' === $key || !empty($page['entrypoint']) || !is_array($template) || isset($template['source_path'])) continue;
            $generic = TemplateSlugs::genericForPage($page, array_keys($bySlug));
            if ($key !== $generic && isset($bySlug[$generic]) && $template['canonical_block_markup'] === $bySlug[$generic]['canonical_block_markup']) {
                $renames[$key] = $generic;
                $redundant[$key] = true;
                unset($pages[$index]['route_template'], $pages[$index]['metadata']['post_meta']['_wp_page_template']);
                continue;
            }
            if ($key === $generic) continue;
            $groups[$page['post_type'] . "\0" . $template['canonical_block_markup']][] = $index;
        }
        $titles = array(); $shared = array(); $layouts = array();
        foreach ($groups as $indexes) {
            if (count($indexes) < 2) continue;
            $page = $pages[$indexes[0]];
            $key = TemplateSlugs::forPage($page);
            $prefix = 'page' === $page['post_type'] ? 'page-' : 'single-' . $page['post_type'] . '-';
            $slug = $prefix . 'shared-' . substr(hash('sha256', $page['post_type'] . "\0" . $bySlug[$key]['canonical_block_markup']), 0, 12);
            while (isset($bySlug[$slug]) || isset($titles[$slug])) $slug .= '-shared';
            foreach ($indexes as $index) {
                $renames[TemplateSlugs::forPage($pages[$index])] = $slug;
                $pages[$index]['route_template'] = $slug;
                $pages[$index]['metadata']['post_meta']['_wp_page_template'] = $slug;
            }
            // Name the group, not its members: the Site Editor lists this title.
            $ordinal = $layouts[$page['post_type']] = ($layouts[$page['post_type']] ?? 0) + 1;
            $titles[$slug] = ucfirst(str_replace('_', ' ', $page['post_type'])) . ' (shared layout' . (1 < $ordinal ? ' ' . $ordinal : '') . ')';
        }
        $templates = array_values(array_filter($templates, static fn(array $template): bool => !isset($redundant[$template['slug']])));
        foreach ($templates as &$template) {
            $slug = $renames[$template['slug']] ?? null;
            if (null === $slug || isset($shared[$slug])) continue;
            $shared[$slug] = true;
            $template['slug'] = $slug;
            $template['target_path'] = 'templates/' . $slug . '.html';
            $template['reconciliation_identity'] = WordPressSitePlan::identity('template', 'wordpress-site-plan/' . $template['target_path'], $template['target_path']);
        }
        unset($template);
        $templates = array_values(array_filter($templates, static fn(array $template): bool => !isset($renames[$template['slug']])));
        foreach ($parts as &$part) {
            foreach (array('template_slugs', 'excluded_template_slugs') as $field) if (isset($part['placement'][$field])) {
                $part['placement'][$field] = array_values(array_unique(array_map(static fn(string $slug): string => $renames[$slug] ?? $slug, $part['placement'][$field])));
            }
            if (isset($part['placement']['template_wrappers'])) {
                $wrappers = array();
                foreach ($part['placement']['template_wrappers'] as $slug => $wrapper) $wrappers[$renames[$slug] ?? $slug] = $wrapper;
                $part['placement']['template_wrappers'] = $wrappers;
            }
        }
        unset($part);
        return $titles;
    }

    /**
     * Whether the captured entry page (else the first captured page) shows
     * its own title as a heading, which a page authored later renders as its
     * native post title.
     *
     * @param array<int,array<string,mixed>> $pages
     */
    private static function entryShowsTitle(array $pages): bool
    {
        $captured = array_values(array_filter($pages, static fn(array $page): bool => 'page' === ($page['post_type'] ?? 'page')));
        $entry = array_values(array_filter($captured, static fn(array $page): bool => !empty($page['entrypoint'])))[0] ?? $captured[0] ?? null;
        $title = trim((string) preg_replace('/\s+/u', ' ', html_entity_decode((string) ($entry['title'] ?? ''), ENT_QUOTES | ENT_HTML5)));
        if ('' === $title) return false;
        $dom = HtmlSerialization::parseDocument('<html><body>' . $entry['canonical_block_markup'] . '</body></html>');
        foreach (array('h1', 'h2') as $tag) foreach ($dom->getElementsByTagName($tag) as $heading) {
            if ($title === trim((string) preg_replace('/\s+/u', ' ', $heading->textContent))) return true;
        }
        return false;
    }

    private static function px(float $value): string
    {
        return round($value, 3) . 'px';
    }

    /** Captured landmarks remain content-owned until shell extraction removes them. */
    public static function contentCarriesChrome(string $markup, string $area): bool
    {
        if (!in_array($area, array('header', 'footer'), true)) return false;
        if (1 === preg_match('~"area"\\s*:\\s*"' . $area . '"~i', $markup)) return true;
        $dom = HtmlSerialization::parseDocument('<html><body>' . $markup . '</body></html>');
        $xpath = new \DOMXPath($dom);
        return $xpath->query('//' . $area . '[not(ancestor::article) and not(ancestor::main) and not(ancestor::section)]')->length > 0;
    }

    /**
     * @param array<int,array<string,mixed>> $pages
     * @param array<int,array<string,mixed>> $parts
     * @param array<int,array<string,mixed>> $surfaces
     * @param array<int,array<string,mixed>> $tokens
     * @param array<int,array<string,mixed>> $taxonomyEntities
     * @param array<string,array<string,mixed>> $frames
     * @param array<string,string> $documentChrome Shared document chrome by template slug.
     * @param array{prose_width?:float|int|null,prose_padding?:list<float|int>|null,prose_columns?:list<array<string,mixed>>,separate?:bool} $authoredPage
     *        The captured entry document's prose column, which pages authored
     *        after the import render in. `separate` when captured pages other
     *        than a lone entry page render through page.html.
     * @return array<int,array<string,mixed>>
     */
    public static function build(RouteLinks $links, array $pages, array $parts, array $surfaces = array(), array $tokens = array(), ?AssetReferenceCanonicalizer $references = null, ?string $singleContent = null, array $taxonomyEntities = array(), array $frames = array(), array $documentChrome = array(), array $postTemplates = array(), array $authoredPage = array(), bool $preserveParagraphMargin = false): array
     {
         $fallbackProseWidth = isset($authoredPage['prose_width']) ? (float) $authoredPage['prose_width'] : null;
         $fallbackProsePadding = array_map('floatval', $authoredPage['prose_padding'] ?? array(0, 0));
         $fallbackProseColumns = $authoredPage['prose_columns'] ?? array();
         $separateAuthoredPage = !empty($authoredPage['separate']);
         $authoredTitle = self::entryShowsTitle($pages);
         $bound = array_values(array_filter($parts, static fn(array $part): bool => in_array($part['placement']['kind'] ?? '', array('entry_shell', 'shared_shell'), true)));
         usort($bound, static function (array $left, array $right): int {
             $priority = array('header' => 0, 'footer' => 2);
             return (($priority[$left['area']] ?? 1) <=> ($priority[$right['area']] ?? 1)) ?: strcmp($left['slug'], $right['slug']);
         });
         $hasCapturedPosts = (bool) array_filter($pages, static fn(array $page): bool => 'post' === ($page['post_type'] ?? null));
         $hasCommentForm = (bool) array_filter($pages, static fn(array $page): bool => !empty($page['metadata']['native_comment_form']));
         $markup = static function (string $templateSlug, bool $authored = false) use ($bound, $singleContent, $hasCapturedPosts, $frames, $hasCommentForm, $postTemplates, $pages, $fallbackProseWidth, $fallbackProsePadding, $fallbackProseColumns, $authoredTitle, $preserveParagraphMargin): string {
             $before = ''; $after = '';
             $container = null;
             foreach ($bound as $part) if (in_array($templateSlug, $part['placement']['template_slugs'] ?? array(), true) || TemplateSlugs::partAppliesToOverride($part, $templateSlug)
                 || ('archive' === $templateSlug && 'header' === $part['area'] && in_array('index', $part['placement']['template_slugs'] ?? array(), true) && !in_array($part['slug'], $frames['archive']['shells'] ?? array(), true))) {
                 // A lifecycle may bind chrome only after its captured content
                 // relinquishes that area, including responsive document copies.
                 $retainsChrome = false;
                 foreach ($pages as $page) if (TemplateSlugs::forPage($page) === $templateSlug && self::contentCarriesChrome($page['canonical_block_markup'], $part['area'])) $retainsChrome = true;
                 if ($retainsChrome) continue;
                 // The lifecycle's shared frame may already render this area.
                 if (isset($frames[$templateSlug]) && array() !== $frames[$templateSlug]['shells'] && self::contentCarriesChrome($frames[$templateSlug]['opening'] . $frames[$templateSlug]['closing'], (string) $part['area'])) continue;
                 if (is_array($part['placement']['container'] ?? null)) $container = $part['placement']['container'];
                 $reference = '<!-- wp:template-part {"slug":"' . $part['slug'] . '","area":"' . $part['area'] . '","tagName":"' . $part['tag_name'] . '","className":"blocks-engine-shell-transport"} /-->' . "\n";
                 $wrapper = $part['placement']['template_wrappers'][$templateSlug] ?? null;
                 if (is_array($wrapper) && is_string($wrapper['opening'] ?? null) && is_string($wrapper['closing'] ?? null)) $reference = $wrapper['opening'] . "\n" . $reference . $wrapper['closing'] . "\n";
                 if ('footer' === $part['area']) $after .= $reference; else $before .= $reference;
             }
              if (in_array($templateSlug, array('index', 'search', 'archive'), true)) {
                  // Each title renders only in its own context, so one listing
                  // serves the home, archive and search lifecycles alike.
                  $query = '<!-- wp:query-title {"type":"archive"} /-->' . "\n" . '<!-- wp:query-title {"type":"search"} /-->' . "\n"
                      . PostBlocks::defaultQueryLoop(true, $preserveParagraphMargin);
                  $content = '<!-- wp:group {"tagName":"main","layout":{"type":"constrained"}} -->' . "\n" . '<main class="wp-block-group">' . "\n" . $query . "\n" . '</main>' . "\n" . '<!-- /wp:group -->';
              } elseif ('404' === $templateSlug) {
                  $content = '<!-- wp:group {"tagName":"main","layout":{"type":"constrained"}} -->' . "\n" . '<main class="wp-block-group">' . "\n" . '<!-- wp:heading {"level":1} --><h1 class="wp-block-heading">Page not found</h1><!-- /wp:heading -->' . "\n" . '<!-- wp:search {"label":"Search","showLabel":false,"buttonText":"Search"} /-->' . "\n" . '</main>' . "\n" . '<!-- /wp:group -->';
             } else {
                 $content = $postTemplates[$templateSlug] ?? (('single' === $templateSlug && is_string($singleContent) && '' !== $singleContent) ? $singleContent : '<!-- wp:post-content /-->');
                 if ('single' === $templateSlug && !$hasCapturedPosts) {
                     $content = '<!-- wp:group {"tagName":"main","layout":{"type":"constrained"}} --><main class="wp-block-group"><!-- wp:post-title {"level":1} /--><!-- wp:post-content /--></main><!-- /wp:group -->';
                 }
             }
            // Captured pages own their bespoke layout, title included, in
            // content. New pages use the entry's prose column, not its
            // full-width app shell, and show their own title where the entry
            // shows its title as a heading.
            $title = $authored && $authoredTitle ? '<!-- wp:post-title {"level":1} /-->' : '';
            if ($authored && null !== $fallbackProseWidth && '<!-- wp:post-content /-->' === $content) {
                $layout = array('layout' => array('type' => 'constrained', 'contentSize' => self::px($fallbackProseWidth)));
                $style = '';
                if ($fallbackProsePadding[0] > 0 || $fallbackProsePadding[1] > 0) {
                    $layout = array('style' => array('spacing' => array('padding' => array('left' => self::px($fallbackProsePadding[0]), 'right' => self::px($fallbackProsePadding[1]))))) + $layout;
                    $style = ' style="padding-right:' . self::px($fallbackProsePadding[1]) . ';padding-left:' . self::px($fallbackProsePadding[0]) . '"';
                }
                $content = '<!-- wp:group ' . json_encode($layout, JSON_UNESCAPED_SLASHES) . ' --><div class="wp-block-group"' . $style . '>' . $title . '<!-- wp:post-content {"layout":{"type":"constrained"}} /--></div><!-- /wp:group -->';
            }
            if ($authored && array() !== $fallbackProseColumns && !isset($postTemplates['page'])) {
                usort($fallbackProseColumns, static fn(array $a, array $b): int => $a['viewport'] <=> $b['viewport']);
                $first = $fallbackProseColumns[0];
                $last = $fallbackProseColumns[count($fallbackProseColumns) - 1];
                $margin = static function (float $start, float $end) use ($first, $last): string {
                    if ($first['viewport'] === $last['viewport'] || abs($end - $start) < 0.01) return $end . 'px';
                    $slope = ($end - $start) / ($last['viewport'] - $first['viewport']);
                    $offset = $start - $slope * $first['viewport'];
                    return 'clamp(' . min($start, $end) . 'px,calc(' . round(100 * $slope, 6) . 'vw + ' . round($offset, 6) . 'px),' . max($start, $end) . 'px)';
                };
                $left = $margin($first['left'], $last['left']);
                $right = $margin($first['viewport'] - $first['left'] - $first['width'], $last['viewport'] - $last['left'] - $last['width']);
                $attrs = json_encode(array('layout' => array('type' => 'default'), 'style' => array('spacing' => array('margin' => array('left' => $left, 'right' => $right)))), JSON_UNESCAPED_SLASHES);
                $content = '<!-- wp:group ' . $attrs . ' --><div class="wp-block-group" style="margin-right:' . $right . ';margin-left:' . $left . '">' . $title . '<!-- wp:post-content {"layout":{"type":"default"}} /--></div><!-- /wp:group -->';
            }
            if ($hasCommentForm && !str_contains($content, '<!-- wp:post-comments-form') && ('single' === $templateSlug || str_starts_with($templateSlug, 'single-'))) {
                $content .= "\n" . '<!-- wp:comments --><div class="wp-block-comments"><!-- wp:post-comments-form /--></div><!-- /wp:comments -->';
            }
            // Keep each lifecycle's native content inside the shared app frame.
            if (isset($frames[$templateSlug]) && array() !== $frames[$templateSlug]['shells']) {
                if ('<!-- wp:post-content /-->' === $content) $content = '<!-- wp:post-content {"className":"' . WordPressSitePlan::FRAME_CONTENT_CLASS . '"} /-->';
                // A frame that is the page's main landmark already holds the
                // lifecycle content; a document has one main.
                if (str_contains($frames[$templateSlug]['opening'], '"tagName":"main"')) $content = str_replace(array('<!-- wp:group {"tagName":"main",', '<main class="wp-block-group">', '</main>'), array('<!-- wp:group {', '<div class="wp-block-group">', '</div>'), $content);
                $content = $frames[$templateSlug]['opening'] . $content . $frames[$templateSlug]['closing'];
            }
            if (is_array($container) && is_string($container['opening'] ?? null) && is_string($container['closing'] ?? null)) return $container['opening'] . $before . $content . "\n" . $container['closing'] . $after;
            return $before . $content . "\n" . $after;
        };
         $make = static function (string $slug, string $target, string $content): array { return array('slug' => $slug, 'target_path' => $target, 'canonical_block_markup' => $content, 'source_relation' => 'generated_native_lifecycle', 'reconciliation_identity' => WordPressSitePlan::identity('template', 'wordpress-site-plan/' . $target, $target), 'content_hash' => WordPressSitePlan::contentHash($content)); };
         $templates = array($make('index', 'templates/index.html', $markup('index')));
         $templates[] = $make('search', 'templates/search.html', $markup('search'));
         $templates[] = $make('single', 'templates/single.html', $markup('single'));
         $templates[] = $make('archive', 'templates/archive.html', $markup('archive'));
         $templates[] = $make('404', 'templates/404.html', $markup('404'));
        if ( array() !== $pages ) $templates[] = $make('page', 'templates/page.html', $markup('page', !$separateAuthoredPage));
        // Captured pages share page.html, so pages authored after the import
        // render through their own template (ThemeBootstrap selects it).
        if ( array() !== $pages && $separateAuthoredPage && ($authoredMarkup = $markup('page', true)) !== $markup('page') ) $templates[] = $make(self::AUTHORED_PAGE, 'templates/' . self::AUTHORED_PAGE . '.html', $authoredMarkup);
        foreach ( $pages as $page ) if ( ! empty($page['entrypoint']) ) { $templates[] = $make('front-page', 'templates/front-page.html', $markup('front-page')); break; }
        $overrides = array_filter(TemplateSlugs::overrides($bound, array_keys($frames)), static fn(int|string $slug): bool => 1 === preg_match('/^(?:page|single)-[a-z0-9_-]+$/', (string) $slug), ARRAY_FILTER_USE_KEY);
        foreach ($pages as $page) if (isset($page['route_template']) && 'single' !== $page['route_template']) $overrides[$page['route_template']] = true;
        $liveOverrides = array_fill_keys(array_map(TemplateSlugs::forPage(...), $pages), true);
        $overrides = array_intersect_key($overrides, $liveOverrides);
        foreach (array_keys($overrides) as $slug) $templates[] = $make($slug, 'templates/' . $slug . '.html', $markup($slug));
        foreach ($templates as &$template) {
            $lifecycle = self::AUTHORED_PAGE === $template['slug'] ? 'page' : $template['slug'];
            if (!isset($documentChrome[$lifecycle])) continue;
            // Document chrome frames the post content (leading and trailing side
            // regions around the slot), so it replaces the template's own slot and
            // keeps that slot's attributes. A template without a slot keeps the
            // chrome after its markup.
            $chrome = $documentChrome[$lifecycle];
            $framed = preg_replace_callback('/<!-- wp:post-content(?:\s+\{.*?\})?\s*\/-->/', static fn(array $slot): string => str_replace('<!-- wp:post-content /-->', $slot[0], $chrome), $template['canonical_block_markup'], 1, $count);
            $template['canonical_block_markup'] = 1 === $count ? (string) $framed : $template['canonical_block_markup'] . "\n" . $chrome;
            $template['content_hash'] = WordPressSitePlan::contentHash($template['canonical_block_markup']);
        }
        unset($template);
        $templates = self::withoutRedundant($templates, $pages);
        foreach ($templates as &$template) if (isset($frames[$lifecycle = self::AUTHORED_PAGE === $template['slug'] ? 'page' : $template['slug']])) $template['shell_frame'] = $frames[$lifecycle]['shells']; unset($template);
         $explicitSlugs = array_fill_keys(array_map(static fn(array $surface): string => $surface['template_surface']['slug'], $surfaces), true);
         $templates = array_values(array_filter($templates, static fn(array $template): bool => !isset($explicitSlugs[$template['slug']])));
         foreach ($surfaces as $surface) {
            $declaration = $surface['template_surface']; $slug = $declaration['slug']; $target = 'templates/' . $slug . '.html';
            if (array_filter($templates, static fn(array $template): bool => $template['slug'] === $slug)) throw new InvalidArgumentException('A declared template surface collides with a generated template.');
            $content = is_null($references) ? (string) $surface['block_markup'] : $links->markup($references->content((string) $surface['block_markup'], (string) $surface['source_path']), (string) $surface['source_path']);
             $templates[] = array('slug' => $slug, 'target_path' => $target, 'canonical_block_markup' => $content, 'source_path' => $surface['source_path'], 'template_surface' => $declaration, 'provenance' => $surface['provenance'] ?? array(), 'reconciliation_identity' => WordPressSitePlan::identity('template', $surface['source_path'], $target), 'content_hash' => WordPressSitePlan::contentHash($content));
         }
         foreach ($taxonomyEntities as $entity) {
             if (!isset($entity['archive']) || !array_key_exists('presentation_markup', $entity['archive'])) continue;
             $slug = TemplateSlugs::forArchive($entity);
             $target = 'templates/' . $slug . '.html';
             if (array_filter($templates, static fn(array $template): bool => $template['slug'] === $slug)) throw new InvalidArgumentException('A native archive template collides with a declared template.');
             $content = (string) $entity['archive']['presentation_markup'];
             $before = ''; $after = '';
              foreach ($bound as $part) {
                  if (!in_array('archive', $part['placement']['template_slugs'] ?? array(), true) || str_contains($content, '"slug":"' . $part['slug'] . '"') || self::contentCarriesChrome($content, $part['area'])) continue;
                 $reference = '<!-- wp:template-part {"slug":"' . $part['slug'] . '","area":"' . $part['area'] . '","tagName":"' . $part['tag_name'] . '","className":"blocks-engine-shell-transport"} /-->' . "\n";
                 $wrapper = $part['placement']['template_wrappers']['archive'] ?? null;
                 if (is_array($wrapper) && is_string($wrapper['opening'] ?? null) && is_string($wrapper['closing'] ?? null)) $reference = $wrapper['opening'] . "\n" . $reference . $wrapper['closing'] . "\n";
                  if ('footer' === $part['area']) $after .= $reference; else $before .= $reference;
              }
              $nestedShellMarkup = '';
              foreach ($bound as $part) if (in_array('archive', $part['placement']['template_slugs'] ?? array(), true)) $nestedShellMarkup .= (string) $part['canonical_block_markup'];
              foreach ($parts as $part) {
                  if ('inline_shared_shell' !== ($part['placement']['kind'] ?? null) || !in_array($entity['archive']['source_path'], $part['placement']['source_paths'] ?? array(), true) || str_contains($content . $nestedShellMarkup, '"slug":"' . $part['slug'] . '"') || self::contentCarriesChrome($content, $part['area'])) continue;
                  $reference = '<!-- wp:template-part {"slug":"' . $part['slug'] . '","area":"' . $part['area'] . '","tagName":"' . $part['tag_name'] . '","className":"blocks-engine-shell-transport"} /-->' . "\n";
                  if ('footer' === $part['area']) $after .= $reference; else $before .= $reference;
              }
             $content = $before . $content . "\n" . $after;
             $template = $make($slug, $target, $content);
             $template['source_path'] = $entity['archive']['source_path'];
             if ('author' === $entity['kind']) $template['author_archive'] = array('slug' => $entity['slug'], 'source_route' => $entity['archive']['source_route']);
             else $template['taxonomy_archive'] = array('taxonomy' => $entity['taxonomy'], 'slug' => $entity['slug'], 'source_route' => $entity['archive']['source_route']);
             $template['reconciliation_identity'] = WordPressSitePlan::identity('template', $template['source_path'], $target);
             $template['content_hash'] = WordPressSitePlan::contentHash($content);
             $templates[] = $template;
         }
         // The first proven author supplies the native author fallback as well
         // as its source-specific template. Other authors retain their own
         // captured presentations through WordPress's author-nicename hierarchy.
         if (!array_filter($templates, static fn(array $template): bool => 'author' === $template['slug'])) {
             foreach ($templates as $template) if (isset($template['author_archive'])) {
                 $template['slug'] = 'author';
                 $template['target_path'] = 'templates/author.html';
                 $template['reconciliation_identity'] = WordPressSitePlan::identity('template', $template['source_path'], $template['target_path']);
                 $templates[] = $template;
                 break;
             }
         }
         return $templates;
    }

    /**
     * Prove a template part is placed where its placement says, and nowhere
     * else: every template it binds (its placement's template slugs, the
     * route overrides it applies to, the archives it covers, and for an inline
     * shell the templates whose frame or archive carries it) references it at
     * least once and at most once per captured document copy, unless the
     * content already carries that chrome. Any other template must not
     * reference it.
     *
     * @param array<string,mixed> $part @param array<string,mixed> $plan @param array<string,bool> $overrideTemplateSlugs
     */
    public static function assertPartBound(array $part, array $plan, array $overrideTemplateSlugs): void
    {
        $boundTemplates = in_array($part['placement']['kind'] ?? null, array('entry_shell', 'shared_shell'), true) ? $part['placement']['template_slugs'] : array();
        if (in_array($part['placement']['kind'] ?? null, array('entry_shell', 'shared_shell'), true)) foreach (array_keys($overrideTemplateSlugs) as $slug) if (TemplateSlugs::partAppliesToOverride($part, $slug)) $boundTemplates[] = $slug;
        if (in_array('archive', $part['placement']['template_slugs'] ?? array(), true)) foreach (array_merge($plan['taxonomy_entities'], $plan['author_entities']) as $entity) {
            if (!isset($entity['archive'])) continue;
            $boundTemplates[] = TemplateSlugs::forArchive($entity);
            if ('author' === $entity['kind']) $boundTemplates[] = 'author';
        }
        if ('inline_shared_shell' === ($part['placement']['kind'] ?? null)) foreach ($plan['templates'] as $template) if (in_array($part['slug'], $template['shell_frame'] ?? array(), true)) $boundTemplates[] = $template['slug'];
        if ('inline_shared_shell' === ($part['placement']['kind'] ?? null)) foreach (array_merge($plan['taxonomy_entities'], $plan['author_entities']) as $entity) if (isset($entity['archive']) && in_array($entity['archive']['source_path'], $part['placement']['source_paths'] ?? array(), true)) foreach ($plan['templates'] as $template) if ((TemplateSlugs::forArchive($entity) === ($template['slug'] ?? null) || ('author' === $entity['kind'] && 'author' === ($template['slug'] ?? null) && $template['source_path'] === $entity['archive']['source_path'])) && str_contains($template['canonical_block_markup'], '"slug":"' . $part['slug'] . '"')) $boundTemplates[] = $template['slug'];
        // The new-page template renders the page lifecycle's chrome.
        if (in_array('page', $boundTemplates, true)) $boundTemplates[] = self::AUTHORED_PAGE;
        foreach ( $plan['templates'] as $template ) {
            $references = substr_count($template['canonical_block_markup'], '"slug":"' . $part['slug'] . '"');
            // An inline shell is bound where it sits, so a template that
            // keeps every captured document copy binds it once per copy.
            // Shared footer content is also referenced once per footer its
            // source document authors, as extraction recorded.
            $allowed = 'inline_shared_shell' === ($part['placement']['kind'] ?? null) ? max(self::documentCopyCount($template['canonical_block_markup']), (int) ($part['placement']['references_per_source'][$template['source_path'] ?? ''] ?? 0)) : 1;
            $contentOwnsChrome = self::contentCarriesChrome($template['canonical_block_markup'], $part['area']);
            foreach ($plan['pages'] as $page) if (TemplateSlugs::forPage($page) === $template['slug'] && self::contentCarriesChrome($page['canonical_block_markup'], $part['area'])) $contentOwnsChrome = true;
            if (0 === $references && $contentOwnsChrome) continue;
            if (in_array($template['slug'], $boundTemplates, true) && ($references < 1 || $references > $allowed)) throw new InvalidArgumentException('WordPress site plan template part binding is invalid.');
            if (!in_array($template['slug'], $boundTemplates, true) && 0 !== $references) throw new InvalidArgumentException('WordPress site plan has an unproven template part binding.');
        }
    }

    /** Rendered elements rooting a captured document copy; at least one. */
    private static function documentCopyCount(string $markup): int
    {
        $html = (string) preg_replace('/<!--.*?-->/s', '', $markup);
        $copies = 0;
        if (preg_match_all('/<[a-z][a-z0-9-]*\\s[^>]*?\\bclass="([^"]*)"/i', $html, $matches)) {
            foreach ($matches[1] as $classes) {
                if (array_filter(preg_split('/\\s+/', $classes) ?: array(), ShellExtraction::isResponsiveVariantClass(...))) ++$copies;
            }
        }

        return max(1, $copies);
    }
}
