<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';
if ($bootstrap = getenv('PROJECTED_ROOT_DATA_STATE_WORDPRESS_BOOTSTRAP')) require $bootstrap;

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\Support\EngineMarker;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\DocumentRootContext;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\WordPressSitePlan;

/**
 * Projected root data-state predicates must survive actual root materialization.
 *
 * Author CSS such as `:root:not([data-launched="true"]) .launched-only{display:none}`
 * is projected as `:root:not(.marker) .launched-only`, where the marker is the
 * stable identity of the positive owner predicate. That marker is only truthful
 * when the root registry materializes it on exactly the routes whose source
 * root carried the positive value, and omits it on routes with the opposing
 * value or no value at all.
 */

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    ++$assertions;
    if (!$condition) throw new RuntimeException($message);
};

$css = 'body{margin:0}p{margin:0}.chrome{padding:8px}'
    . ':root:not([data-launched="true"]) .launched-only{display:none!important}'
    . ':root:not([data-launched="false"]) .preview-only{display:none!important}'
    . '.cta{font-size:16px}'
    . '@media(min-width:768px){.cta{font-size:20px}}'
    . '@media(min-width:1100px){.cta{font-size:24px}}';
$chrome = '<header class="chrome"><p>Shared header</p></header><footer class="chrome"><p>Shared footer</p></footer>';
$content = '<main><p>Route body copy</p></main>'
    . '<section class="launched-only"><a class="cta" href="/plans"><span>Plan CTA</span></a></section>'
    . '<section class="preview-only"><a class="cta" href="/preview"><span>Preview CTA</span></a></section>';
$page = static fn (string $rootOpen, string $title): string => '<!doctype html><html ' . $rootOpen . '><head><title>' . $title . '</title><link rel="stylesheet" href="/site.css"></head><body>'
    . $chrome . $content . '</body></html>';
$sources = array(
    'index.html' => $page('data-launched="true" class="site"', 'Launched'),
    'preview.html' => $page('data-launched="false"', 'Preview'),
    'archive.html' => $page('', 'Archive'),
    'site.css' => $css,
);

$normalize = static fn (string $markup): string => preg_replace('/blocks-engine-[a-z-]+-[0-9a-f]{12}-\d+/', 'blocks-engine-marker', $markup) ?? $markup;
/** @return array<string,string> Marker class owning the `.launched-only`/`.preview-only` negation in the projected CSS. */
$stateMarkers = static function (array $plan): array {
    $css = implode("\n", array_column(array_filter($plan['assets'], static fn (array $asset): bool => 'css' === $asset['kind']), 'content'));
    $markers = array();
    foreach (array('launched-only', 'preview-only') as $subject) {
        if (1 === preg_match('/:root:not\(\.(' . EngineMarker::patternBody() . ')\)\s+\.' . $subject . '/', $css, $match)) {
            $markers[$subject] = $match[1];
        }
    }
    return $markers;
};

$plans = array();
$compiler = new ArtifactCompiler();
$plans['whole'] = (new WordPressSitePlan())->fromCompilerResult($compiler->compile(array('entrypoint' => 'index.html', 'files' => $sources))->toArray());
$shared = $compiler->prepareShared(array('entrypoint' => 'index.html', 'files' => $sources));
$plans['staged'] = (new WordPressSitePlan())->fromCompilerResult($compiler->compose($shared, $compiler->compilePreparedPages($shared, $compiler->preparePages(array('entrypoint' => 'index.html', 'files' => $sources), $shared)))->toArray());

foreach ($plans as $driver => $plan) {
    $pages = array_column($plan['pages'], null, 'source_path');
    $markers = $stateMarkers($plan);
    $assert(isset($markers['launched-only']) && isset($markers['preview-only']), "{$driver}: both negated root-state rules are projected with their marker.");
    $assert($markers['launched-only'] !== $markers['preview-only'], "{$driver}: opposing valued predicates own distinct state markers.");

    $launchedRoot = $pages['index.html']['document_metadata']['root_attributes'];
    $previewRoot = $pages['preview.html']['document_metadata']['root_attributes'];
    $archiveRoot = $pages['archive.html']['document_metadata']['root_attributes'];
    $assert('true' === ($launchedRoot['data-launched'] ?? null), "{$driver}: the positive source value stays on the rendered root registry.");
    $assert('false' === ($previewRoot['data-launched'] ?? null), "{$driver}: the opposing source value stays on its own route registry.");
    $assert(in_array($markers['launched-only'], preg_split('/\s+/', trim($launchedRoot['class'] ?? '')) ?: array(), true), "{$driver}: the launched route materializes the marker its projected predicate negates.");
    $assert(!in_array($markers['launched-only'], preg_split('/\s+/', trim($previewRoot['class'] ?? '')) ?: array(), true), "{$driver}: the opposing route never materializes the launched marker.");
    $assert(in_array($markers['preview-only'], preg_split('/\s+/', trim($previewRoot['class'] ?? '')) ?: array(), true), "{$driver}: the preview route materializes the marker for its own positive value.");
    $assert(!in_array($markers['preview-only'], preg_split('/\s+/', trim($launchedRoot['class'] ?? '')) ?: array(), true), "{$driver}: the launched route never materializes the preview marker.");
    $assert(!EngineMarker::matchesAny((string) ($archiveRoot['class'] ?? '')), "{$driver}: an unvalued route carries no state marker.");
    $assert(!isset($archiveRoot['data-launched']), "{$driver}: absence of the attribute is not invented as a value.");

    foreach ($pages as $page) {
        $bodyClasses = trim((string) ($page['document_metadata']['body_attributes']['class'] ?? ''));
        $assert(!EngineMarker::matchesAny($bodyClasses), "{$driver}: root-state markers never enter body ownership: " . $bodyClasses);
        $assert(!str_contains((string) $page['canonical_block_markup'], (string) $markers['launched-only']), "{$driver}: the root-state marker is not a source-specific block class.");
    }

    $assert(count(array_filter($plan['template_parts'], static fn (array $part): bool => in_array($part['slug'], array('header', 'footer'), true))) >= 2, "{$driver}: the neutral shared chrome stays shared.");
    foreach ($plan['template_parts'] as $part) {
        $assert(!str_contains((string) $part['canonical_block_markup'], (string) $markers['launched-only']), "{$driver}: shared chrome carries no root-state marker of its own.");
    }

    $bootstrap = DocumentRootContext::bootstrap($plan['pages']);
    $rows = substr_count($bootstrap, $markers['launched-only']);
    $assert(1 === $rows, "{$driver}: exactly one route record owns the launched marker, found {$rows}.");
    $assert(str_contains($bootstrap, $markers['preview-only']), "{$driver}: the generated root registry carries the preview marker.");
    $assert(false === DocumentRootContext::needsCanvas($plan['pages']), "{$driver}: root-state markers stay html-owned and never switch canvas selection.");
}

$wholePages = array_column($plans['whole']['pages'], 'document_metadata', 'source_path');
$stagedPages = array_column($plans['staged']['pages'], 'document_metadata', 'source_path');
foreach (array('index.html', 'preview.html', 'archive.html') as $route) {
    $assert(
        ($wholePages[$route]['root_attributes'] ?? array()) === ($stagedPages[$route]['root_attributes'] ?? array()),
        "whole and staged compilation agree on the materialized {$route} root registry."
    );
}
$assert(
    $normalize(implode('', array_column($plans['whole']['assets'], 'content'))) === $normalize(implode('', array_column($plans['staged']['assets'], 'content'))),
    'whole and staged compilation agree on the projected root-state CSS.'
);

// With WordPress loaded, exercise the real language_attributes/body_class hooks,
// the emitted canvas, and the editor settings the editor script reconciles.
if (function_exists('do_blocks')) {
    $plan = $plans['whole'];
    $pages = array_column($plan['pages'], null, 'source_path');
    eval(DocumentRootContext::bootstrap(array_slice($plan['pages'], 0, 1)));
    eval(DocumentRootContext::bootstrap(array_slice($plan['pages'], 1)));
    if (isset($blocks_engine_document_attributes)) $GLOBALS['blocks_engine_document_attributes'] = $blocks_engine_document_attributes;
    foreach (array('wp_head', 'wp_footer', 'wp_body_open') as $hook) remove_all_actions($hook);
    $activeCss = static function (array $page) use ($plan): string {
        $assets = array_filter($plan['assets'], static function (array $asset) use ($page): bool {
            if ('css' !== $asset['kind']) return false;
            foreach ($asset['scopes'] as $scope) if ('global' === $scope['kind'] || $page['source_path'] === ($scope['source_path'] ?? null)) return true;
            return false;
        });
        return implode("\n", array_column($assets, 'content'));
    };
    add_filter('get_post_metadata', static function ($value, $id, $key) use ($pages) {
        if ('_blocks_engine_reconciliation_identity' !== $key) return $value;
        return array_values($pages)[$id - 900001]['reconciliation_identity'] ?? $value;
    }, 10, 3);
    add_filter('posts_pre_query', static function ($posts, $query) {
        return 'wp_template_part' === $query->get('post_type') ? array() : $posts;
    }, 10, 2);
    $parts = array_column($plan['template_parts'], null, 'slug');
    add_filter('pre_get_block_file_template', static function ($value, $id, $type) use ($parts) {
        $slug = explode('//', $id)[1] ?? '';
        if ('wp_template_part' !== $type || !isset($parts[$slug])) return $value;
        $template = new WP_Block_Template();
        $template->id = $id;
        $template->theme = get_stylesheet();
        $template->slug = $slug;
        $template->type = $type;
        $template->content = $parts[$slug]['canonical_block_markup'];
        $template->source = 'theme';
        return $template;
    }, 10, 3);

    $markers = $stateMarkers($plan);
    $fixtures = array();
    foreach (array_values($pages) as $index => $page) {
        global $wp_query;
        $wp_query = new WP_Query();
        $wp_query->is_singular = true;
        $wp_query->is_page = true;
        $wp_query->is_home = false;
        $wp_query->queried_object_id = 900001 + $index;
        $wp_query->queried_object = new WP_Post((object) array('ID' => 900001 + $index, 'post_type' => 'page', 'post_title' => $page['title'], 'post_name' => $page['slug'], 'post_parent' => 0));
        $template = array_column($plan['templates'], null, 'slug')['page'] ?? $plan['templates'][0] ?? null;
        $GLOBALS['_wp_current_template_content'] = str_replace('<!-- wp:post-content /-->', $page['canonical_block_markup'], $template['canonical_block_markup'] ?? $page['canonical_block_markup']);
        $GLOBALS['_wp_current_template_id'] = '';
        ob_start();
        require ABSPATH . WPINC . '/template-canvas.php';
        $native = (string) ob_get_clean();
        $htmlOpen = 1 === preg_match('/<html[^>]*>/i', $native, $htmlMatch) ? $htmlMatch[0] : '';
        $isLaunched = 'index.html' === $page['source_path'];
        $expectedValue = $isLaunched ? 'true' : ('preview.html' === $page['source_path'] ? 'false' : null);
        $assert(null === $expectedValue ? !str_contains($htmlOpen, 'data-launched') : str_contains($htmlOpen, 'data-launched="' . $expectedValue . '"'), "rendered route {$page['source_path']}: source root values are retained verbatim: " . $htmlOpen);
        $assert($isLaunched ? str_contains($htmlOpen, $markers['launched-only']) : !str_contains($htmlOpen, $markers['launched-only']), "rendered route {$page['source_path']}: the launched marker is materialized on exactly the routes whose source matched: " . $htmlOpen);
        $assert(str_contains($native, 'Shared header') && str_contains($native, 'Shared footer'), "rendered route {$page['source_path']}: shared chrome renders through native template parts.");

        $context = (object) array('post' => (object) array('ID' => 900001 + $index));
        $settings = apply_filters('block_editor_settings_all', array(), $context);
        $editorContext = $settings['blocksEngineDocumentContext'] ?? array();
        $assert($isLaunched ? str_contains((string) ($editorContext['html']['class'] ?? ''), $markers['launched-only']) : !str_contains((string) ($editorContext['html']['class'] ?? ''), $markers['launched-only']), "editor route {$page['source_path']}: the editor context carries the same state contract.");
        $markup = $page['canonical_block_markup'];
        $fixtures[] = array(
            'route' => $page['source_path'],
            'source' => str_replace('</head>', '<style>' . $css . '</style></head>', $sources[$page['source_path']]),
            'native' => str_replace('</head>', '<style>' . $activeCss($page) . '</style></head>', $native),
            'editor' => '<!doctype html><html><head><style>' . $css . '</style></head><body class="editor-styles-wrapper">' . do_blocks($markup) . '<script>window.blocksEngineDocumentContext=' . wp_json_encode($editorContext) . ';' . DocumentRootContext::editorScript() . '</script></body></html>',
        );
    }
    if ($output = getenv('PROJECTED_ROOT_DATA_STATE_ARTIFACT')) file_put_contents($output, wp_json_encode($fixtures, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
}
if ($planOutput = getenv('PROJECTED_ROOT_DATA_STATE_PLAN')) file_put_contents($planOutput, json_encode(array('plans' => array_map(static fn (array $plan): array => array('pages' => array_column($plan['pages'], 'document_metadata', 'source_path'), 'assets' => array_column($plan['assets'], 'content')), $plans), 'sources' => $sources, 'css' => $css), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

fwrite(STDOUT, "Projected root data state: {$assertions} assertions passed\n");
