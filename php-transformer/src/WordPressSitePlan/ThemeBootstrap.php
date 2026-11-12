<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan;

use Automattic\BlocksEngine\PhpTransformer\Contract\CaptureEvidence;
use Automattic\BlocksEngine\PhpTransformer\Path\ArtifactPath;
use Automattic\BlocksEngine\PhpTransformer\Support\ArrayField;
use Automattic\BlocksEngine\PhpTransformer\Support\StylesheetActivation;

/**
 * Writes the generated theme's `functions.php` and answers which stylesheets
 * WordPress loads for a document.
 *
 * The plan's assets, parts, templates and pages become one PHP bootstrap:
 * stylesheet enqueues scoped to the documents and devices that load them,
 * editor styles, script registrations, and the render filters that restore
 * captured behavior core blocks cannot save. `documentStylesheets()` reads
 * the same enqueue table, so tests and tools see exactly what WordPress loads.
 *
 * Stateless: every method is a pure function of its inputs.
 */
final class ThemeBootstrap
{
    public const EDITOR_CORE_IMAGE_INTERACTION_CSS = ':root .block-editor-block-list__block.wp-block-image img{pointer-events:auto!important}';

    public const EDITOR_POST_TITLE_INTERACTION_CSS = ':root .editor-post-title{position:relative;z-index:100000;pointer-events:auto!important}';

    public const EDITOR_LINK_INTERACTION_CSS = ':root .editor-styles-wrapper a[href]{pointer-events:none!important}';

    public const LISTING_QUERY_CSS = '.wp-block-query.blocks-engine-listing-query,.wp-block-query.blocks-engine-listing-query .wp-block-post-template,.wp-block-query.blocks-engine-listing-query .wp-block-post,.wp-block-query.blocks-engine-listing-query .wp-block-post-content{display:contents;list-style:none;margin:0;padding:0}.blocks-engine-listing-query .blocks-engine-authored-excerpt>p{margin:0}.blocks-engine-listing-overlay{width:auto}.blocks-engine-listing-card{position:relative}.blocks-engine-listing-card>.blocks-engine-listing-overlay{position:absolute;inset:0;z-index:2}.blocks-engine-listing-card>.blocks-engine-listing-background{position:absolute;inset:0;margin:0;pointer-events:none;z-index:0}.blocks-engine-listing-background img{width:100%;height:100%;object-fit:cover}.blocks-engine-listing-card>:not(.blocks-engine-listing-background):not(.blocks-engine-listing-overlay){position:relative;z-index:1}';

    /**
     * A generated theme reproduces captured text, so WordPress typographic
     * rewriting stays off while it is active. Static block content survives
     * texturization only because its punctuation is entity encoded; text that a
     * dynamic block decodes and prints is rewritten, so the guarantee belongs to
     * the theme rather than to any one block.
     */
    public const SOURCE_TEXT_TYPOGRAPHY = <<<'PHP'
add_filter( 'run_wptexturize', '__return_false' );
PHP;

    /** Keep native emoji unless capture proves the source used core's runtime. */
    public const SOURCE_NATIVE_EMOJI = <<<'PHP'
remove_action( 'wp_head', 'print_emoji_detection_script', 7 );
remove_action( 'embed_head', 'print_emoji_detection_script' );
add_action( 'admin_init', static function (): void { remove_action( 'admin_print_scripts', 'print_emoji_detection_script' ); } );
add_filter( 'tiny_mce_plugins', static function ( array $plugins ): array { return array_values( array_diff( $plugins, array( 'wpemoji' ) ) ); } );
PHP;

    /**
     * core/accordion-heading saves a fixed `+` icon slot with no artwork field.
     * A source-proved SVG rides in the heading's block metadata and is rendered
     * into that slot here, so saved markup stays core's exact shape while the
     * page paints the actual vector. Metadata is author-editable post content,
     * so the markup is sanitized to passive SVG on every render.
     */
    public const ACCORDION_ICON_RENDERER = <<<'PHP'
add_filter( 'render_block_core/accordion-heading', static function ( string $content, array $block ): string {
    $svg = $block['attrs']['metadata']['blocksEngineIcon'] ?? '';
    if ( ! is_string( $svg ) || '' === $svg ) return $content;
    $paint = array( 'fill' => true, 'fill-rule' => true, 'clip-rule' => true, 'stroke' => true, 'stroke-width' => true, 'stroke-linecap' => true, 'stroke-linejoin' => true, 'stroke-miterlimit' => true, 'stroke-dasharray' => true, 'stroke-dashoffset' => true, 'opacity' => true, 'fill-opacity' => true, 'stroke-opacity' => true, 'transform' => true, 'class' => true, 'style' => true );
    $shape = static fn ( array $geometry ): array => array_merge( $paint, array_fill_keys( $geometry, true ) );
    $svg = trim( wp_kses( $svg, array(
        'svg' => array_merge( $paint, array( 'xmlns' => true, 'width' => true, 'height' => true, 'viewbox' => true, 'preserveaspectratio' => true, 'aria-hidden' => true, 'focusable' => true, 'role' => true ) ),
        'g' => $paint,
        'path' => $shape( array( 'd' ) ),
        'circle' => $shape( array( 'cx', 'cy', 'r' ) ),
        'ellipse' => $shape( array( 'cx', 'cy', 'rx', 'ry' ) ),
        'line' => $shape( array( 'x1', 'y1', 'x2', 'y2' ) ),
        'polyline' => $shape( array( 'points' ) ),
        'polygon' => $shape( array( 'points' ) ),
        'rect' => $shape( array( 'x', 'y', 'width', 'height', 'rx', 'ry' ) ),
    ) ) );
    if ( ! str_starts_with( $svg, '<svg' ) ) return $content;
    return (string) preg_replace_callback( '/(<span\b[^>]*\bwp-block-accordion-heading__toggle-icon\b[^>]*>)[^<]*(<\/span>)/', static fn ( array $match ): string => $match[1] . $svg . $match[2], $content, 1 );
}, 10, 2 );
PHP;

    /**
     * A lowered srcset family keeps its authored candidates, slot sizes and
     * loading in core/image metadata. They describe the source `src`; once
     * the image is replaced, the new attachment's own candidates apply.
     */
    public const IMAGE_SELECTION_RENDERER = <<<'PHP'
add_filter( 'render_block_core/image', static function ( string $content, array $block ): string {
    $selection = $block['attrs']['metadata']['blocksEngineImageSelection'] ?? null;
    if ( ! is_array( $selection ) || ! is_string( $selection['src'] ?? null ) || ! is_string( $selection['srcset'] ?? null ) || '' === $selection['srcset'] ) return $content;
    $tag = new WP_HTML_Tag_Processor( $content );
    if ( ! $tag->next_tag( 'IMG' ) || $tag->get_attribute( 'src' ) !== $selection['src'] ) return $content;
    $tag->set_attribute( 'srcset', $selection['srcset'] );
    if ( is_string( $selection['sizes'] ?? null ) && '' !== $selection['sizes'] ) $tag->set_attribute( 'sizes', $selection['sizes'] );
    else $tag->remove_attribute( 'sizes' );
    if ( in_array( $selection['loading'] ?? null, array( 'lazy', 'eager' ), true ) ) $tag->set_attribute( 'loading', $selection['loading'] );
    return $tag->get_updated_html();
}, 10, 2 );
PHP;

    /**
     * The post the current request renders as a document: the queried post on
     * a singular view. A provider that renders an imported page's design on
     * another view (an archive of its own content type, for instance) names
     * that page through the `blocks_engine_document_id` filter, and the page's
     * route styles, document attributes, head, and current-navigation state
     * follow it there.
     */
    public const DOCUMENT_ID_EXPRESSION = "(int) apply_filters( 'blocks_engine_document_id', is_singular() ? get_queried_object_id() : 0 )";

    /**
     * Served route of each source document a navigation item records as
     * marking it current.
     *
     * @param array<int,array<string,mixed>> $documents
     * @param array<int,array<string,mixed>> $pages
     * @return array<string,string>
     */
    private static function navigationCurrentSourceRoutes(array $documents, array $pages): array
    {
        $sources = array();
        foreach ($documents as $document) {
            preg_match_all('/"blocksEngineCurrentSources":(\[[^\]]*\])/', (string) ($document['canonical_block_markup'] ?? $document['block_markup'] ?? ''), $matches);
            foreach ($matches[1] as $list) foreach ((array) json_decode($list, true) as $source) if (is_string($source)) $sources[$source] = true;
        }
        $routes = array();
        foreach ($pages as $page) {
            $source = (string) ($page['source_path'] ?? '');
            if (isset($sources[$source]) && is_string($page['route']['path'] ?? null)) $routes[$source] = '/' . trim($page['route']['path'], '/');
        }
        ksort($routes, SORT_STRING);
        return $routes;
    }

    /** @param array<string,array<string,mixed>> $routes @return list<string> */
    private static function routeStylesheetTable(array $routes): array
    {
        $sequences = $indices = $matches = array();
        foreach ($routes as $route) {
            $key = serialize($route['styles']);
            if (!isset($indices[$key])) { $indices[$key] = count($sequences); $sequences[] = $route['styles']; }
            // Handles belong to occurrences, not resources: never intern them
            // into a sequence shared by different source documents.
            $matches[] = '    if ( ' . $route['condition'] . ' ) $blocks_engine_style_routes[] = [' . $indices[$key] . ',' . BootstrapData::literal($route['handles']) . '];';
        }
        return array_merge(array(
            '    $blocks_engine_style_sequences = ' . BootstrapData::literal($sequences) . ';',
            '    $blocks_engine_style_routes = [];',
        ), $matches, array(
            '    foreach ( $blocks_engine_style_routes as [$sequence, $handles] ) {',
            '        $blocks_engine_route_styles = true;',
            '        foreach ( $blocks_engine_style_sequences[$sequence] as $position => $style ) wp_enqueue_style( $handles[$position], get_theme_file_uri( $style[0] ), array(), null, \'\' !== $style[1] ? $style[1] : \'all\' );',
            '    }',
        ));
    }

    /** @param array<int,array<string,mixed>> $assets */
    public static function functionsPhp(array $assets, array $scripts = array(), array $parts = array(), array $tokens = array(), array $templates = array(), array $pages = array(), array $menus = array(), bool $runtimeEmoji = false, array $rootDocuments = array()): string
    {
        $lines = array("<?php", self::SOURCE_TEXT_TYPOGRAPHY);
        $documents = array_merge($templates, $parts, $pages);
        $documentsWithMenus = array_merge($documents, $menus);
        if (!$runtimeEmoji) $lines[] = self::SOURCE_NATIVE_EMOJI;
        // Core only retains theme.json variations registered for the block.
        $lines[] = <<<'PHP'
add_action( 'init', static function (): void {
    $theme = wp_json_file_decode( get_theme_file_path( 'theme.json' ), array( 'associative' => true ) );
    foreach ( $theme['styles']['blocks']['core/button']['variations'] ?? array() as $slug => $look ) {
        register_block_style( 'core/button', array( 'name' => $slug, 'label' => ucwords( str_replace( '-', ' ', $slug ) ) ) );
    }
} );
PHP;
        $rootContext = DocumentRootContext::bootstrap(array_merge($pages, $rootDocuments));
        if ('' !== $rootContext) $lines[] = $rootContext;
        $fields = ListingFieldProjection::bootstrap($pages);
        if ('' !== $fields) $lines[] = $fields;
        if (self::markupContains($documents, 'blocksEngineSocialAnchor')) $lines[] = SocialLinkSourceAttribution::bootstrap();
        if (self::markupContains($documents, 'blocksEngineLinkClass')) {
            $lines[] = "add_filter( 'render_block_core/post-title', static function ( string \$content, array \$block ): string { \$classes = \$block['attrs']['metadata']['blocksEngineLinkClass'] ?? ''; if ( ! is_string( \$classes ) || '' === \$classes ) return \$content; \$tag = new WP_HTML_Tag_Processor( \$content ); if ( \$tag->next_tag( 'A' ) ) foreach ( preg_split( '/\\s+/', \$classes ) ?: array() as \$class ) \$tag->add_class( \$class ); return \$tag->get_updated_html(); }, 10, 2 );";
        }
        if (self::markupContains($documents, 'blocksEngineIcon')) {
            $lines[] = self::ACCORDION_ICON_RENDERER;
        }
        if (self::markupContains($documents, 'blocksEngineInlineSvg')) {
            $lines[] = InlineSvgImageRuntime::source();
        }
        if (self::markupContains($documents, 'blocksEngineImageSelection')) {
            $lines[] = self::IMAGE_SELECTION_RENDERER;
        }
        if (array_filter($pages, static fn(array $page): bool => str_contains($page['canonical_block_markup'], 'blocks-engine-listing-overlay'))) {
            $lines[] = "add_filter( 'render_block_core/read-more', static function ( string \$content, array \$block ): string { if ( ! in_array( 'blocks-engine-listing-overlay', preg_split( '/\\s+/', (string) ( \$block['attrs']['className'] ?? '' ) ) ?: array(), true ) ) return \$content; \$tag = new WP_HTML_Tag_Processor( \$content ); if ( \$tag->next_tag( 'A' ) ) { \$tag->set_attribute( 'aria-hidden', 'true' ); \$tag->set_attribute( 'tabindex', '-1' ); } return \$tag->get_updated_html(); }, 10, 2 );";
        }
        $lines[] = "add_action( 'wp_enqueue_scripts', static function (): void {";
        $lines[] = '    $blocks_engine_page_id = ' . self::DOCUMENT_ID_EXPRESSION . ';';
        $lines[] = "    \$blocks_engine_page_identity = \$blocks_engine_page_id ? get_post_meta( \$blocks_engine_page_id, '_blocks_engine_reconciliation_identity', true ) : '';";
        $lines[] = "    \$blocks_engine_page_uri = \$blocks_engine_page_id && '' === \$blocks_engine_page_identity && 'page' === get_post_type( \$blocks_engine_page_id ) ? trim( get_page_uri( \$blocks_engine_page_id ), '/' ) : null;";
        $importLoaded = self::importLoadedStylesheets($assets);
        $stylesheetAttributes = array();
        $stylesheetEnqueues = self::stylesheetEnqueues($assets, $pages, $rootDocuments);
        // Each source document's ordered stylesheet instances form one route
        // block; a repeated resource gets one handle per instance, same URI.
        if (array_filter($stylesheetEnqueues, static fn(array $enqueue): bool => isset($enqueue['route']))) $lines[] = '    $blocks_engine_route_styles = false;';
        $routeRows = array();
        $routePosition = null;
        foreach ($stylesheetEnqueues as $enqueue) {
            $asset = $enqueue['asset'];
            if ('editor' === ($asset['stylesheet_target'] ?? 'both') || isset($importLoaded[$asset['target_path']])) continue;
            // Stylesheet-set membership travels on every instance's link.
            if (isset($asset['stylesheet_activation'])) {
                $state = $asset['stylesheet_activation'];
                $stylesheetAttributes[$enqueue['handle']] = array('rel' => $state['rel']);
                if ('' !== $state['title']) $stylesheetAttributes[$enqueue['handle']]['title'] = $state['title'];
                if ($state['disabled']) $stylesheetAttributes[$enqueue['handle']]['disabled'] = true;
            }
            $deviceAttributes = WordPressSitePlan::stylesheetDeviceAttributes($enqueue);
            if (isset($deviceAttributes[CaptureEvidence::DEVICE_STYLE])) $stylesheetAttributes[$enqueue['handle']] = ($stylesheetAttributes[$enqueue['handle']] ?? array()) + $deviceAttributes;
            $media = '' !== $enqueue['media'] ? ', ' . var_export($enqueue['media'], true) : '';
            $call = "wp_enqueue_style( " . var_export($enqueue['handle'], true) . ", get_theme_file_uri( " . var_export(self::encodedAssetUrlPath($asset['target_path']), true) . " ), array(), null{$media} );";
            if (isset($enqueue['route'])) {
                if (null === $routePosition) $routePosition = count($lines);
                $routeRows[$enqueue['route']]['condition'] = $enqueue['condition'];
                $routeRows[$enqueue['route']]['styles'][] = array(self::encodedAssetUrlPath($asset['target_path']), $enqueue['media'], $stylesheetAttributes[$enqueue['handle']] ?? array());
                $routeRows[$enqueue['route']]['handles'][] = $enqueue['handle'];
                continue;
            }
            $condition = empty($enqueue['fallback']) ? $enqueue['condition'] : '! $blocks_engine_route_styles';
            $lines[] = "    if ( {$condition} ) {$call}";
        }
        if (null !== $routePosition) array_splice($lines, $routePosition, 0, self::routeStylesheetTable($routeRows));
        $attributes = array();
        foreach ($scripts as $script) {
            $handle = 'blocks-engine-script-' . substr(hash('sha256', $script['identity']), 0, 12);
            $source = null !== $script['local_target'] ? "get_theme_file_uri( " . var_export(self::encodedAssetUrlPath($script['local_target']), true) . " ) . " . var_export($script['suffix'], true) : var_export($script['url'], true);
            $args = array('in_footer' => 'body' === $script['placement']);
            if ($script['async'] && !$script['module']) $args['strategy'] = 'async';
            if ($script['defer'] && !$script['async'] && !$script['module']) $args['strategy'] = 'defer';
            $lines[] = "    wp_register_script( " . var_export($handle, true) . ", {$source}, array(), null, " . var_export($args, true) . " );";
            $attributes[$handle] = array_filter(array('type' => $script['type'], 'nomodule' => $script['nomodule'], 'integrity' => $script['integrity'], 'crossorigin' => $script['crossorigin'], 'referrerpolicy' => $script['referrerpolicy'], 'fetchpriority' => $script['fetchpriority'], 'async' => $script['async'] && $script['module'], 'defer' => $script['defer'] && ($script['async'] || $script['module'])), static fn(mixed $value): bool => false !== $value && null !== $value);
        }
        $lines[] = "}, 1 );";
        $stylesheetAttributes = array_filter($stylesheetAttributes, static fn(array $state): bool => array('rel' => 'stylesheet') !== $state);
        if (array() !== $stylesheetAttributes) {
            $bodies = array_values(array_unique($stylesheetAttributes, SORT_REGULAR));
            $indices = array_map(static fn(array $state): int => array_search($state, $bodies, true), $stylesheetAttributes);
            $lines[] = '$blocks_engine_stylesheet_attribute_bodies = ' . var_export($bodies, true) . ';';
            $lines[] = '$blocks_engine_stylesheet_attributes = ' . var_export($indices, true) . ';';
            $lines[] = "add_filter( 'style_loader_tag', static function ( string \$html, string \$handle ) use ( \$blocks_engine_stylesheet_attributes, \$blocks_engine_stylesheet_attribute_bodies ): string {";
            $lines[] = "    \$index = \$blocks_engine_stylesheet_attributes[ \$handle ] ?? null; if ( null === \$index ) return \$html; \$state = \$blocks_engine_stylesheet_attribute_bodies[ \$index ]; \$tag = new WP_HTML_Tag_Processor( \$html ); if ( \$tag->next_tag( 'LINK' ) ) { foreach ( \$state as \$attribute => \$value ) \$tag->set_attribute( \$attribute, \$value ); } return \$tag->get_updated_html();";
            $lines[] = "}, 10, 2 );";
            // Concatenating hosts print one combined link per media group and
            // skip style_loader_tag, which would drop the attributes above:
            // device markers, stylesheet-set titles and disabled states.
            $lines[] = "add_filter( 'css_do_concat', static function ( \$do_concat, \$handle ) use ( \$blocks_engine_stylesheet_attributes ) { return isset( \$blocks_engine_stylesheet_attributes[ \$handle ] ) ? false : \$do_concat; }, 10, 2 );";
        }
        // Pages count too: a consumer may publish a theme template from a page's
        // canonical markup (a store's product archive keeps its asset tokens),
        // and only this resolver turns those tokens into theme URLs on render.
        // Map only the tokens those documents reference: every entry ships in
        // functions.php and keeps its theme file reachable.
        $referenced = array();
        foreach ($documents as $document) if (preg_match_all('/' . preg_quote(WordPressSitePlan::TOKEN_PREFIX, '/') . '([^}]+)\}\}/', (string) ($document['canonical_block_markup'] ?? ''), $matches)) $referenced += array_fill_keys($matches[1], true);
        $templateAssetTokens = array();
        foreach ($tokens as $token) if (isset($referenced[$token['token'] ?? null]) && is_string($token['target_path'] ?? null)) $templateAssetTokens[WordPressSitePlan::TOKEN_PREFIX . $token['token'] . '}}'] = $token['target_path'];
        if (array() !== $templateAssetTokens) {
            $lines[] = '$blocks_engine_template_asset_tokens = ' . var_export($templateAssetTokens, true) . ';';
            $lines[] = '$blocks_engine_template_attachment_ids = array();';
            $lines[] = '$blocks_engine_resolve_template_assets = static function ( string $content ) use ( $blocks_engine_template_asset_tokens, $blocks_engine_template_attachment_ids ): string {';
            // A file-system path and a URL path are different encodings of the
            // same name: a literal `%` (or a `+`, which some serving layers
            // decode to a space) in a captured file name resolves on some hosts
            // and 404s on others once the server URL-decodes the request path.
            // Percent-encode every segment in the emitted URL only, leaving the
            // on-disk path -- and therefore the theme-file lookup -- untouched.
            $lines[] = "    \$references = array(); foreach ( \$blocks_engine_template_asset_tokens as \$token => \$path ) { if ( isset( \$blocks_engine_template_attachment_ids[ \$token ] ) ) { \$references[ \$token ] = wp_get_attachment_url( \$blocks_engine_template_attachment_ids[ \$token ] ) ?: ''; continue; } \$uri = get_theme_file_uri( \$path ); \$references[ \$token ] = str_ends_with( \$uri, \$path ) ? substr( \$uri, 0, -strlen( \$path ) ) . implode( '/', array_map( 'rawurlencode', explode( '/', \$path ) ) ) : \$uri; }";
            $lines[] = '    return strtr( $content, $references );';
            $lines[] = '};';
            $lines[] = "add_filter( 'get_block_file_template', static function ( \$template, string \$id, string \$type ) use ( \$blocks_engine_resolve_template_assets ) { if ( \$template instanceof WP_Block_Template && \$template->has_theme_file && get_stylesheet() === \$template->theme ) \$template->content = \$blocks_engine_resolve_template_assets( \$template->content ); return \$template; }, 10, 3 );";
            $lines[] = "add_filter( 'get_block_templates', static function ( array \$templates ) use ( \$blocks_engine_resolve_template_assets ): array { foreach ( \$templates as \$template ) if ( \$template instanceof WP_Block_Template && 'theme' === \$template->source && get_stylesheet() === \$template->theme ) \$template->content = \$blocks_engine_resolve_template_assets( \$template->content ); return \$templates; }, 10, 1 );";
            $lines[] = "add_filter( 'render_block_core/template-part', static function ( string \$content ) use ( \$blocks_engine_resolve_template_assets ): string { return \$blocks_engine_resolve_template_assets( \$content ); }, 10, 1 );";
        }
        $editorStyles = array();
        $partSlugsBySource = array();
        foreach ($parts as $part) {
            $sourcePaths = is_array($part['placement']['source_paths'] ?? null) ? $part['placement']['source_paths'] : array((string) ($part['placement']['source_path'] ?? preg_replace('/#.*$/', '', (string) ($part['source_path'] ?? ''))));
            foreach ($sourcePaths as $sourcePath) if (is_string($sourcePath) && '' !== $sourcePath && '' !== (string) ($part['slug'] ?? '')) $partSlugsBySource[$sourcePath][] = (string) $part['slug'];
        }
        foreach ($stylesheetEnqueues as $enqueue) {
            $asset = $enqueue['asset'];
            if (!StylesheetActivation::active($asset) || 'frontend' === ($asset['stylesheet_target'] ?? 'both') || isset($importLoaded[$asset['target_path']])) continue;
            $partSlugs = array();
            foreach ($enqueue['scopes'] ?? array($enqueue['scope']) as $owner) foreach ($partSlugsBySource[(string) ($owner['source_path'] ?? '')] ?? array() as $slug) $partSlugs[$slug] = true;
            foreach ($enqueue['scopes'] ?? array($enqueue['scope']) as $editorScope) $editorStyles[] = array_filter(array('target_path' => $asset['target_path'], 'handle' => $enqueue['handle'], 'content_hash' => $asset['content_hash'], 'scopes' => array($editorScope), 'template_part_slugs' => array_keys($partSlugs), 'media' => $asset['source_media'] ?? self::editorStylesheetMedia($enqueue), 'author_css' => 'engine-support' !== ($asset['source'] ?? ''), 'editor_only' => 'editor' === ($asset['stylesheet_target'] ?? 'both'), 'fallback' => empty($enqueue['fallback']) ? null : true), static fn(mixed $value): bool => null !== $value);
        }
        if (array() !== $editorStyles) {
            // Files and scopes own shared data; replay owns occurrence order,
            // handles, media and fallback semantics (including repeated links).
            $files = $fileIndices = $scopes = $scopeIndices = $replay = array();
            foreach ($editorStyles as $style) {
                $path = $style['target_path'];
                if (!isset($fileIndices[$path])) {
                    $fileIndices[$path] = count($files);
                    $files[] = array_diff_key($style, array_flip(array('handle', 'scopes', 'template_part_slugs', 'media', 'fallback'))) + array('scopes' => array(), 'template_part_slugs' => array());
                }
                $file = $fileIndices[$path];
                $scope = $style['scopes'][0];
                $key = serialize($scope);
                if (!isset($scopeIndices[$key])) { $scopeIndices[$key] = count($scopes); $scopes[] = $scope; }
                $scopeIndex = $scopeIndices[$key];
                $files[$file]['scopes'][$scopeIndex] = $scopeIndex;
                $files[$file]['template_part_slugs'] = array_values(array_unique(array_merge($files[$file]['template_part_slugs'], $style['template_part_slugs'])));
                $entry = array($file, $scopeIndex, $style['handle'], $style['media'], !empty($style['fallback']), $style['template_part_slugs']);
                foreach (array(5 => array(), 4 => false, 3 => '') as $field => $default) {
                    if ($entry[$field] !== $default) break;
                    array_pop($entry);
                }
                $replay[] = $entry;
            }
            foreach ($files as &$file) $file['scopes'] = array_values($file['scopes']);
            unset($file);
            $lines[] = BootstrapData::table('blocks_engine_presentation_scopes', $scopes);
            $lines[] = BootstrapData::table('blocks_engine_presentation_styles', $files);
            $lines[] = '$blocks_engine_presentation_replay = ' . BootstrapData::literal($replay) . ';';
            $lines[] = '$blocks_engine_presentation_sequence = array();';
            $lines[] = 'foreach ( $blocks_engine_presentation_replay as $entry ) { $style = $blocks_engine_presentation_styles[ $entry[0] ]; $style[\'scopes\'] = array( $blocks_engine_presentation_scopes[ $entry[1] ] ); $style[\'handle\'] = $entry[2]; $style[\'media\'] = $entry[3] ?? \'\'; $style[\'fallback\'] = $entry[4] ?? false; $style[\'template_part_slugs\'] = $entry[5] ?? array(); $blocks_engine_presentation_sequence[] = $style; }';
            $lines[] = "\$blocks_engine_presentation_matches = static function ( array \$style, ?WP_Post \$post, bool \$site_editor ): bool {";
            $lines[] = "    \$matches = \$site_editor; if ( ! \$matches && \$post instanceof WP_Post ) foreach ( \$style['scopes'] as \$scope ) {";
            $lines[] = "        if ( 'wp_template_part' === \$post->post_type && in_array( basename( (string) \$post->post_name ), \$style['template_part_slugs'], true ) ) { \$matches = true; break; }";
            $lines[] = "        if ( 'global' === \$scope['kind'] ) { \$matches = true; break; }";
            $lines[] = "        if ( 'post' === \$scope['kind'] && 'post' === \$post->post_type && \$scope['reconciliation_identity'] === get_post_meta( \$post->ID, '_blocks_engine_reconciliation_identity', true ) ) { \$matches = true; break; }";
            $lines[] = "        if ( 'page' === \$scope['kind'] ) { \$identity = get_post_meta( \$post->ID, '_blocks_engine_reconciliation_identity', true ); if ( '' !== \$identity ? \$scope['reconciliation_identity'] === \$identity : 'page' === \$post->post_type && ( ( \$scope['front_page'] && (int) get_option( 'page_on_front' ) === (int) \$post->ID ) || \$scope['route_path'] === trim( get_page_uri( \$post ), '/' ) ) ) { \$matches = true; break; } }";
            $lines[] = "    }";
            $lines[] = "    return \$matches;";
            $lines[] = "};";
            // Core collects the editor canvas iframe's stylesheets by firing
            // enqueue_block_assets with should_load_block_editor_scripts_and_styles
            // forced false (_wp_get_iframed_editor_assets()); the iframe then
            // loads them by URL. Authored and editor-only CSS is enqueued only on
            // that pass, so it never styles the outer admin document and is never
            // inlined into the editor settings payload.
            $lines[] = "add_action( 'enqueue_block_assets', static function () use ( \$blocks_engine_presentation_sequence, \$blocks_engine_presentation_matches ): void {";
            $lines[] = "    \$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null; \$site_editor = \$screen instanceof WP_Screen && 'site-editor' === \$screen->base; if ( ! \$site_editor && ( ! \$screen instanceof WP_Screen || ! in_array( \$screen->base, array( 'post', 'post-new' ), true ) ) ) return; \$post = \$GLOBALS['post'] ?? null;";
            $lines[] = "    \$canvas = ! wp_should_load_block_editor_scripts_and_styles();";
            // One document's canvas replays its ordered instances. The site
            // editor and shared template parts span routes, so they load each
            // resource/condition once instead of once per source document. Like
            // the front end, global resources outside a route's sequence (a
            // post no source document owns) load from their fallback entry.
            $lines[] = "    \$route_canvas = ! \$site_editor && \$post instanceof WP_Post && 'wp_template_part' !== \$post->post_type; \$loaded = array(); \$routed = false;";
            $lines[] = "    if ( \$route_canvas ) foreach ( \$blocks_engine_presentation_sequence as \$style ) if ( empty( \$style['fallback'] ) && 'global' !== \$style['scopes'][0]['kind'] && \$blocks_engine_presentation_matches( \$style, \$post, false ) ) { \$routed = true; break; }";
            $lines[] = "    foreach ( \$blocks_engine_presentation_sequence as \$style ) if ( ( empty( \$style['fallback'] ) || ( \$route_canvas && ! \$routed ) ) && ( \$canvas || ( empty( \$style['author_css'] ) && empty( \$style['editor_only'] ) ) ) && \$blocks_engine_presentation_matches( \$style, \$post instanceof WP_Post ? \$post : null, \$site_editor ) ) {";
            $lines[] = "        \$resource = \$style['target_path'] . \"\\0\" . ( \$style['media'] ?? 'all' ); if ( ! \$route_canvas && isset( \$loaded[ \$resource ] ) ) continue; \$loaded[ \$resource ] = true;";
            $lines[] = "        wp_enqueue_style( 'blocks-engine-editor-' . \$style['handle'], get_theme_file_uri( \$style['target_path'] ), array(), \$style['content_hash'], \$style['media'] ?? 'all' );";
            $lines[] = "    }";
            $lines[] = "} );";
        }
        // Keep the source landmark intact. The marked Core transport contributes
        // no layout box on the frontend; the editor retains its selection box.
        // Synthetic landmarks likewise must not replace source flex/grid items.
        // Authored landmark boxes remain intact.
        if (array() !== $parts) {
            $transportCss = ':where(body:not(.editor-styles-wrapper):not(.wp-admin)) :where(.blocks-engine-shell-transport){display:contents}:where(body:not(.editor-styles-wrapper):not(.wp-admin)) :where(.blocks-engine-shell-landmark){display:contents}';
            $lines[] = "add_action( 'wp_enqueue_scripts', static function (): void { wp_register_style( 'blocks-engine-shell-transport', false, array(), null ); wp_enqueue_style( 'blocks-engine-shell-transport' ); wp_add_inline_style( 'blocks-engine-shell-transport', " . var_export($transportCss, true) . " ); } );";
        }
        // A page's layout frame sits in its template around post-content. Core's
        // post-content element would be an extra box in that layout, so the
        // marked block renders only its content.
        foreach ($templates as $template) {
            if (!str_contains((string) ($template['canonical_block_markup'] ?? ''), WordPressSitePlan::FRAME_CONTENT_CLASS)) continue;
            $lines[] = "add_filter( 'render_block_core/post-content', static function ( string \$content, array \$block ): string {";
            $lines[] = "    if ( ! str_contains( (string) ( \$block['attrs']['className'] ?? '' ), '" . WordPressSitePlan::FRAME_CONTENT_CLASS . "' ) || ! preg_match( '/^<([a-z][a-z0-9-]*)\\b[^>]*>(.*)<\\/\\1>\\s*\$/s', \$content, \$match ) ) return \$content;";
            $lines[] = "    return \$match[2];";
            $lines[] = "}, 10, 2 );";
            break;
        }
        if (self::markupContains($documentsWithMenus, 'data-blocks-engine-route-state')) {
            $lines[] = NavigationAnchorRuntime::retainedLabelSource();
        }
        if (self::markupContains($documents, 'blocksEngineWordmark')) {
            // Resolve before Core renders site-title, retaining one editable part
            // and Core's live site name/home URL rather than rewriting saved HTML.
            $lines[] = "add_filter( 'render_block_data', static function ( array \$block ): array {";
            $lines[] = "    if ( 'core/site-title' === ( \$block['blockName'] ?? null ) && ! empty( \$block['attrs']['metadata']['blocksEngineWordmark'] ) ) \$block['attrs']['level'] = ! empty( \$block['attrs']['metadata']['blocksEngineWordmarkHeading'] ) && is_front_page() ? 1 : 0;";
            $lines[] = "    return \$block;";
            $lines[] = "}, 10, 1 );";
        }
        if (self::markupContains($documents, 'blocksEngineNavigationContainer')) {
            $lines[] = NavigationContainerRuntime::source();
        }
        $hasNavigationLink = false;
        if (self::markupContains($documents, 'blocksEngineNavigationOpener')) {
            $lines[] = NavigationOpenerRuntime::source();
        }
        foreach ($documentsWithMenus as $document) {
            $markup = (string) ($document['canonical_block_markup'] ?? $document['block_markup'] ?? '');
            // Require the navigation container alongside a link, not merely the
            // link comment text, so the filter is only emitted where core/navigation
            // actually renders a native navigation item: bare block-name
            // substrings can appear in unrelated JSON/attribute contexts that never
            // reach the core navigation renderers. Entity inner markup is
            // the link list for a referencing navigation block.
            if ((str_contains($markup, 'wp:navigation-link') || str_contains($markup, 'wp:navigation-submenu')) && (str_contains($markup, 'wp:navigation ') || isset($document['token']))) { $hasNavigationLink = true; break; }
        }
        if ($hasNavigationLink) {
            // Core's navigation-link and navigation-submenu renderers only add
            // aria-current="page" when their own
            // id/kind match the queried object, which never happens for the "custom"
            // kind links this engine emits. When navigation is inlined per page, the
            // current item is already marked with the blocks-engine-current-navigation-item
            // class (a respected className block support). That marker cannot work once
            // navigation is hoisted into a shared template part, because one rendered
            // part serves every route, so also recover aria-current by comparing the
            // link's own canonical route URL against the page currently being served.
            // Either signal recovers the semantic and the source's/engine's own
            // [aria-current] styling hook at render time.
            // Both native item blocks use the same URL resolution. A submenu's
            // descendants can already be current; inspect only its own first anchor.
            // Where the source marked an item current on routes its URL does not
            // name (a URL-less dropdown parent), the item records those source
            // documents and that evidence decides; URL matching is the fallback.
            $currentSourceRoutes = self::navigationCurrentSourceRoutes($documentsWithMenus, $pages);
            foreach (array('navigation-link', 'navigation-submenu') as $navigationBlock) {
            $lines[] = "add_filter( 'render_block_core/{$navigationBlock}', static function ( string \$content, array \$block ): string {";
            $lines[] = "    if ( ! preg_match( '/<(a|button)\\b[^>]*\\bclass=\"[^\"]*\\bwp-block-navigation-item__content\\b[^>]*>/', \$content, \$anchor ) || str_contains( \$anchor[0], 'aria-current' ) ) return \$content;";
            $lines[] = "    \$current = str_contains( (string) ( \$block['attrs']['className'] ?? '' ), 'blocks-engine-current-navigation-item' );";
            $lines[] = "    \$sources = \$block['attrs']['metadata']['blocksEngineCurrentSources'] ?? null;";
            $lines[] = "    \$url = is_string( \$block['attrs']['url'] ?? null ) ? trim( (string) \$block['attrs']['url'] ) : '';";
            $lines[] = "    if ( ! \$current && ( is_array( \$sources ) || ( '' !== \$url && '/' === \$url[0] && ( 1 === strlen( \$url ) || '/' !== \$url[1] ) ) ) ) {";
            $lines[] = "        \$document = " . self::DOCUMENT_ID_EXPRESSION . ";";
            $lines[] = "        \$served = is_front_page() ? '/' : ( 0 !== \$document && 'page' === get_post_type( \$document ) ? '/' . trim( (string) get_page_uri( \$document ), '/' ) : null );";
            $lines[] = "        \$served = apply_filters( 'blocks_engine_served_route', \$served, \$document );";
            $lines[] = "        \$routes = array( " . implode(', ', array_map(static fn(string $source, string $route): string => var_export($source, true) . ' => ' . var_export($route, true), array_keys($currentSourceRoutes), $currentSourceRoutes)) . " );";
            $lines[] = "        \$current = null !== \$served && ( is_array( \$sources ) ? in_array( \$served, array_map( static fn ( \$source ) => is_string( \$source ) ? ( \$routes[ \$source ] ?? null ) : null, \$sources ), true ) : '/' . trim( (string) parse_url( \$url, PHP_URL_PATH ), '/' ) === \$served );";
            $lines[] = "    }";
            if ('navigation-submenu' === $navigationBlock) {
                // A section parent is current while its submenu displays the
                // served item; an item the submenu keeps hidden is shown elsewhere.
                $lines[] = '    if ( ! $current && ! empty( $block[\'attrs\'][\'metadata\'][\'blocksEngineCurrentSection\'] ) && false !== ( $menu = strpos( $content, \'<ul\' ) ) && preg_match_all( \'/<li\\b([^>]*)>(?:(?!<li\\b).)*?<a\\b[^>]*\\baria-current="page"/s\', substr( $content, $menu ), $items ) ) {';
                $lines[] = '        foreach ( $items[1] as $item ) $current = $current || ! preg_match( \'/\\bstyle="[^"]*\\bdisplay\\s*:\\s*none\\b/i\', $item );';
                $lines[] = '    }';
            }
            $lines[] = "    if ( ! \$current ) return \$content;";
            if ('navigation-submenu' === $navigationBlock) {
                // Only the outer item is current, never a descendant or ancestor.
                $lines[] = "    \$content = preg_replace_callback( '/^<li\\b[^>]*\\bclass=\"([^\"]*)\"/', static function ( array \$match ): string { return in_array( 'current-menu-item', preg_split( '/\\s+/', \$match[1] ), true ) ? \$match[0] : substr( \$match[0], 0, -1 ) . ' current-menu-item\"'; }, \$content, 1 ) ?? \$content;";
                // A URL-less toggle is the current section, not a link to the page.
                $lines[] = "    if ( 'a' !== \$anchor[1] || '' === \$url || '#' === \$url[0] ) return \$content;";
            }
            $lines[] = "    return preg_replace( '/(<a\\b[^>]*\\bclass=\"[^\"]*\\bwp-block-navigation-item__content\\b[^\"]*\")/', '\$1 aria-current=\"page\"', \$content, 1 ) ?? \$content;";
            $lines[] = "}, 10, 2 );";
            }
            // Resolve ancestry only after every item has had its exact/source
            // evidence pass. At the container boundary we can choose one longest
            // segment prefix, independent of item render order or submenu depth.
            $lines[] = "add_filter( 'render_block_core/navigation', static function ( string \$content ): string {";
            $lines[] = "    if ( preg_match( '/\\baria-current=[\"\\\x27]page[\"\\\x27]|\\bcurrent-menu-item\\b|\\bblocks-engine-current-navigation-item\\b/', \$content ) ) return \$content;";
            $lines[] = "    \$document = " . self::DOCUMENT_ID_EXPRESSION . ";";
            $lines[] = "    \$served = is_front_page() ? '/' : ( 0 !== \$document && 'page' === get_post_type( \$document ) ? '/' . trim( (string) get_page_uri( \$document ), '/' ) : null );";
            $lines[] = "    \$served = apply_filters( 'blocks_engine_served_route', \$served, \$document );";
            $lines[] = "    if ( ! is_string( \$served ) || ! str_starts_with( \$served, '/' ) ) return \$content;";
            $lines[] = "    \$best = null; \$length = 0;";
            $lines[] = '    preg_match_all( \'/<li\\b[^>]*>(?:(?!<li\\b|<a\\b|<button\\b).)*<a\\b[^>]*>/s\', $content, $items, PREG_OFFSET_CAPTURE );';
            $lines[] = '    foreach ( $items[0] as $item ) {';
            $lines[] = '        if ( ! preg_match( \'/\\bhref="([^"]*)"/\', $item[0], $href ) || ! preg_match( \'/\\bclass="[^"]*\\bwp-block-navigation-item__content\\b/\', $item[0] ) ) continue;';
            $lines[] = '        $url = html_entity_decode( $href[1], ENT_QUOTES, \'UTF-8\' );';
            $lines[] = '        if ( ! str_starts_with( $url, \'/\' ) || str_starts_with( $url, \'//\' ) ) continue;';
            $lines[] = '        $path = \'/\' . trim( (string) parse_url( $url, PHP_URL_PATH ), \'/\' );';
            $lines[] = '        if ( \'/\' !== $path && strlen( $path ) > $length && str_starts_with( $served, $path . \'/\' ) ) { $best = $item; $length = strlen( $path ); }';
            $lines[] = '    }';
            $lines[] = '    if ( null === $best ) return $content;';
            $lines[] = '    $item = preg_replace( \'/<a\\b/\', \'<a aria-current="page"\', $best[0], 1 );';
            $lines[] = '    $item = preg_replace_callback( \'/^<li\\b[^>]*>/\', static function ( array $match ): string { return str_contains( $match[0], \'class="\' ) ? preg_replace( \'/class="/\', \'class="current-menu-ancestor \', $match[0], 1 ) : substr( $match[0], 0, -1 ) . \' class="current-menu-ancestor">\'; }, $item, 1 );';
            $lines[] = '    return substr_replace( $content, $item, $best[1], strlen( $best[0] ) );';
            $lines[] = "}, 10, 1 );";
        }
        if (self::markupContains($documents, CaptureEvidence::VIEWPORT_ENTRANCE)) {
            // Device-scoped entrances resolve through the site's live core
            // viewport queries, so they follow block visibility breakpoints.
            $entranceScript = \Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Generators\ViewportEntranceRuntime::script('__BLOCKS_ENGINE_VIEWPORT_QUERIES__');
            $lines[] = "add_action( 'wp_enqueue_scripts', static function (): void { \$queries = array(); if ( method_exists( 'WP_Theme_JSON', 'get_viewport_media_queries' ) ) { foreach ( WP_Theme_JSON::get_viewport_media_queries( wp_get_global_settings( array( 'viewport' ) ), array( 'include_desktop' => true ) ) as \$state => \$query ) { \$queries[ ltrim( \$state, '@' ) ] = preg_replace( '/^@media\\s+/', '', \$query ); } } wp_register_script( 'blocks-engine-viewport-entrances', false, array(), null, true ); wp_enqueue_script( 'blocks-engine-viewport-entrances' ); wp_add_inline_script( 'blocks-engine-viewport-entrances', str_replace( '__BLOCKS_ENGINE_VIEWPORT_QUERIES__', (string) wp_json_encode( (object) \$queries ), " . var_export($entranceScript, true) . " ) ); } );";
        }
        if (self::markupContains($documents, 'blocksEngineDialog')) {
            $lines[] = CapturedDialogRuntime::bootstrap();
        }
        if (self::markupContains($documents, CaptureEvidence::RESPONSIVE_FRAGMENT)) {
            $fragmentScript = \Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Generators\ResponsiveFragmentRuntime::script();
            $lines[] = "add_action( 'wp_enqueue_scripts', static function (): void { wp_register_script( 'blocks-engine-responsive-fragments', false, array(), null, true ); wp_enqueue_script( 'blocks-engine-responsive-fragments' ); wp_add_inline_script( 'blocks-engine-responsive-fragments', " . var_export($fragmentScript, true) . " ); } );";
        }
        if (self::markupContains($documents, 'blocks-engine-navigation-header-offset')) {
            $headerOffsetScript = NavigationHeaderOffsetRuntime::script();
            $lines[] = "add_action( 'wp_enqueue_scripts', static function (): void { wp_register_script( 'blocks-engine-navigation-header-offset', false, array(), null, true ); wp_enqueue_script( 'blocks-engine-navigation-header-offset' ); wp_add_inline_script( 'blocks-engine-navigation-header-offset', " . var_export($headerOffsetScript, true) . " ); } );";
        }
        $breadcrumbLabels = BreadcrumbLabelRuntime::labels($documents, $pages);
        $breadcrumbPostTrails = BreadcrumbLabelRuntime::postTrails($pages);
        if (array() !== $breadcrumbLabels || array() !== $breadcrumbPostTrails) $lines[] = BreadcrumbLabelRuntime::source($breadcrumbLabels, $breadcrumbPostTrails);
        if (self::markupContains($documentsWithMenus, 'blocksEngineRuntimeAttributes')) {
            $lines[] = RuntimeAttributesRuntime::source();
        }
        if (self::markupContains($documents, NavigationEntityProjection::ITEMS_KEY)) {
            $lines[] = NavigationReferenceItemsRuntime::source();
        }
        if (self::markupContains($documentsWithMenus, 'blocksEngineNavigationAnchor')) {
            $lines[] = NavigationAnchorRuntime::source();
        }
        // Gutenberg pads every saved block with newlines. Imported presentation can
        // preserve white-space (Wix rich text uses break-spaces), so that padding
        // renders as blank lines once a page is saved. Drop serializer newline runs
        // at block edges and between inner blocks; text inside blocks is untouched.
        $lines[] = "add_filter( 'render_block_data', static function ( array \$block ): array {";
        $lines[] = "    \$content = \$block['innerContent'] ?? null; if ( ! is_array( \$content ) ) return \$block;";
        $lines[] = "    if ( null === ( \$block['blockName'] ?? null ) ) { if ( '' === trim( (string) ( \$block['innerHTML'] ?? '' ) ) ) { \$block['innerHTML'] = ''; \$block['innerContent'] = array(); } return \$block; }";
        $lines[] = "    \$last = count( \$content ) - 1;";
        $lines[] = "    foreach ( \$content as \$index => \$chunk ) {";
        $lines[] = "        if ( ! is_string( \$chunk ) ) continue;";
        $lines[] = "        if ( 0 === \$index || null === \$content[ \$index - 1 ] ) \$chunk = preg_replace( '/^\\s*\\n[ \\t\\r\\f]*/', '', \$chunk ) ?? \$chunk;";
        $lines[] = "        if ( \$last === \$index || null === \$content[ \$index + 1 ] ) \$chunk = preg_replace( '/[ \\t\\r\\f]*\\n\\s*\$/', '', \$chunk ) ?? \$chunk;";
        $lines[] = "        \$content[ \$index ] = \$chunk;";
        $lines[] = "    }";
        $lines[] = "    \$block['innerContent'] = \$content; return \$block;";
        $lines[] = "}, 10, 1 );";
        $hasListingQuery = false;
        foreach ($pages as $page) {
            if (str_contains((string) ($page['canonical_block_markup'] ?? ''), WordPressSitePlan::LISTING_QUERY_CLASS)) {
                $hasListingQuery = true;
                break;
            }
        }
        $editorCss = self::EDITOR_CORE_IMAGE_INTERACTION_CSS . self::EDITOR_POST_TITLE_INTERACTION_CSS . self::EDITOR_LINK_INTERACTION_CSS;
        foreach ($pages as $page) if (str_contains((string) ($page['canonical_block_markup'] ?? ''), WordPressSitePlan::SYNCED_PATTERN_TOKEN_PREFIX)) {
            $editorCss .= SyncedPatternProjection::EDITOR_CONTAINER_CSS;
            break;
        }
        if ($hasListingQuery) {
            // Gutenberg's query-template editor can expose a core/post-meta key
            // before its post context resolves; the frontend binding remains live.
            $editorCss .= self::LISTING_QUERY_CSS
                . ':root .editor-styles-wrapper .blocks-engine-listing-overlay{display:none}'
                . ':root .editor-styles-wrapper .blocks-engine-listing-bound-meta{display:none}';
            $lines[] = "add_action( 'wp_enqueue_scripts', static function (): void { wp_register_style( 'blocks-engine-listing-query', false, array(), null ); wp_enqueue_style( 'blocks-engine-listing-query' ); wp_add_inline_style( 'blocks-engine-listing-query', " . var_export(self::LISTING_QUERY_CSS, true) . " ); } );";
        }
        $lines[] = "add_filter( 'block_editor_settings_all', static function ( array \$settings ): array { \$settings['styles'][] = array( 'css' => " . var_export($editorCss, true) . ", '__unstableType' => 'theme' ); return \$settings; }, 20 );";
        foreach ($scripts as $script) {
            $handle = 'blocks-engine-script-' . substr(hash('sha256', $script['identity']), 0, 12);
            foreach ($script['scopes'] as $scope) {
                $condition = self::bootstrapScopeCondition($scope);
                $lines[] = "add_action( 'wp_enqueue_scripts', static function (): void { if ( {$condition} ) wp_enqueue_script( " . var_export($handle, true) . " ); }, " . (10 + $scope['order']) . " );";
            }
        }
        if (array() !== $attributes) {
            $lines[] = "add_filter( 'script_loader_tag', static function ( string \$tag, string \$handle ): string {";
            $lines[] = '    $attributes = ' . var_export($attributes, true) . ';';
            $lines[] = "    if ( ! isset( \$attributes[\$handle] ) ) return \$tag;";
            $lines[] = "    \$rendered = ''; foreach ( \$attributes[\$handle] as \$name => \$value ) \$rendered .= true === \$value ? ' ' . \$name : ' ' . \$name . '=\"' . esc_attr( (string) \$value ) . '\"';";
            $lines[] = "    return preg_replace( '/<script\\b/', '<script' . \$rendered, \$tag, 1 ) ?? \$tag;";
            $lines[] = "}, 10, 2 );";
        }
        return implode("\n", $lines) . "\n";
    }

    /** @param list<array<string,mixed>> $documents */
    private static function markupContains(array $documents, string $marker): bool
    {
        foreach ($documents as $document) if (str_contains((string) ($document['canonical_block_markup'] ?? $document['block_markup'] ?? ''), $marker)) return true;
        return false;
    }

    private static function encodedAssetUrlPath(string $path): string { return implode('/', array_map('rawurlencode', explode('/', $path))); }

    /**
     * The editor canvas runs no device-selection script, so it activates the
     * default (desktop) device's sheets at their source media itself; other
     * devices' sheets keep their inert captured media.
     */
    private static function editorStylesheetMedia(array $enqueue): string
    {
        $device = WordPressSitePlan::stylesheetDeviceAttributes($enqueue);
        if (!isset($device[CaptureEvidence::DEVICE_STYLE], $device[CaptureEvidence::SOURCE_MEDIA]) || !in_array('desktop', preg_split('/\s+/', trim((string) $device[CaptureEvidence::DEVICE_STYLE])) ?: array(), true)) return (string) $enqueue['media'];
        return (string) $device[CaptureEvidence::SOURCE_MEDIA];
    }

    /**
     * Resource identity owns bytes; a document occurrence owns an enqueue handle.
     * Keep generated support on its existing side of the authored sequence and
     * join projected slices at their source occurrence, including repeated links.
     *
     * @param array<int,array<string,mixed>> $assets
     * @param array<int,array<string,mixed>> $pages
     * @param array<int,array<string,mixed>> $rootDocuments Captured documents omitted from pages.
     * @return array<int,array<string,mixed>>
     */
    private static function stylesheetEnqueues(array $assets, array $pages, array $rootDocuments = array()): array
    {
        $documentsBySource = WordPressSitePlan::assetOwnerDocuments($pages, $rootDocuments);
        // Replay owners are delivery metadata, not empty stylesheet resources.
        $deliveryAssets = array();
        foreach ($assets as $asset) {
            if (!isset($asset['stylesheet_replays'])) { $deliveryAssets[] = $asset; continue; }
            foreach ($asset['stylesheet_replays'] as $replay) $deliveryAssets[] = array_replace($asset, $replay);
            $resource = $asset;
            unset($resource['stylesheet_replays']);
            $deliveryAssets[] = $resource;
        }
        $assets = $deliveryAssets;
        $assetsBySource = array_column($assets, null, 'source_path');
        $before = $after = $instances = array();
        $firstManaged = count($assets);
        foreach ($assets as $index => $asset) if (!empty($asset['stylesheet_instances'])) $firstManaged = min($firstManaged, $index);
        $partTargets = array();
        foreach ($assets as $asset) foreach ($asset['stylesheet_parts'] ?? array() as $path) $partTargets[$path] = true;
        foreach ($assets as $index => $asset) {
            if ('css' !== ($asset['kind'] ?? null) || isset($partTargets[$asset['target_path']])) continue;
            $occurrences = $asset['stylesheet_instances'] ?? array();
            $parent = $asset['stylesheet_parent'] ?? null;
            if (array() === $occurrences && is_string($parent)) $occurrences = $assetsBySource[$parent]['stylesheet_instances'] ?? array();
            foreach ($occurrences as $occurrence) {
                $source = $occurrence['source_path'];
                if (!isset($documentsBySource[$source])) continue;
                if (!array_filter($asset['scopes'], static fn(array $scope): bool => 'global' === $scope['kind'] || ($scope['source_path'] ?? null) === $source)) continue;
                $scope = WordPressSitePlan::stylesheetDocumentScope($documentsBySource[$source]);
                $instances[$source][] = array('asset' => $asset, 'scope' => $scope, 'route' => $source, 'condition' => self::bootstrapScopeCondition($scope, true), 'media' => $occurrence['media'], 'handle' => 'blocks-engine-instance-' . substr(hash('sha256', $asset['target_path'] . "\0" . $source . "\0" . $occurrence['order']), 0, 16), 'order' => $occurrence['order'], 'resource_order' => $index) + WordPressSitePlan::stylesheetDeviceAttributes($occurrence);
            }
            if (!empty($asset['frame_fallback'])) {
                $row = array('asset' => $asset, 'scope' => array('kind' => 'global'), 'condition' => 'true', 'media' => (string) ($asset['media'] ?? ''), 'handle' => 'blocks-engine-frame-fallback-' . substr(hash('sha256', $asset['target_path']), 0, 12), 'fallback' => true);
                if ('before-author' === ($asset['stylesheet_placement'] ?? '')) $before[] = $row; else $after[] = $row;
            }
            foreach ($asset['scopes'] as $scopeIndex => $scope) {
                if (array() === $occurrences && 0 !== $scopeIndex) continue;
                if (array() !== $occurrences && 'global' !== $scope['kind']) continue;
                // A global resource with document instances still styles
                // WordPress-native routes (archives, search) that no source
                // document owns; the bootstrap guards it with the route flag.
                $fallback = array() !== $occurrences;
                $row = array('asset' => $asset, 'scope' => $scope, 'condition' => self::bootstrapScopeCondition($scope, true), 'media' => (string) ($asset['media'] ?? ''), 'handle' => 'blocks-engine-' . substr(hash('sha256', $asset['target_path']), 0, 12), 'fallback' => $fallback);
                if (!$fallback) {
                    $row['scopes'] = $asset['scopes'];
                    $row['condition'] = self::compactStylesheetCondition($asset['scopes']);
                }
                if (!$fallback && ('before-author' === ($asset['stylesheet_placement'] ?? '') || ($index < $firstManaged && 'after-author' !== ($asset['stylesheet_placement'] ?? '') && 'wordpress-compat' !== ($asset['source'] ?? '')))) $before[] = $row;
                else $after[] = $row;
            }
        }
        $ordered = $before;
        foreach ($instances as $rows) {
            usort($rows, static fn(array $left, array $right): int => $left['order'] <=> $right['order'] ?: $left['resource_order'] <=> $right['resource_order']);
            array_push($ordered, ...$rows);
        }
        $expanded = array();
        $byTarget = array_column($assets, null, 'target_path');
        foreach (array_merge($ordered, $after) as $row) {
            if (!isset($row['asset']['stylesheet_parts'])) { $expanded[] = $row; continue; }
            foreach ($row['asset']['stylesheet_parts'] as $position => $path) {
                $part = $row;
                // Bytes are shared; delivery remains owned by this occurrence.
                $part['asset'] = array_replace($row['asset'], array_intersect_key($byTarget[$path], array_flip(array('source_path', 'target_path', 'token', 'reconciliation_identity', 'content', 'content_hash', 'size_bytes'))));
                unset($part['asset']['stylesheet_parts']);
                $part['handle'] .= '-part-' . $position;
                $expanded[] = $part;
            }
        }
        return $expanded;
    }

    /**
     * The ordered stylesheet enqueues the generated bootstrap publishes for one
     * canonical document's route: before-author globals, the document's
     * instances (one handle per occurrence, shared resource URI), then
     * after-author globals. A host that publishes plan assets without that
     * bootstrap (a companion plugin inside an existing theme) replays this
     * sequence instead of re-deriving it from asset fields.
     *
     * @param array<string,mixed> $plan
     * @return list<array{target_path:string,handle:string,media:string,stylesheet_target:string}>
     */
    public static function documentStylesheets(array $plan, string $sourcePath): array
    {
        $assets = ArrayField::array($plan, 'assets');
        $importLoaded = self::importLoadedStylesheets($assets);
        $sequence = array();
        foreach (self::stylesheetEnqueues($assets, ArrayField::array($plan, 'pages'), $plan['theme']['document_roots'] ?? array()) as $enqueue) {
            $asset = $enqueue['asset'];
            if (!empty($enqueue['fallback']) || isset($importLoaded[$asset['target_path']])) continue;
            $owned = isset($enqueue['route']) ? $sourcePath === $enqueue['route'] : ('global' === $enqueue['scope']['kind'] || $sourcePath === ($enqueue['scope']['source_path'] ?? null));
            if (isset($enqueue['scopes'])) $owned = in_array('global', array_column($enqueue['scopes'], 'kind'), true) || in_array($sourcePath, array_column($enqueue['scopes'], 'source_path'), true);
            if ($owned) $sequence[] = array('target_path' => (string) $asset['target_path'], 'handle' => (string) $enqueue['handle'], 'media' => (string) $enqueue['media'], 'stylesheet_target' => (string) ($asset['stylesheet_target'] ?? 'both'));
        }
        return $sequence;
    }

    /**
     * A stylesheet that another stylesheet loads through `@import` (a chunked
     * stylesheet's loader, or an authored import) already loads at its
     * importer's cascade position. Enqueueing it as well adds a second copy at
     * an unrelated position, which inverts the source cascade.
     *
     * @param array<int,array<string,mixed>> $assets
     * @return array<string,true> Target paths that load only through an importing stylesheet.
     */
    private static function importLoadedStylesheets(array $assets): array
    {
        $stylesheets = array();
        foreach ($assets as $asset) if ('css' === ($asset['kind'] ?? null)) $stylesheets[(string) $asset['source_path']] = true;
        $importLoaded = array();
        foreach ($assets as $asset) {
            if ('css' !== ($asset['kind'] ?? null)) continue;
            $linked = false;
            $imported = false;
            foreach (ArrayField::array($asset, 'references') as $reference) {
                if (!is_array($reference)) continue;
                if ('link' === ($reference['element'] ?? null)) $linked = true;
                $importer = (string) ($reference['source_path'] ?? '');
                if ('css-import' === ($reference['context'] ?? null) && isset($stylesheets[$importer]) && $importer !== $asset['source_path'] && self::importResolvesTo((string) ($reference['url'] ?? ''), $importer, (string) $asset['source_path'])) $imported = true;
            }
            if ($imported && !$linked) $importLoaded[(string) $asset['target_path']] = true;
        }
        return $importLoaded;
    }

    /**
     * A root-relative import names a path under the artifact root, not under
     * its importer: an inline `<style>` asset lives at a virtual `inline/`
     * path, so `@import "/en.css"` reaches a capture's `website/en.css`.
     * Match it the way root-relative stylesheet links resolve, by suffix.
     */
    private static function importResolvesTo(string $url, string $importer, string $sourcePath): bool
    {
        $url = ArtifactPath::stripQueryAndFragment($url);
        if (!str_starts_with($url, '/') || str_starts_with($url, '//')) return $sourcePath === ArtifactPath::resolveRelativePath($url, $importer);
        $relative = ArtifactPath::resolveRelativePath(ltrim($url, '/'));
        return '' !== $relative && ($sourcePath === $relative || str_ends_with($sourcePath, '/' . $relative));
    }

    /** @param list<array<string,mixed>> $scopes */
    private static function compactStylesheetCondition(array $scopes): string
    {
        if (1 === count($scopes)) return self::bootstrapScopeCondition($scopes[0], true);
        $conditions = $identities = $paths = array();
        foreach ($scopes as $scope) {
            if ('page' === $scope['kind'] && empty($scope['front_page'])) {
                $identities[] = $scope['reconciliation_identity'];
                $paths[] = $scope['route_path'];
            } else $conditions[] = '(' . self::bootstrapScopeCondition($scope, true) . ')';
        }
        if (array() !== $identities) $conditions[] = "( 0 !== \$blocks_engine_page_id && ( '' !== \$blocks_engine_page_identity ? in_array( \$blocks_engine_page_identity, " . BootstrapData::literal($identities) . ", true ) : in_array( \$blocks_engine_page_uri, " . BootstrapData::literal($paths) . ", true ) ) )";
        return implode(' || ', $conditions);
    }

    /** @param array<string,mixed> $scope */
    private static function bootstrapScopeCondition(array $scope, bool $compact = false): string
    {
        if ('global' === ($scope['kind'] ?? null)) return 'true';
        if ('archive' === ($scope['kind'] ?? null)) return var_export($scope['route_path'], true) . ' === blocks_engine_archive_path()';
        if (!empty($scope['front_page'])) return 'is_front_page()';
        if ('post' === ($scope['kind'] ?? null)) return "is_singular( 'post' ) && " . var_export($scope['reconciliation_identity'], true) . " === get_post_meta( get_queried_object_id(), '_blocks_engine_reconciliation_identity', true )";
        if ($compact) return "0 !== \$blocks_engine_page_id && ( '' !== \$blocks_engine_page_identity ? " . var_export($scope['reconciliation_identity'], true) . " === \$blocks_engine_page_identity : " . var_export($scope['route_path'], true) . " === \$blocks_engine_page_uri )";
        return "0 !== ( \$blocks_engine_page_id = " . self::DOCUMENT_ID_EXPRESSION . " ) && ( '' !== ( \$blocks_engine_page_identity = get_post_meta( \$blocks_engine_page_id, '_blocks_engine_reconciliation_identity', true ) ) ? " . var_export($scope['reconciliation_identity'], true) . " === \$blocks_engine_page_identity : 'page' === get_post_type( \$blocks_engine_page_id ) && " . var_export($scope['route_path'], true) . " === trim( get_page_uri( \$blocks_engine_page_id ), '/' ) )";
    }
}
