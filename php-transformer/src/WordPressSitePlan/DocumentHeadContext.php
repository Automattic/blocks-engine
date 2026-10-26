<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan;

use Automattic\BlocksEngine\PhpTransformer\Support\HtmlTagScanner;
use InvalidArgumentException;

/** Ordered, source-owned head DOM. Asset bodies come from canonical writes. */
final class DocumentHeadContext
{
    public const SCHEMA = 'blocks-engine/document-head/v1';
    public const MAX_ELEMENTS = 512;
    private const MAX_ATTRIBUTES = 32;
    private const MAX_ATTRIBUTE_BYTES = 8192;
    private const MAX_INLINE_DATA_BYTES = 1048576;
    private const MAX_DATA_URL_BYTES = 1048576;

    /**
     * A selector-bearing head declaration requires a real head materializer.
     * Ordinary descriptive metadata retains its existing consumer-owned routing.
     * @param list<array<string,mixed>> $files
     * @return array<string,mixed>|null
     */
    public static function fromHtml(string $html, string $sourcePath, array $files): ?array
    {
        $declared = false;
        foreach (array('meta', 'link', 'style') as $tag) foreach (HtmlTagScanner::scan($html, $tag) as $element) {
            if ('head' !== $element['placement']) continue;
            foreach (HtmlTagScanner::attributes($element['tag']) as $name => $value) {
                if (in_array($name, array('id', 'class'), true) || str_starts_with($name, 'data-')) $declared = true;
            }
        }
        if (!$declared) return null;

        $inline = array();
        foreach ($files as $file) {
            if ($sourcePath !== ($file['source_path'] ?? null)) continue;
            if ('inline-script' === ($file['source'] ?? null)) $inline['script'][$file['selector'] ?? ''] = $file['path'];
            if ('inline-style' === ($file['source'] ?? null)) $inline['style'][(int) ($file['stylesheet_index'] ?? 0)] = $file['path'];
        }
        $elements = array();
        foreach (array('meta', 'link', 'style', 'script') as $tag) {
            $styleIndex = 0;
            foreach (HtmlTagScanner::scan($html, $tag) as $index => $declaration) {
                $attributes = HtmlTagScanner::attributes($declaration['tag']);
                if ('style' === $tag && '' !== trim($declaration['content']) && in_array(strtolower($attributes['type'] ?? ''), array('', 'text/css'), true)) ++$styleIndex;
                if ('head' !== $declaration['placement']) continue;
                if (isset($attributes['data-blocks-engine-marker-runtime']) || isset($attributes['data-blocks-engine-superseded-by'])) continue;
                // A link with no href, or an empty one, has no URL to bind.
                // Document link metadata skips it too (e.g. an imagesrcset-only preload).
                if ('link' === $tag && '' === ($attributes['href'] ?? '')) continue;
                $row = array('tag' => $tag, 'attributes' => $attributes);
                if ('link' === $tag) { $row['url'] = $attributes['href']; $row['selector'] = 'link:nth-of-type(' . ($index + 1) . ')'; unset($row['attributes']['href']); }
                if ('script' === $tag) {
                    $row['url'] = $attributes['src'] ?? ($inline['script']['script:nth-of-type(' . ($index + 1) . ')'] ?? '');
                    $row['inline'] = !isset($attributes['src']);
                    unset($row['attributes']['src']);
                    if ('' === trim($declaration['content']) && '' === $row['url']) continue;
                    if ($row['inline'] && !WordPressSitePlan::isExecutableScriptType($attributes['type'] ?? '', 'module' === strtolower($attributes['type'] ?? ''))) {
                        $row['content'] = $declaration['content'];
                        $row['body_hash'] = hash('sha256', trim($declaration['content']));
                        unset($row['url']);
                    }
                }
                if ('style' === $tag) {
                    if ('' === trim($declaration['content'])) continue;
                    $row['url'] = $inline['style'][$styleIndex] ?? '';
                }
                $elements[$declaration['offset']] = $row;
            }
        }
        ksort($elements);
        $head = array('schema' => self::SCHEMA, 'elements' => array_values($elements));
        // Duplicate viewports and unrepresentable attributes or elements are
        // page-local limits, not integrity failures. The site plan drops them
        // and reports each one (see keepLastViewport and withoutUnrepresentable),
        // so only the rest of the head has to be valid here.
        self::assertValid(self::withoutUnrepresentable($head)[0], false, false);
        return $head;
    }

    /**
     * Drops what a head cannot carry, smallest unit first: an event handler,
     * an invalid or oversized attribute, then an element that is still over a
     * budget. Malformed structure is left in place for assertValid() to reject.
     *
     * @param array<string,mixed> $head
     * @return array{0:array<string,mixed>,1:list<array{code:string,reason_code:string,tag:string,name:string}>} The head and one row per dropped attribute or element.
     */
    public static function withoutUnrepresentable(array $head): array
    {
        if (!is_array($head['elements'] ?? null) || !array_is_list($head['elements'])) return array($head, array());
        $dropped = array();
        $elements = array();
        foreach ($head['elements'] as $row) {
            if (!is_array($row) || !is_string($row['tag'] ?? null) || !is_array($row['attributes'] ?? null)) { $elements[] = $row; continue; }
            foreach ($row['attributes'] as $name => $value) {
                $reason = self::unrepresentableAttribute($name, $value);
                if (null === $reason) continue;
                unset($row['attributes'][$name]);
                $dropped[] = array('code' => 'document_head_attribute_dropped', 'reason_code' => $reason, 'tag' => $row['tag'], 'name' => (string) $name);
            }
            $reason = self::unrepresentableElement($row);
            if (null !== $reason) { $dropped[] = array('code' => 'document_head_element_dropped', 'reason_code' => $reason, 'tag' => $row['tag'], 'name' => ''); continue; }
            $elements[] = $row;
        }
        // Keep the head in source order up to its budget; later elements are dropped.
        foreach (array_slice($elements, self::MAX_ELEMENTS) as $row) $dropped[] = array('code' => 'document_head_element_dropped', 'reason_code' => 'element_budget', 'tag' => is_array($row) ? (string) ($row['tag'] ?? '') : '', 'name' => '');
        $head['elements'] = array_slice($elements, 0, self::MAX_ELEMENTS);
        return array($head, $dropped);
    }

    private static function unrepresentableAttribute(mixed $name, mixed $value): ?string
    {
        if (!is_string($name)) return 'invalid_name';
        // Event handlers are executable source behavior; the head carries markup only.
        if (str_starts_with($name, 'on')) return 'event_handler';
        if (!preg_match('/^[a-z][a-z0-9_-]{0,127}$/', $name)) return 'invalid_name';
        if (in_array($name, array('src', 'href', 'srcdoc'), true)) return 'reserved_name';
        if (!is_string($value) || str_contains($value, "\0")) return 'invalid_value';
        return strlen($value) > self::MAX_ATTRIBUTE_BYTES ? 'oversized_value' : null;
    }

    /** @param array<string,mixed> $row */
    private static function unrepresentableElement(array $row): ?string
    {
        if (count($row['attributes']) > self::MAX_ATTRIBUTES) return 'attribute_budget';
        if (isset($row['content']) && is_string($row['content']) && strlen($row['content']) > self::MAX_INLINE_DATA_BYTES) return 'oversized_inline_data';
        $reference = $row['asset_reference'] ?? $row['url'] ?? null;
        if ('link' === $row['tag'] && is_string($reference) && 1 === preg_match('~^data:~i', $reference) && strlen($reference) > self::MAX_DATA_URL_BYTES) return 'oversized_data_url';
        return null;
    }

    /**
     * Browsers honor the last viewport meta, so keep that one.
     * @param array<string,mixed> $head
     * @return array{0:array<string,mixed>,1:int} The head and how many earlier viewports were dropped.
     */
    public static function keepLastViewport(array $head): array
    {
        $isViewport = static fn(mixed $row): bool => is_array($row) && 'meta' === ($row['tag'] ?? null) && is_array($row['attributes'] ?? null) && 'viewport' === strtolower((string) ($row['attributes']['name'] ?? ''));
        $last = null;
        foreach (($head['elements'] ?? array()) as $index => $row) if ($isViewport($row)) $last = $index;
        $dropped = 0;
        $elements = array();
        foreach (($head['elements'] ?? array()) as $index => $row) {
            if ($isViewport($row) && $index !== $last) { ++$dropped; continue; }
            $elements[] = $row;
        }
        if (0 < $dropped) $head['elements'] = $elements;
        return array($head, $dropped);
    }

    public static function assertValid(mixed $head, bool $canonical = true, bool $uniqueViewport = true): void
    {
        if (!is_array($head) || self::SCHEMA !== ($head['schema'] ?? null) || array('schema', 'elements') !== array_keys($head) || !is_array($head['elements']) || !array_is_list($head['elements']) || count($head['elements']) > self::MAX_ELEMENTS) throw new InvalidArgumentException('Document head context is invalid or exceeds its element budget.');
        $viewports = 0;
        foreach ($head['elements'] as $row) {
            if (!is_array($row) || !in_array($row['tag'] ?? null, array('meta', 'link', 'style', 'script'), true) || !is_array($row['attributes'] ?? null) || count($row['attributes']) > self::MAX_ATTRIBUTES || array_diff(array_keys($row), array('tag', 'attributes', 'url', 'asset_reference', 'resolved_url', 'inline', 'content', 'body_hash', 'selector'))) throw new InvalidArgumentException('Document head element is invalid.');
            if ('link' === $row['tag'] && (!is_string($row['selector'] ?? null) || !preg_match('/^link:nth-of-type\([1-9][0-9]*\)$/', $row['selector']))) throw new InvalidArgumentException('Document head link lacks source occurrence provenance.');
            foreach ($row['attributes'] as $name => $value) {
                if (!is_string($name) || !preg_match('/^[a-z][a-z0-9_-]{0,127}$/', $name) || str_starts_with($name, 'on') || in_array($name, array('src', 'href', 'srcdoc'), true) || !is_string($value) || strlen($value) > self::MAX_ATTRIBUTE_BYTES || str_contains($value, "\0")) throw new InvalidArgumentException('Document head attributes are invalid or exceed their budget.');
            }
            if ('meta' === $row['tag'] && 'viewport' === strtolower($row['attributes']['name'] ?? '')) ++$viewports;
            if ('script' === $row['tag'] && !is_bool($row['inline'] ?? null)) throw new InvalidArgumentException('Document head script must declare inline or external source semantics.');
            if (isset($row['content'])) {
                if ('script' !== $row['tag'] || !$row['inline'] || !is_string($row['content']) || strlen($row['content']) > self::MAX_INLINE_DATA_BYTES || WordPressSitePlan::isExecutableScriptType($row['attributes']['type'] ?? '', 'module' === strtolower($row['attributes']['type'] ?? '')) || ($row['body_hash'] ?? null) !== hash('sha256', trim($row['content'])) || isset($row['url']) || isset($row['asset_reference'])) throw new InvalidArgumentException('Document head inline data declaration is invalid or exceeds its budget.');
                continue;
            }
            if ('meta' !== $row['tag']) {
                $reference = $row['asset_reference'] ?? $row['url'] ?? null;
                $route = 'link' === $row['tag'] && self::isRouteLink($row['attributes']) && is_string($reference) && preg_match('~^/(?:[a-z0-9_-]+(?:/[a-z0-9_-]+)*)?(?:[?#].*)?$~', $reference);
                // A data: URL carries its own bytes, so a link has no write to bind.
                // Scripts keep the script-loading contract, which requires a write or HTTP(S) URL.
                $embedded = 'link' === $row['tag'] && is_string($reference) && 1 === preg_match('~^data:~i', $reference);
                if ($canonical && $embedded && strlen($reference) > self::MAX_DATA_URL_BYTES) throw new InvalidArgumentException('Document head data URL exceeds its budget.');
                if (!is_string($reference) || '' === $reference || ($canonical && !$route && !$embedded && !str_starts_with($reference, WordPressSitePlan::TOKEN_PREFIX) && !preg_match('~^(?:https?:)?//~i', $reference))) throw new InvalidArgumentException('Document head ' . $row['tag'] . ' asset is not bound to a declared write or external URL: ' . mb_strcut((string) $reference, 0, 160, 'UTF-8'));
                if ('style' === $row['tag'] && $canonical && !isset($row['asset_reference'])) throw new InvalidArgumentException('Document head style must bind a canonical stylesheet write.');
            }
        }
        if ($uniqueViewport && 1 < $viewports) throw new InvalidArgumentException('Document head must declare a unique viewport.');
    }

    /** Document navigation relationships are routes; subresource links are assets. */
    public static function isRouteLink(array $attributes): bool
    {
        $relations = preg_split('/\s+/', strtolower(trim($attributes['rel'] ?? ''))) ?: array();
        return array() !== $relations && array() === array_diff($relations, array('canonical', 'alternate', 'next', 'prev', 'author', 'license', 'help')) && !isset($attributes['as']) && !in_array(strtolower($attributes['type'] ?? ''), array('text/css', 'text/javascript'), true);
    }

    /** Same DOM bytes used by the proof and by the generated theme. Style rows render as stylesheet links. */
    public static function render(array $head, array $assets, array $tokens, string $entryRoot = ''): string
    {
        self::assertValid($head);
        $byToken = array();
        foreach ($assets as $asset) $byToken[WordPressSitePlan::TOKEN_PREFIX . $asset['token'] . '}}'] = $asset;
        $references = new AssetReferenceCanonicalizer($tokens, $entryRoot);
        $html = '';
        foreach ($head['elements'] as $row) {
            $tag = $row['tag']; $attributes = $row['attributes']; $body = '';
            $reference = $row['asset_reference'] ?? $row['url'] ?? '';
            if ('link' === $tag || ('script' === $tag && !$row['inline'])) $attributes['link' === $tag ? 'href' : 'src'] = $reference;
            if ('script' === $tag && $row['inline']) {
                $asset = $byToken[$reference] ?? null;
                $body = $row['content'] ?? $asset['content'] ?? null;
                if (!is_string($body) || (!isset($row['content']) && 'js' !== ($asset['kind'] ?? null))) throw new InvalidArgumentException('Document head inline script lacks a canonical script payload.');
                if (!isset($row['content'])) $body = $references->content($body, $asset['source_path']);
                if (preg_match('~</script\b~i', $body)) throw new InvalidArgumentException('Document head script cannot close its raw-text element.');
            }
            // A style row links its canonical CSS write in place; the theme file owns the bytes.
            if ('style' === $tag) {
                if ('css' !== ($byToken[$reference]['kind'] ?? null)) throw new InvalidArgumentException('Document head style lacks a canonical CSS write.');
                $tag = 'link';
                $attributes = array('rel' => 'stylesheet', 'href' => $reference) + array_filter($attributes, static fn(string $name): bool => in_array($name, array('media', 'id', 'class', 'nonce', 'title'), true) || str_starts_with($name, 'data-'), ARRAY_FILTER_USE_KEY);
            }
            $html .= '<' . $tag;
            foreach ($attributes as $name => $value) $html .= ' ' . $name . '="' . htmlspecialchars($value, ENT_QUOTES | ENT_HTML5, 'UTF-8') . '"';
            $html .= '>' . ('script' === $tag ? $body . '</' . $tag . '>' : '') . "\n";
        }
        return $html;
    }

    /** @return array<string,array<string,true>> */
    public static function ownedAssets(array $pages): array
    {
        $owned = array();
        foreach ($pages as $page) foreach ($page['document_metadata']['head']['elements'] ?? array() as $row) {
            $reference = $row['asset_reference'] ?? $row['url'] ?? null;
            if (is_string($reference)) $owned[$page['source_path']][$reference] = true;
        }
        return $owned;
    }

    public static function bootstrap(array $pages, array $assets, array $tokens, array $taxonomyEntities = array()): string
    {
        $rows = array(); $paths = array();
        $taxonomyArchives = array();
        foreach ($taxonomyEntities as $entity) {
            $sourcePath = is_array($entity) ? ($entity['archive']['source_path'] ?? null) : null;
            $taxonomy = is_array($entity) ? ($entity['taxonomy'] ?? null) : null;
            $slug = is_array($entity) ? ($entity['slug'] ?? null) : null;
            if ('author' === ($entity['kind'] ?? null) && is_string($sourcePath) && is_string($slug)) $taxonomyArchives[$sourcePath] = array('author_slug' => $slug);
            elseif (is_string($sourcePath) && is_string($taxonomy) && is_string($slug)) $taxonomyArchives[$sourcePath] = array('taxonomy' => $taxonomy, 'term_slug' => $slug);
        }
        // Native archives have source-owned heads but are not materialized pages.
        foreach ($taxonomyEntities as $entity) {
            if (!isset($entity['archive']['document_head'])) continue;
            $archive = $entity['archive'];
            $pages[] = array('source_path' => $archive['source_path'], 'post_type' => 'page', 'route' => array('path' => $archive['source_route']), 'reconciliation_identity' => WordPressSitePlan::identity('page', $archive['source_path'], $archive['source_route']), 'document_metadata' => array('head' => $archive['document_head']));
        }
        foreach ($pages as $page) {
            if (!isset($page['document_metadata']['head']) || !empty($page['synthetic'])) continue;
            $head = $page['document_metadata']['head'];
            $ownedPaths = array();
            foreach ($head['elements'] as $element) {
                if ('style' !== $element['tag'] && !('link' === $element['tag'] && in_array('stylesheet', preg_split('/\s+/', strtolower($element['attributes']['rel'] ?? '')) ?: array(), true))) continue;
                foreach ($assets as $asset) {
                    $owned = ($element['asset_reference'] ?? null) === WordPressSitePlan::TOKEN_PREFIX . $asset['token'] . '}}';
                    if ('stylesheet-occurrence' === ($asset['source'] ?? null)) foreach ($asset['references'] ?? array() as $reference) {
                        if ($page['source_path'] === ($reference['source_path'] ?? null) && ($element['selector'] ?? '') === ($reference['selector'] ?? null)) $owned = true;
                    }
                    if ($owned) $ownedPaths[$asset['target_path']] = true;
                }
            }
            // The head replays its stylesheets at their parser positions, so it
            // dequeues their ordered instance handles (#2634). WordPress prints
            // the rest of the queue first (Core block styles, then before-author
            // engine support); the replay follows it and prints the stylesheets
            // the enqueue order places after the head's own (after-author) last.
            $styles = $after = array();
            foreach (WordPressSitePlan::documentStylesheets(array('assets' => $assets, 'pages' => $pages), $page['source_path']) as $stylesheet) {
                if (isset($ownedPaths[$stylesheet['target_path']])) { $styles[] = $stylesheet['handle']; $after = array(); }
                elseif (array() !== $styles) $after[] = $stylesheet['handle'];
            }
            $row = array('identity' => $page['reconciliation_identity'], 'path' => trim($page['route']['path'], '/'), 'front_page' => !empty($page['entrypoint']), 'html' => self::render($head, $assets, $tokens, WordPressSitePlan::entryRootFromDocuments($pages)), 'styles' => $styles, 'after' => $after, 'viewport' => (bool) array_filter($head['elements'], static fn(array $row): bool => 'meta' === $row['tag'] && 'viewport' === strtolower($row['attributes']['name'] ?? '')));
            if (isset($taxonomyArchives[$page['source_path']])) $row += $taxonomyArchives[$page['source_path']];
            $rows[] = $row;
        }
        if (array() === $rows) return '';
        foreach ($tokens as $token) $paths[WordPressSitePlan::TOKEN_PREFIX . $token['token'] . '}}'] = $token['target_path'];
        $code = <<<'PHP'
add_filter( 'template_include', static function ( $template ) use ( $blocks_engine_document_heads, $blocks_engine_head_assets ) {
    $id = (int) apply_filters( 'blocks_engine_document_id', is_singular() ? get_queried_object_id() : 0 );
    $identity = $id ? get_post_meta( $id, '_blocks_engine_reconciliation_identity', true ) : '';
    $queried_term = function_exists( 'get_queried_object' ) ? get_queried_object() : null;
    foreach ( $blocks_engine_document_heads as $row ) {
        if ( isset( $row['taxonomy'], $row['term_slug'] ) ) {
            if ( ! is_object( $queried_term ) || ( $queried_term->taxonomy ?? '' ) !== $row['taxonomy'] || ( $queried_term->slug ?? '' ) !== $row['term_slug'] ) continue;
        } elseif ( isset( $row['author_slug'] ) ) {
            if ( ! is_author() || ! is_object( $queried_term ) || ( $queried_term->user_nicename ?? '' ) !== $row['author_slug'] ) continue;
        } elseif ( '' !== $identity ? $identity !== $row['identity'] : !( ( $row['front_page'] && is_front_page() ) || ( 0 !== $id && 'page' === get_post_type( $id ) && $row['path'] === trim( get_page_uri( $id ), '/' ) ) ) ) continue;
        if ( $row['viewport'] ) remove_action( 'wp_head', '_block_template_viewport_meta_tag', 0 );
        add_action( 'wp_enqueue_scripts', static function () use ( $row ): void {
            foreach ( array_merge( $row['styles'], $row['after'] ) as $handle ) wp_dequeue_style( $handle );
        }, PHP_INT_MAX );
        add_action( 'wp_head', static function () use ( $row, $blocks_engine_head_assets ): void {
            $references = array();
            foreach ( $blocks_engine_head_assets as $token => $path ) $references[$token] = get_theme_file_uri( implode( '/', array_map( 'rawurlencode', explode( '/', $path ) ) ) );
            echo strtr( $row['html'], $references );
            if ( array() !== $row['after'] ) wp_print_styles( $row['after'] );
        }, 8 );
        break;
    }
    return $template;
}, PHP_INT_MAX );
PHP;
        return '$blocks_engine_document_heads = ' . var_export($rows, true) . ";\n" . '$blocks_engine_head_assets = ' . var_export($paths, true) . ";\n" . $code . "\n";
    }

    /** Only validated plans with the matching emitted bootstrap can supply proof. */
    public static function fromPlan(array $plan, string $sourcePath): string
    {
        WordPressSitePlan::assertValid($plan);
        foreach ($plan['pages'] as $page) {
            if ($sourcePath === $page['source_path'] && isset($page['document_metadata']['head'])) return self::render($page['document_metadata']['head'], $plan['assets'], $plan['reference_tokens'], WordPressSitePlan::entryRootFromDocuments($plan['pages']));
        }
        return '';
    }
}
