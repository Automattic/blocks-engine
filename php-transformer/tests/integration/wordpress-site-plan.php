<?php
declare(strict_types=1);

// Run with a standard WordPress test-suite installation, for example:
// WP_TESTS_DIR=/path/to/wordpress-tests-lib composer test:wordpress-integration
$testsDir = getenv('WP_TESTS_DIR');
if (!is_string($testsDir) || !is_file($testsDir . '/includes/bootstrap.php')) {
    if ('1' === getenv('REQUIRE_WP_TESTS')) {
        fwrite(STDERR, "WP_TESTS_DIR must point to a WordPress test-suite installation.\n");
        exit(1);
    }
    fwrite(STDOUT, "wordpress-site-plan WordPress integration skipped: WP_TESTS_DIR is unavailable.\n");
    exit(0);
}

require dirname(__DIR__, 2) . '/vendor/autoload.php';
require $testsDir . '/includes/bootstrap.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\WordPressSitePlanResolver;

$assert = static function (bool $condition, string $message): void { if (!$condition) throw new RuntimeException($message); };
$theme = 'blocks-engine-site-plan-contract-' . substr(hash('sha256', __FILE__), 0, 8);
$themeDir = WP_CONTENT_DIR . '/themes/' . $theme;
$previousTheme = get_stylesheet();
$previousTemplate = get_template();
$previousShowOnFront = get_option('show_on_front');
$previousPageOnFront = get_option('page_on_front');
$pageIds = array();
try {
if (!is_dir($themeDir) && !mkdir($themeDir, 0777, true) && !is_dir($themeDir)) throw new RuntimeException('Could not create integration theme directory.');
$result = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array(
    'index.html' => '<!doctype html><html><head><script src="/assets/head.js?head=1#top"></script><script src="assets/defer.js" defer></script></head><body><a class="skip-link" href="#content">Skip to content</a><header id="site-chrome" class="site-chrome" style="border-top:3px solid #111"><p>Integration Header</p></header><main id="content"><img src="assets/logo.svg"><h1>Home</h1></main><footer class="site-footer"><p>Integration Footer</p></footer><script src="assets/async.js" async defer></script><script src="assets/module.js" type="module"></script><script src="assets/legacy.js" nomodule integrity="sha384-test" crossorigin="anonymous" referrerpolicy="no-referrer"></script><script src="https://cdn.example.test/external.js?build=1#run" async></script></body></html>',
    'assets/logo.svg' => '<svg xmlns="http://www.w3.org/2000/svg"/>',
    'assets/head.js' => 'window.headAsset=true;',
    'assets/defer.js' => 'window.deferAsset=true;',
    'assets/async.js' => 'window.asyncAsset=true;',
    'assets/module.js' => 'window.moduleAsset=true;',
    'assets/legacy.js' => 'window.legacyAsset=true;',
    'about.html' => '<!doctype html><html><body><a class="skip-link" href="#content">Skip to content</a><header id="site-chrome" class="site-chrome" style="border-top:3px solid #111"><p>Integration Header</p></header><main id="content"><h1>Root About</h1></main><footer class="site-footer"><p>Integration Footer</p></footer><script src="assets/root-about.js"></script><script src="assets/shared.js"></script></body></html>',
    'nested/about.html' => '<!doctype html><html><head><script src="assets/about-head.js" defer></script></head><body><a class="skip-link" href="#content">Skip to content</a><header id="site-chrome" class="site-chrome" style="border-top:3px solid #111"><p>Integration Header</p></header><main id="content"><h1>About</h1></main><footer class="site-footer"><p>Integration Footer</p></footer><script src="https://cdn.example.test/about.js" async></script><script src="assets/shared.js"></script></body></html>',
    'nested/deep/about.html' => '<!doctype html><html><body><a class="skip-link" href="#content">Skip to content</a><header id="site-chrome" class="site-chrome" style="border-top:3px solid #111"><p>Integration Header</p></header><main id="content"><h1>Deep About</h1></main><footer class="site-footer"><p>Integration Footer</p></footer><script src="assets/deep-about.js"></script></body></html>',
    array('path' => 'notes/essay.html', 'content' => '<main><article>Essay<time datetime="2024-03-02T10:30:00Z"></time></article></main><script src="assets/essay.js"></script>'),
    'assets/about-head.js' => 'window.aboutHeadAsset=true;',
    'assets/root-about.js' => 'window.rootAboutAsset=true;',
    'assets/deep-about.js' => 'window.deepAboutAsset=true;',
    'assets/shared.js' => 'window.sharedAsset=true;',
    'assets/essay.js' => 'window.essayAsset=true;',
)))->toArray();
$plan = $result['source_reports']['wordpress_site_plan'] ?? array();
$resolved = (new WordPressSitePlanResolver())->resolve($plan, array('theme_uri' => home_url('/wp-content/themes/' . $theme)));
foreach ($resolved['writes'] as $write) {
    $path = $themeDir . '/' . $write['target_path'];
    if (!is_dir(dirname($path)) && !mkdir(dirname($path), 0777, true) && !is_dir(dirname($path))) throw new RuntimeException('Could not create theme write directory.');
    if (false === file_put_contents($path, 'base64' === $write['payload']['encoding'] ? base64_decode($write['payload']['data'], true) : $write['payload']['data'])) throw new RuntimeException('Could not write materialized theme file.');
}
wp_clean_themes_cache();
$wpTheme = wp_get_theme($theme);
$assert($wpTheme->exists(), 'WordPress recognizes the materialized block theme.');
switch_theme($theme);
require $themeDir . '/functions.php';
$positionedSvg = (new HtmlTransformer())->transform('<style>.hero-media{position:relative;width:1280px;height:760px}@media(max-width:700px){.hero-media{width:320px;height:240px}}</style><main><div class="hero-media"><svg class="hero-art" width="100%" height="100%" style="object-fit:cover" viewBox="0 0 1280 728.88"><rect width="1280" height="728.88" fill="#111"/></svg></div></main>')->toArray();
$positionedSvgMarkup = (string) ($positionedSvg['serialized_blocks'] ?? '');
$positionedSvgCss = implode("\n", array_map(static fn(array $asset): string => 'css' === ($asset['kind'] ?? '') ? (string) ($asset['content'] ?? '') : '', $positionedSvg['assets'] ?? array()));
$positionedSvgId = wp_insert_post(array('post_type' => 'page', 'post_status' => 'draft', 'post_title' => 'Positioned SVG', 'post_content' => serialize_blocks(parse_blocks($positionedSvgMarkup))), true);
if (is_wp_error($positionedSvgId)) throw new RuntimeException($positionedSvgId->get_error_message());
$pageIds['positioned-svg'] = $positionedSvgId;
$positionedSvgSaved = (string) get_post_field('post_content', $positionedSvgId);
$assert(str_contains($positionedSvgCss, '.wp-block-image.be-inline-geometry-') && str_contains($positionedSvgCss, '>img{width:100%;height:100%;-o-object-fit:cover;object-fit:cover}') && str_contains($positionedSvgSaved, 'wp-block-image hero-art be-inline-geometry-') && 'core/image' === (parse_blocks($positionedSvgSaved)[0]['innerBlocks'][0]['blockName'] ?? null), 'WordPress parses and saves positioned SVG fill as a native core/image while its desktop/mobile parent-fill CSS defeats core intrinsic-image sizing.');
$fixture87Styles = '.gallery .photo{min-height:var(--h)}.gallery .photo::before{content:"";display:block;height:100%;background:linear-gradient(135deg,var(--a),var(--b))}.tour-card{background:linear-gradient(135deg,var(--tone),#fff)}';
$fixture87 = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array('index.html' => '<link rel="stylesheet" href="assets/site.css"><main><div class="gallery"><figure class="photo" style="--h:280px;--a:#27485f;--b:#87d8ff"></figure></div><div class="tour-card" style="--tone:#315b74;border-color:#d8dee9;border-width:1px;border-style:solid;border-radius:16px;padding:1.2rem;min-height:430px">Card</div></main>', 'assets/site.css' => $fixture87Styles)))->toArray();
$fixture87Saved = serialize_blocks(parse_blocks((string) ($fixture87['serialized_blocks'] ?? '')));
$fixture87Css = implode("\n", array_map(static fn(array $asset): string => 'css' === ($asset['kind'] ?? '') ? (string) ($asset['content'] ?? '') : '', $fixture87['assets'] ?? array()));
$assert(!str_contains($fixture87Saved, '--h:') && !str_contains($fixture87Saved, '--tone:') && str_contains($fixture87Saved, 'min-height:var(--h)') && str_contains($fixture87Saved, 'border-color:#d8dee9;border-style:solid;border-width:1px;border-radius:16px;min-height:430px;padding-top:1.2rem;padding-right:1.2rem;padding-bottom:1.2rem;padding-left:1.2rem') && str_contains($fixture87Css, '--h:280px !important;--a:#27485f !important;--b:#87d8ff !important') && str_contains($fixture87Css, '--tone:#315b74 !important'), 'WordPress parse/save retains fixture87 core group support styles while generated carrier CSS preserves gallery and card custom-property paint.');
$pageDeclarations = array(); foreach ($resolved['pages'] as $page) $pageDeclarations[$page['source_path']] = $page;
$pagesBySource = array(); foreach ($resolved['operations'] as $operation) if ('create_page' === $operation['kind']) { $page = $pageDeclarations[$operation['source_path']] ?? null; if (!is_array($page) || ($operation['post_type'] ?? $page['post_type']) !== $page['post_type']) throw new RuntimeException('Create operation lacks an authoritative post type.'); $id = wp_insert_post(array('post_type' => $page['post_type'], 'post_status' => 'publish', 'post_title' => $page['title'], 'post_name' => $operation['slug'], 'post_parent' => 'page' === $page['post_type'] && '' !== $operation['parent_source_path'] ? ($pagesBySource[$operation['parent_source_path']] ?? 0) : 0, 'post_content' => $page['resolved_block_markup']), true); if (is_wp_error($id)) throw new RuntimeException($id->get_error_message()); update_post_meta($id, '_blocks_engine_reconciliation_identity', $page['reconciliation_identity']); $pageIds[$operation['reconciliation_identity']] = $id; $pagesBySource[$operation['source_path']] = $id; }
foreach ($resolved['operations'] as $operation) if ('site_reading' === $operation['kind']) { update_option('show_on_front', $operation['show_on_front']); update_option('page_on_front', $pageIds[$operation['front_page_reconciliation_identity']]); }
$readingOperation = array_values(array_filter($resolved['operations'], static fn(array $operation): bool => 'site_reading' === $operation['kind']))[0] ?? array();
$assert('page' === get_option('show_on_front') && $pageIds[$readingOperation['front_page_reconciliation_identity']] === (int) get_option('page_on_front'), 'WordPress applies the declared topological page and front-page operations without manual hierarchy mutation.');
$essay = get_post($pagesBySource['notes/essay.html'] ?? 0); $essayPlan = $pageDeclarations['notes/essay.html'] ?? array();
$assert($essay && 'post' === $essay->post_type && 0 === (int) $essay->post_parent && ($essayPlan['reconciliation_identity'] ?? null) === get_post_meta($essay->ID, '_blocks_engine_reconciliation_identity', true), 'Reference materialization honors operation post_type, keeps posts parentless, and persists the runtime reconciliation identity.');
$frontPage = get_post((int) get_option('page_on_front')); if (!$frontPage) throw new RuntimeException('Could not load front page.');
global $wp_query;
$setRequest = static function (WP_Post $post, bool $frontPage) use (&$wp_query): void { $page = 'page' === $post->post_type; $uri = $page ? get_page_uri($post) : ''; $wp_query->is_front_page = $frontPage; $wp_query->is_page = $page; $wp_query->is_single = !$page; $wp_query->is_home = false; $wp_query->is_singular = true; $wp_query->post = $post; $wp_query->posts = array($post); $wp_query->queried_object = $post; $wp_query->queried_object_id = $post->ID; $wp_query->query_vars = $page ? array('page_id' => $post->ID, 'pagename' => $uri) : array('p' => $post->ID, 'post_type' => 'post'); setup_postdata($post); };
$resetScripts = static function (): WP_Scripts { $scripts = wp_scripts(); $scripts->queue = array(); $scripts->to_do = array(); $scripts->done = array(); $scripts->in_footer = array(); $scripts->groups = array(); return $scripts; };
$setRequest($frontPage, true); $scripts = $resetScripts(); do_action('wp_enqueue_scripts');
$handleFor = static function (string $needle) use ($scripts): string { foreach ($scripts->registered as $handle => $script) if (str_starts_with($handle, 'blocks-engine-script-') && str_contains((string) $script->src, $needle)) return $handle; throw new RuntimeException("Missing generated script {$needle}."); };
$head = $handleFor('assets/assets/head.js?head=1#top'); $defer = $handleFor('assets/assets/defer.js'); $async = $handleFor('assets/assets/async.js'); $module = $handleFor('assets/assets/module.js'); $legacy = $handleFor('assets/assets/legacy.js'); $external = $handleFor('https://cdn.example.test/external.js?build=1#run'); $aboutHead = $handleFor('assets/assets/about-head.js'); $aboutExternal = $handleFor('https://cdn.example.test/about.js'); $rootAbout = $handleFor('assets/assets/root-about.js'); $deepAbout = $handleFor('assets/assets/deep-about.js'); $shared = $handleFor('assets/assets/shared.js'); $essayScript = $handleFor('assets/assets/essay.js');
$assert($scripts->registered[$head]->src === get_theme_file_uri('assets/assets/head.js') . '?head=1#top' && $scripts->registered[$external]->src === 'https://cdn.example.test/external.js?build=1#run' && $scripts->registered[$defer]->deps === array() && $scripts->registered[$module]->deps === array(), 'Generated theme preserves local root-relative suffixes, external URLs, and strategy-safe empty dependencies.');
$generatedHandles = array($head, $defer, $async, $module, $legacy, $external, $aboutHead, $aboutExternal, $rootAbout, $deepAbout, $shared, $essayScript);
$assertQueue = static function (array $expected, WP_Scripts $scripts, string $message) use ($assert, $generatedHandles): void { $actual = array_values(array_filter($scripts->queue, static fn(string $handle): bool => in_array($handle, $generatedHandles, true))); $assert($expected === $actual, $message . ': ' . json_encode(array('expected' => $expected, 'actual' => $actual))); };
$assertQueue(array($head, $defer, $async, $module, $legacy, $external), $scripts, 'Front-page request enqueues only front-page declarations in source order');
ob_start(); wp_print_head_scripts(); $headTags = (string) ob_get_clean(); ob_start(); wp_print_footer_scripts(); $footerTags = (string) ob_get_clean();
$tag = static function (string $tags, string $needle): string { if (!preg_match('~<script\b[^>]*\bsrc=["\'][^"\']*' . preg_quote($needle, '~') . '[^"\']*["\'][^>]*></script>~', $tags, $match)) throw new RuntimeException("Missing rendered script {$needle}."); return $match[0]; };
$headTag = $tag($headTags, 'head.js?head=1#top'); $deferTag = $tag($headTags, 'defer.js'); $asyncTag = $tag($footerTags, 'async.js'); $moduleTag = $tag($footerTags, 'module.js'); $legacyTag = $tag($footerTags, 'legacy.js'); $externalTag = $tag($footerTags, 'external.js?build=1#run');
$assert(!str_contains($headTag, ' async') && !str_contains($headTag, ' defer') && str_contains($deferTag, ' defer') && str_contains($asyncTag, ' async') && str_contains($asyncTag, ' defer') && str_contains($moduleTag, 'type="module"') && str_contains($legacyTag, ' nomodule') && str_contains($legacyTag, 'integrity="sha384-test"') && str_contains($legacyTag, 'crossorigin="anonymous"') && str_contains($legacyTag, 'referrerpolicy="no-referrer"') && str_contains($externalTag, ' async'), 'Each rendered handle preserves its exact loading and tag attributes.');
$frontOrder = array(strpos($headTags, 'head.js?head=1#top'), strpos($headTags, 'defer.js'), strpos($footerTags, 'async.js'), strpos($footerTags, 'module.js'), strpos($footerTags, 'legacy.js'), strpos($footerTags, 'external.js?build=1#run'));
$assert($frontOrder === array_values(array_filter($frontOrder, static fn(int $offset): bool => $offset >= 0)), 'Rendered front-page scripts retain their declared order without dependency edges.');
$about = get_post($pagesBySource['nested/about.html']); if (!$about) throw new RuntimeException('Could not load nested about page.');
$setRequest($about, false); $scripts = $resetScripts(); do_action('wp_enqueue_scripts');
$assertQueue(array($aboutHead, $aboutExternal, $shared), $scripts, 'Nested-page request excludes front-page and duplicate-slug sibling declarations');
ob_start(); wp_print_head_scripts(); $nestedHeadTags = (string) ob_get_clean(); ob_start(); wp_print_footer_scripts(); $nestedFooterTags = (string) ob_get_clean();
$assert(str_contains($nestedHeadTags, 'about-head.js') && str_contains($nestedFooterTags, 'about.js') && str_contains($nestedFooterTags, 'shared.js') && !str_contains($nestedHeadTags . $nestedFooterTags, 'head.js?head=1#top'), 'Nested-page rendered tags contain only its declared handles.');
$rootAboutPage = get_post($pagesBySource['about.html']); if (!$rootAboutPage) throw new RuntimeException('Could not load root about page.'); $setRequest($rootAboutPage, false); $scripts = $resetScripts(); do_action('wp_enqueue_scripts');
$assertQueue(array($rootAbout, $shared), $scripts, 'Root about request isolates duplicate leaf slugs and reuses the shared registration');
$deepAboutPage = get_post($pagesBySource['nested/deep/about.html']); if (!$deepAboutPage) throw new RuntimeException('Could not load deep about page.'); $setRequest($deepAboutPage, false); $scripts = $resetScripts(); do_action('wp_enqueue_scripts');
$assertQueue(array($deepAbout), $scripts, 'Deep nested request isolates duplicate leaf slugs');
$setRequest($essay, false); $scripts = $resetScripts(); do_action('wp_enqueue_scripts');
$assertQueue(array($essayScript), $scripts, 'Post request enqueues only its reconciliation-identity-scoped script.');
$front = file_get_contents($themeDir . '/templates/front-page.html'); if (false === $front) throw new RuntimeException('Could not read front-page template.');
$post = $frontPage; $setRequest($post, true);
$rendered = do_blocks($front); wp_reset_postdata();
$assert(1 === substr_count($rendered, '<header') && 1 === substr_count($rendered, '<footer') && str_contains($rendered, 'id="site-chrome"') && str_contains($rendered, 'site-chrome') && str_contains($rendered, 'border-top:3px solid #111') && str_contains($rendered, 'href="#content"') && str_contains($rendered, '<main id="content"'), 'WordPress renders one complete styled landmark wrapper per front-page route and preserves the skip-link target.');
$assert(str_contains($rendered, 'Home') && str_contains($rendered, home_url('/wp-content/themes/' . $theme . '/assets/assets/logo.svg')), 'WordPress renders front-page content with a browser-valid resolved image URL.');
$pageTemplate = file_get_contents($themeDir . '/templates/page.html'); if (false === $pageTemplate) throw new RuntimeException('Could not read page template.');
$post = $about; $setRequest($post, false); $nestedRendered = do_blocks($pageTemplate); wp_reset_postdata();
$assert(1 === substr_count($nestedRendered, '<header') && 1 === substr_count($nestedRendered, '<footer') && str_contains($nestedRendered, 'About') && str_contains($nestedRendered, 'href="#content"') && str_contains($nestedRendered, '<main id="content"'), 'WordPress renders nested pages through declared shared parts without duplicate chrome.');
fwrite(STDOUT, "wordpress-site-plan WordPress integration passed\n");
} finally {
    foreach ($pageIds as $id) wp_delete_post((int) $id, true);
    update_option('show_on_front', $previousShowOnFront); update_option('page_on_front', $previousPageOnFront);
    if ('' !== $previousTheme) switch_theme($previousTheme);
    wp_clean_themes_cache();
    if (is_dir($themeDir)) { $items = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($themeDir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST); foreach ($items as $item) $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname()); rmdir($themeDir); }
    wp_clean_themes_cache();
}
