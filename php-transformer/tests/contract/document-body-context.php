<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';
if ($bootstrap = getenv('BODY_CONTEXT_WORDPRESS_BOOTSTRAP')) require $bootstrap;

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\WordPressCompatCss;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\DocumentRootContext;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\WordPressSitePlan;

// BODY_CONTEXT_BASELINE_SRC lets the same regression load the fetched parent
// implementation without altering either checkout.
if ($baseline = getenv('BODY_CONTEXT_BASELINE_SRC')) spl_autoload_register(static function (string $name) use ($baseline): void {
    $prefix = 'Automattic\\BlocksEngine\\PhpTransformer\\';
    if (str_starts_with($name, $prefix)) {
        $file = $baseline . '/' . str_replace('\\', '/', substr($name, strlen($prefix))) . '.php';
        if (is_file($file)) require $file;
    }
}, true, true);

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    ++$assertions;
    if (!$condition) throw new RuntimeException($message);
};
$css = 'body{margin:0}p{margin:0}.expand *{margin:0}.chrome{display:block}.expand{margin-left:-15px;margin-right:-15px;padding-left:15px;padding-right:15px}'
    . '.scope{padding-top:7px;background-color:rgb(240,240,240)}body.scope{color:rgb(11,22,33)}'
    . '.page{padding-top:7px;background-color:rgb(240,240,240)}'
    . '@media(min-width:1100px){.scope .expand{margin-left:0;margin-right:0;padding-left:0;padding-right:0}}'
    . '@supports(display:block){body[data-mode="boxed"] .expand{margin-left:0;margin-right:0;padding-left:9px;padding-right:9px}}'
    . '[data-mode="plain"] .expand{padding-left:12px;padding-right:12px}'
    . '@media(min-width:1100px){body:not(.responsive) #content-frame{min-width:1600px}}';
$header = '<header class="chrome"><div id="header-frame" class="expand"><p>Shared header</p></div></header>';
$footer = '<footer class="chrome"><div id="footer-frame" class="expand"><p>Shared footer</p></div></footer>';
$source = static fn(string $attributes, string $title): string => '<!doctype html><html data-document="neutral"><head><title>' . $title . '</title><link rel="stylesheet" href="/root.css"></head><body ' . $attributes . '>'
    . $header . '<main><div id="content-frame" class="expand"><p>' . $title . '</p></div><div id="same-element" class="scope page expand"><p>Actual scope subject</p></div></main>' . $footer . '</body></html>';
$sources = array('index.html' => $source('id="wide-document" class="scope responsive page" data-mode="wide" data-empty=""', 'Wide'), 'plain.html' => $source('data-mode="plain"', 'Plain'), 'boxed.html' => $source('class="scope responsive page" data-mode="boxed"', 'Boxed'), 'class-only.html' => $source('class="scope responsive page"', 'Class only'));
$result = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => $sources + array('root.css' => $css)))->toArray();
$plan = (new WordPressSitePlan())->fromCompilerResult($result);
$pages = array_column($plan['pages'], null, 'source_path');
$parts = array_column($plan['template_parts'], null, 'slug');
$style = implode("\n", array_column(array_filter($plan['assets'], static fn(array $asset): bool => 'css' === $asset['kind'] && 'editor' !== ($asset['stylesheet_target'] ?? 'both')), 'content'));

if (!getenv('BODY_CONTEXT_BASELINE_SRC')) {
    $assert('scope responsive page' === ($pages['index.html']['document_metadata']['body_attributes']['class'] ?? null), 'Body context travels through the canonical plan.');
    $assert('plain' === ($pages['plain.html']['document_metadata']['body_attributes']['data-mode'] ?? null), 'Root state remains route-owned.');
    $assert('' === ($pages['index.html']['document_metadata']['body_attributes']['data-empty'] ?? null), 'Presence-only data state survives.');
    $assert(count($parts) >= 2, 'The neutral header and footer are shared template parts.');
    $assert(!str_contains($pages['index.html']['canonical_block_markup'], 'expand scope'), 'Body classes are not duplicated onto source subjects.');
    $assert(str_contains($style, 'body.scope'), 'Body-subject rules retain their type and state predicate.');
    $assert(str_contains($style, 'body[data-mode="boxed"]'), 'Document data predicates are not frozen into shared subject markers.');
    $assert(!isset(DocumentRootContext::fromHtml('<body onclick="bad()" data-mode="wide"></body>', 'body')['onclick']), 'Only selector/state attributes enter the runtime.');
}

// With WordPress loaded, exercise generated hooks, native template-part
// resolution, core render callbacks, and the emitted canvas. No DB writes.
if (function_exists('do_blocks')) {
    $collisions = array();
    if (!getenv('BODY_CONTEXT_BASELINE_SRC')) {
        $compat = new WordPressCompatCss();
        foreach ($plan['assets'] as $asset) if ('css' === $asset['kind'] && 'editor' !== ($asset['stylesheet_target'] ?? 'both')) array_push($collisions, ...$compat->bodyClassCollisionClasses($asset['content']));
    }
    // The same shared header/footer spans two independently emitted batches.
    // The first route's body attributes must remain available to the canvas.
    eval(DocumentRootContext::bootstrap(array_slice($plan['pages'], 0, 1), array_values(array_unique($collisions))));
    eval(DocumentRootContext::bootstrap(array_slice($plan['pages'], 1), array_values(array_unique($collisions))));
    if (isset($blocks_engine_document_attributes)) $GLOBALS['blocks_engine_document_attributes'] = $blocks_engine_document_attributes;
    // Isolate this request's document from host plugins' head/footer output.
    // Core render callbacks and the native template canvas remain real.
    foreach (array('wp_head', 'wp_footer', 'wp_body_open') as $hook) remove_all_actions($hook);
    add_action('wp_head', static function () use (&$style): void { echo '<style>' . $style . '</style>'; });
    add_filter('get_post_metadata', static function ($value, $id, $key) use ($pages) {
        if ('_blocks_engine_reconciliation_identity' !== $key) return $value;
        return array_values($pages)[$id - 900001]['reconciliation_identity'] ?? $value;
    }, 10, 3);
    add_filter('posts_pre_query', static function ($posts, $query) {
        return 'wp_template_part' === $query->get('post_type') ? array() : $posts;
    }, 10, 2);
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
    global $wp_query;
    $fixtures = array();
    $render = static function (array $page, int $index, string $sourceHtml, string $route) use ($plan, $parts, $css, $assert, &$style, &$fixtures): void {
        $activeAssets = array_filter($plan['assets'], static function (array $asset) use ($page): bool {
            if ('css' !== $asset['kind']) return false;
            foreach ($asset['scopes'] as $scope) if ('global' === $scope['kind'] || $page['source_path'] === ($scope['source_path'] ?? null)) return true;
            return false;
        });
        $style = implode("\n", array_column(array_filter($activeAssets, static fn(array $asset): bool => 'editor' !== ($asset['stylesheet_target'] ?? 'both')), 'content'));
        $editorStyle = implode("\n", array_column(array_filter($activeAssets, static fn(array $asset): bool => 'frontend' !== ($asset['stylesheet_target'] ?? 'both')), 'content'));
        global $wp_query;
        $wp_query = new WP_Query();
        $wp_query->is_singular = true;
        $wp_query->is_page = true;
        $wp_query->is_home = false;
        $wp_query->queried_object_id = 900001 + $index;
        $wp_query->queried_object = (object) array('ID' => 900001 + $index, 'post_type' => 'page');
        $canvas = apply_filters('template_include', ABSPATH . WPINC . '/template-canvas.php');
        if (!getenv('BODY_CONTEXT_BASELINE_SRC')) {
            $expectedCanvas = array_diff_key($page['document_metadata']['body_attributes'], array('class' => true)) ? get_theme_file_path('document-canvas.php') : ABSPATH . WPINC . '/template-canvas.php';
            $assert($expectedCanvas === $canvas, 'Canvas selection follows actual route attributes; class-only documents use core.');
        }
        $template = array_column($plan['templates'], null, 'slug')['page'] ?? $plan['templates'][0] ?? null;
        $markup = str_replace('<!-- wp:post-content /-->', $page['canonical_block_markup'], $template['canonical_block_markup'] ?? $page['canonical_block_markup']);
        $GLOBALS['_wp_current_template_content'] = $markup;
        $GLOBALS['_wp_current_template_id'] = '';
        ob_start();
        if (ABSPATH . WPINC . '/template-canvas.php' === $canvas) require $canvas;
        else eval('?>' . DocumentRootContext::canvas());
        $native = (string) ob_get_clean();
        $context = (object) array('post' => (object) array('ID' => 900001 + $index));
        $settings = apply_filters('block_editor_settings_all', array(), $context);
        $fixtures[] = array('route' => $route, 'source' => str_replace('</head>', '<style>' . $css . '</style></head>', $sourceHtml),
            'native' => $native,
            'editor' => '<!doctype html><html><head><style>' . $editorStyle . '</style></head><body class="editor-styles-wrapper">' . do_blocks($markup) . ($settings['__unstableResolvedAssets']['body'] ?? '') . '</body></html>');
        $assert(str_contains($native, 'Shared header') && str_contains($native, 'Shared footer'), 'Native template parts render through core: ' . json_encode(array('parts' => array_keys($parts), 'template' => $template['slug'] ?? '', 'native' => $native)));
    };
    foreach (array_values($pages) as $index => $page) {
        $render($page, $index, $sources[$page['source_path']], $page['source_path']);
    }
    if (isset($blocks_engine_document_attributes)) {
        // An authoritative empty record replaces the same route's earlier
        // populated state. It is not an instruction to clear other routes.
        $boxedIndex = (int) array_search('boxed.html', array_keys($pages), true);
        $wp_query->queried_object_id = 900001 + $boxedIndex;
        $before = array('html' => $blocks_engine_document_attributes('html'), 'body' => $blocks_engine_document_attributes('body'));
        $emptyPage = $pages['boxed.html'];
        $emptyPage['document_metadata']['root_attributes'] = array();
        $emptyPage['document_metadata']['body_attributes'] = array();
        $replacement = DocumentRootContext::bootstrap(array($emptyPage));
        eval($replacement);
        $after = array('html' => $blocks_engine_document_attributes('html'), 'body' => $blocks_engine_document_attributes('body'));
        $afterCanvas = apply_filters('template_include', ABSPATH . WPINC . '/template-canvas.php');
        $wp_query->queried_object_id = 900001;
        $retained = array('html' => $blocks_engine_document_attributes('html'), 'body' => $blocks_engine_document_attributes('body'));
        if ($output = getenv('BODY_CONTEXT_TRANSITION_EVIDENCE')) file_put_contents($output, wp_json_encode(array('identity' => $emptyPage['reconciliation_identity'], 'before' => $before, 'after' => $after, 'after_canvas' => $afterCanvas, 'retained_route' => 'index.html', 'retained' => $retained, 'replacement_bootstrap_bytes' => strlen($replacement)), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        $assert('boxed' === ($before['body']['data-mode'] ?? null), 'The prior batch supplies the populated boxed route.');
        $assert(array('html' => array(), 'body' => array()) === $after, 'An authoritative empty route record must supersede both prior root attribute maps: ' . wp_json_encode($after));
        $assert(1 === count(array_filter($blocks_engine_document_roots, static fn(array $row): bool => $emptyPage['reconciliation_identity'] === $row['identity'])), 'The canonical registry retains one authoritative record for the updated identity.');
        $assert(ABSPATH . WPINC . '/template-canvas.php' === $afterCanvas, 'An emptied body context returns to core canvas selection.');
        $assert(array('html' => $pages['index.html']['document_metadata']['root_attributes'], 'body' => $pages['index.html']['document_metadata']['body_attributes']) === $retained, 'An independent earlier route retains its document state.');
        $emptySource = str_replace('<html data-document="neutral">', '<html>', $source('', 'Boxed'));
        $render($emptyPage, $boxedIndex, $emptySource, 'boxed.html#authoritative-empty');
        $render($pages['index.html'], 0, $sources['index.html'], 'index.html#retained-after-empty');
    }
    $wp_query->is_singular = false;
    $wp_query->is_page = false;
    if (!getenv('BODY_CONTEXT_BASELINE_SRC')) {
        $assert(array() === $blocks_engine_document_attributes('body'), 'Unowned routes do not inherit another page root state.');
        $assert(ABSPATH . WPINC . '/template-canvas.php' === apply_filters('template_include', ABSPATH . WPINC . '/template-canvas.php'), 'Unowned routes retain the native core canvas.');
    }
    $output = getenv('BODY_CONTEXT_ARTIFACT');
    if (!$output) throw new RuntimeException('BODY_CONTEXT_ARTIFACT must name the browser evidence file.');
    file_put_contents($output, wp_json_encode($fixtures, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
}
fwrite(STDOUT, "Document body context: {$assertions} assertions passed\n");
