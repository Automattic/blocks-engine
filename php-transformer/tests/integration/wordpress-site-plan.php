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
$previousUserId = get_current_user_id();
$editorUserId = 0;
$pageIds = array();
try {
if (!is_dir($themeDir) && !mkdir($themeDir, 0777, true) && !is_dir($themeDir)) throw new RuntimeException('Could not create integration theme directory.');
$result = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array(
    'index.html' => '<!doctype html><html><head><link rel="stylesheet" href="assets/global.css"><style>.home-owned{color:#123456}</style><script src="/assets/head.js?head=1#top"></script><script src="assets/defer.js" defer></script></head><body><a class="skip-link" href="#content">Skip to content</a><header id="site-chrome" class="site-chrome" style="border-top:3px solid #111"><p>Integration Header</p></header><main id="content"><img src="assets/logo.svg"><h1>Home</h1></main><footer class="site-footer"><p>Integration Footer</p></footer><script src="assets/async.js" async defer></script><script src="assets/module.js" type="module"></script><script src="assets/legacy.js" nomodule integrity="sha384-test" crossorigin="anonymous" referrerpolicy="no-referrer"></script><script src="https://cdn.example.test/external.js?build=1#run" async></script></body></html>',
    'assets/logo.svg' => '<svg xmlns="http://www.w3.org/2000/svg"/>',
    'assets/global.css' => 'body{color:#123456;background-color:#fefefe;font-family:Inter,sans-serif;font-size:18px;padding:24px}.global-presentation{display:block}',
    'assets/head.js' => 'window.headAsset=true;',
    'assets/defer.js' => 'window.deferAsset=true;',
    'assets/async.js' => 'window.asyncAsset=true;',
    'assets/module.js' => 'window.moduleAsset=true;',
    'assets/legacy.js' => 'window.legacyAsset=true;',
    'parts/sidebar.html' => '<aside class="site-sidebar"><p>Integration Sidebar</p></aside>',
    'about.html' => '<!doctype html><html><body><a class="skip-link" href="#content">Skip to content</a><header id="site-chrome" class="site-chrome" style="border-top:3px solid #111"><p>Integration Header</p></header><main id="content"><h1>Root About</h1></main><footer class="site-footer"><p>Integration Footer</p></footer><script src="assets/root-about.js"></script><script src="assets/shared.js"></script></body></html>',
    'nested/about.html' => '<!doctype html><html><head><link rel="stylesheet" href="assets/global.css"><style>.about-owned{color:#654321}</style><script src="assets/about-head.js" defer></script></head><body><a class="skip-link" href="#content">Skip to content</a><header id="site-chrome" class="site-chrome" style="border-top:3px solid #111"><p>Integration Header</p></header><main id="content"><h1>About</h1></main><footer class="site-footer"><p>Integration Footer</p></footer><script src="https://cdn.example.test/about.js" async></script><script src="assets/shared.js"></script></body></html>',
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
$themeJson = json_decode((string) file_get_contents($themeDir . '/theme.json'), true);
$globalStylesheet = wp_get_global_stylesheet();
$presetGroups = array(
    'color' => $themeJson['settings']['color']['palette'] ?? array(),
    'font-family' => $themeJson['settings']['typography']['fontFamilies'] ?? array(),
    'font-size' => $themeJson['settings']['typography']['fontSizes'] ?? array(),
    'spacing' => $themeJson['settings']['spacing']['spacingSizes'] ?? array(),
);
foreach ($presetGroups as $group => $presets) foreach ($presets as $preset) {
    $slug = (string) ($preset['slug'] ?? '');
    $variable = '--wp--preset--' . $group . '--' . $slug;
    $assert('' !== $slug && $slug === _wp_to_kebab_case($slug) && str_contains($globalStylesheet, $variable . ':') && str_contains($globalStylesheet, 'var(' . $variable . ')'), 'WordPress emits and resolves the generated ' . $group . ' preset without changing its slug.');
}
$sidebarPart = current(array_filter($plan['template_parts'] ?? array(), static fn(array $part): bool => 'sidebar' === ($part['slug'] ?? null)));
$sidebarWrite = current(array_filter($resolved['writes'] ?? array(), static fn(array $write): bool => 'templates/front-page.html' === ($write['target_path'] ?? null)));
$sidebarTemplateBlocks = parse_blocks((string) ($sidebarWrite['payload']['data'] ?? ''));
$sidebarBlocks = array_values(array_filter($sidebarTemplateBlocks, static fn(array $block): bool => 'core/template-part' === ($block['blockName'] ?? null) && 'sidebar' === ($block['attrs']['slug'] ?? null)));
$sidebarReference = isset($sidebarBlocks[0]) ? serialize_block($sidebarBlocks[0]) : '';
$sidebarRendered = do_blocks($sidebarReference);
$assert('uncategorized' === ($sidebarPart['area'] ?? null) && 'aside' === ($sidebarPart['tag_name'] ?? null) && 1 === count($sidebarBlocks) && 'uncategorized' === ($sidebarBlocks[0]['attrs']['area'] ?? null) && 'aside' === ($sidebarBlocks[0]['attrs']['tagName'] ?? null) && str_contains($sidebarRendered, '<aside ') && str_contains($sidebarRendered, 'Integration Sidebar') && !str_contains($sidebarRendered, '<sidebar'), 'WordPress parses and renders the sidebar reference emitted by the generated front-page template with a core-supported area and semantic aside wrapper.');
$positionedSvg = (new HtmlTransformer())->transform('<style>.hero-media{position:relative;width:1280px;height:760px}@media(max-width:700px){.hero-media{width:320px;height:240px}}</style><main><div class="hero-media"><svg class="hero-art" width="100%" height="100%" style="object-fit:cover" viewBox="0 0 1280 728.88"><rect width="1280" height="728.88" fill="#111"/></svg></div></main>')->toArray();
$positionedSvgMarkup = (string) ($positionedSvg['serialized_blocks'] ?? '');
$positionedSvgCss = implode("\n", array_map(static fn(array $asset): string => 'css' === ($asset['kind'] ?? '') ? (string) ($asset['content'] ?? '') : '', $positionedSvg['assets'] ?? array()));
$positionedSvgId = wp_insert_post(array('post_type' => 'page', 'post_status' => 'draft', 'post_title' => 'Positioned SVG', 'post_content' => serialize_blocks(parse_blocks($positionedSvgMarkup))), true);
if (is_wp_error($positionedSvgId)) throw new RuntimeException($positionedSvgId->get_error_message());
$pageIds['positioned-svg'] = $positionedSvgId;
$positionedSvgSaved = (string) get_post_field('post_content', $positionedSvgId);
$assert(str_contains($positionedSvgCss, '.wp-block-image.be-inline-geometry-') && str_contains($positionedSvgCss, '>img{width:100%;height:100%;-o-object-fit:cover;object-fit:cover}') && str_contains($positionedSvgSaved, 'wp-block-image hero-art be-inline-geometry-') && 'core/image' === (parse_blocks($positionedSvgSaved)[0]['innerBlocks'][0]['blockName'] ?? null), 'WordPress parses and saves positioned SVG fill as a native core/image while its desktop/mobile parent-fill CSS defeats core intrinsic-image sizing.');
$styledSymbolSvg = (new HtmlTransformer())->transform('<main><svg viewBox="0 0 24 24" role="img" aria-label="Valve"><defs><style>.icon [data-color="valve"]{fill:#e94560}</style><symbol id="valve"><path data-color="valve" fill="#e94560" d="M0 0h12v12H0z"></path></symbol></defs><use href="#valve" x="6" y="6"></use></svg></main>')->toArray();
$styledSymbolSvgMarkup = (string) ($styledSymbolSvg['serialized_blocks'] ?? '');
$styledSymbolSvgSaved = serialize_blocks(parse_blocks($styledSymbolSvgMarkup));
$styledSymbolSvgRendered = do_blocks($styledSymbolSvgSaved);
$assert('core/image' === (parse_blocks($styledSymbolSvgSaved)[0]['blockName'] ?? null) && !str_contains($styledSymbolSvgSaved, '<!-- wp:html') && str_contains($styledSymbolSvgRendered, '<img') && str_contains($styledSymbolSvgRendered, 'materialized-svg') && str_contains((string) ($styledSymbolSvg['assets'][0]['content'] ?? ''), '<symbol id="valve">') && !str_contains((string) ($styledSymbolSvg['assets'][0]['content'] ?? ''), '<style'), 'WordPress parses, serializes, and renders a sanitized nested-symbol SVG as a visible editable core/image asset.');
$responsiveArtifact = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array('index.html' => '<main><picture><source media="(min-width: 800px)" srcset="assets/hero-large.jpg 1200w" sizes="100vw"><img src="assets/hero.jpg" srcset="assets/hero.jpg 600w, assets/hero-2x.jpg 1200w" sizes="(max-width: 799px) 100vw, 50vw" alt="Hero"></picture><div class="gallery"><figure><img src="assets/gallery-one.jpg" srcset="assets/gallery-one-2x.jpg 2x" alt="Gallery one"></figure><figure><img src="assets/gallery-two.jpg" alt="Gallery two"></figure></div></main>', 'assets/hero.jpg' => 'hero', 'assets/hero-2x.jpg' => 'hero-2x', 'assets/hero-large.jpg' => 'hero-large', 'assets/gallery-one.jpg' => 'gallery-one', 'assets/gallery-one-2x.jpg' => 'gallery-one-2x', 'assets/gallery-two.jpg' => 'gallery-two')))->toArray();
$responsivePlan = $responsiveArtifact['source_reports']['wordpress_site_plan'] ?? array();
$responsiveResolved = (new WordPressSitePlanResolver())->resolve($responsivePlan, array('theme_uri' => home_url('/wp-content/themes/' . $theme)));
$responsiveMarkup = (string) ($responsiveResolved['pages'][0]['resolved_block_markup'] ?? '');
$editorUserId = wp_insert_user(array('user_login' => 'blocks-engine-editor-' . wp_generate_password(8, false), 'user_pass' => wp_generate_password(24), 'role' => 'administrator'));
if (is_wp_error($editorUserId)) throw new RuntimeException($editorUserId->get_error_message());
wp_set_current_user($editorUserId);
$responsiveId = wp_insert_post(array('post_type' => 'page', 'post_status' => 'draft', 'post_title' => 'Responsive fallback', 'post_content' => wp_slash($responsiveMarkup)), true);
if (is_wp_error($responsiveId)) throw new RuntimeException($responsiveId->get_error_message());
$pageIds['responsive-fallback'] = $responsiveId;
$responsiveSaved = (string) get_post_field('post_content', $responsiveId);
$responsiveBase = home_url('/wp-content/themes/' . $theme . '/assets/assets/');
$responsiveBlocks = parse_blocks($responsiveSaved);
$responsivePicture = $responsiveBlocks[0]['innerBlocks'][0] ?? array();
$responsiveGallery = $responsiveBlocks[0]['innerBlocks'][1] ?? array();
$responsiveContent = (string) (($responsivePicture['attrs']['content'] ?? '') . ($responsiveGallery['attrs']['content'] ?? ''));
$assert('core/group' === ($responsiveBlocks[0]['blockName'] ?? null) && str_starts_with((string) ($responsivePicture['blockName'] ?? ''), 'custom/') && str_starts_with((string) ($responsiveGallery['blockName'] ?? ''), 'custom/') && str_contains($responsiveContent, '<picture>') && str_contains($responsiveContent, 'media="(min-width: 800px)"') && str_contains($responsiveContent, $responsiveBase . 'hero-large.jpg 1200w') && str_contains($responsiveContent, $responsiveBase . 'hero.jpg 600w, ' . $responsiveBase . 'hero-2x.jpg 1200w') && str_contains($responsiveContent, 'sizes="(max-width: 799px) 100vw, 50vw"') && str_contains($responsiveContent, $responsiveBase . 'gallery-one-2x.jpg 2x') && str_contains($responsiveContent, 'class="gallery"') && !str_contains($responsiveSaved, '<!-- wp:html') && !str_contains($responsiveSaved, '<!-- wp:gallery'), 'WordPress persists, reloads, and parses materialized picture source selection and mixed responsive gallery markup through generated companion blocks.');
$videoMarkup = (string) ((new HtmlTransformer())->transform('<wix-video><video src="hero.mp4" poster="hero.jpg" autoplay loop muted playsinline controls><track kind="captions" src="captions.vtt" srclang="en" label="English" default></video></wix-video>')->toArray()['serialized_blocks'] ?? '');
$videoSaved = serialize_blocks(parse_blocks($videoMarkup));
$videoRendered = do_blocks($videoSaved);
$videoBlock = parse_blocks($videoSaved)[0] ?? array();
$assert('core/video' === ($videoBlock['blockName'] ?? null) && ! str_contains($videoSaved, '"playsInline":true') && array(array( 'kind' => 'captions', 'src' => 'captions.vtt', 'srcLang' => 'en', 'label' => 'English', 'default' => true )) === ($videoBlock['attrs']['tracks'] ?? null) && str_contains($videoRendered, '<video src="hero.mp4" poster="hero.jpg" controls="controls" autoplay="autoplay" loop="loop" muted="muted" playsinline="playsinline"><track kind="captions" src="captions.vtt" srclang="en" label="English" default="default"></video>'), 'WordPress persists a custom-host native video as editable core/video markup with poster, tracks, and playback metadata.');
$stylableButton = (new HtmlTransformer())->transform('<style>.wix-label{padding:12px 20px;background:#173b64;border-radius:6px;color:#fff}</style><a class="wix-button" href="mailto:hello@example.com" target="_blank" rel="noopener external" aria-label="Email us"><span class="wix-label"><span>Email us</span></span><svg aria-hidden="true"><g><path d="M0 0h1v1z"/></g></svg></a>')->toArray();
$stylableButtonMarkup = (string) ($stylableButton['serialized_blocks'] ?? '');
$stylableButtonId = wp_insert_post(array('post_type' => 'page', 'post_status' => 'draft', 'post_title' => 'Stylable button', 'post_content' => serialize_blocks(parse_blocks($stylableButtonMarkup))), true);
if (is_wp_error($stylableButtonId)) throw new RuntimeException($stylableButtonId->get_error_message());
$pageIds['stylable-button'] = $stylableButtonId;
$stylableButtonSaved = (string) get_post_field('post_content', $stylableButtonId);
$stylableButtonBlocks = parse_blocks($stylableButtonSaved);
$stylableButtonAttrs = $stylableButtonBlocks[0]['innerBlocks'][0]['attrs'] ?? array();
// parse_blocks() retains core/button source attributes in its saved inner HTML;
// only comment-backed attributes such as className appear in attrs.
$stylableButtonBlocks[0]['innerBlocks'][0]['innerContent'][0] = str_replace(
    array( 'Email us', 'mailto:hello@example.com' ),
    array( 'Write to us', 'tel:+15551234567' ),
    (string) ($stylableButtonBlocks[0]['innerBlocks'][0]['innerContent'][0] ?? '')
);
$stylableButtonEdited = serialize_blocks($stylableButtonBlocks);
wp_update_post(array('ID' => $stylableButtonId, 'post_content' => wp_slash($stylableButtonEdited)));
$stylableButtonReloaded = (string) get_post_field('post_content', $stylableButtonId);
$assert('core/button' === ($stylableButtonBlocks[0]['innerBlocks'][0]['blockName'] ?? null) && str_contains((string) ($stylableButtonAttrs['className'] ?? ''), 'wix-button') && !isset($stylableButtonAttrs['ariaLabel']) && !str_contains($stylableButtonSaved, 'aria-label=') && str_contains($stylableButtonSaved, 'href="mailto:hello@example.com"') && str_contains($stylableButtonSaved, 'target="_blank"') && str_contains($stylableButtonSaved, 'rel="noopener external"') && str_contains($stylableButtonSaved, 'materialized-svg') && str_contains($stylableButtonReloaded, 'Write to us') && str_contains($stylableButtonReloaded, 'tel:+15551234567'), 'WordPress parses, serializes, and persists core/button source markup for nested-label SVG label and destination edits without an HTML fallback.');
$fixture87Styles = '.gallery .photo{min-height:var(--h)}.gallery .photo::before{content:"";display:block;height:100%;background:linear-gradient(135deg,var(--a),var(--b))}.tour-card{background:linear-gradient(135deg,var(--tone),#fff)}';
$fixture87 = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array('index.html' => '<link rel="stylesheet" href="assets/site.css"><main><div class="gallery"><figure class="photo" style="--h:280px;--a:#27485f;--b:#87d8ff"></figure></div><div class="tour-card" style="--tone:#315b74;border-color:#d8dee9;border-width:1px;border-style:solid;border-radius:16px;padding:1.2rem;min-height:430px">Card</div></main>', 'assets/site.css' => $fixture87Styles)))->toArray();
$fixture87Saved = serialize_blocks(parse_blocks((string) ($fixture87['serialized_blocks'] ?? '')));
$fixture87Css = implode("\n", array_map(static fn(array $asset): string => 'css' === ($asset['kind'] ?? '') ? (string) ($asset['content'] ?? '') : '', $fixture87['assets'] ?? array()));
$assert(!str_contains($fixture87Saved, '--h:') && !str_contains($fixture87Saved, '--tone:') && !str_contains($fixture87Saved, 'min-height:430px') && str_contains($fixture87Saved, 'min-height:var(--h)') && str_contains($fixture87Saved, 'border-color:#d8dee9;border-style:solid;border-width:1px;border-radius:16px;padding-top:1.2rem;padding-right:1.2rem;padding-bottom:1.2rem;padding-left:1.2rem') && str_contains($fixture87Css, '--h:280px !important;--a:#27485f !important;--b:#87d8ff !important') && str_contains($fixture87Css, '--tone:#315b74 !important') && str_contains($fixture87Css, 'min-height:430px !important'), 'WordPress parse/save retains supported fixture87 styles while generated carrier CSS preserves unsupported dimensions and custom-property paint.');
$pageDeclarations = array(); foreach ($resolved['pages'] as $page) $pageDeclarations[$page['source_path']] = $page;
$pagesBySource = array(); foreach ($resolved['operations'] as $operation) if ('create_page' === $operation['kind']) { $page = $pageDeclarations[$operation['source_path']] ?? null; if (!is_array($page) || ($operation['post_type'] ?? $page['post_type']) !== $page['post_type']) throw new RuntimeException('Create operation lacks an authoritative post type.'); $id = wp_insert_post(array('post_type' => $page['post_type'], 'post_status' => 'publish', 'post_title' => $page['title'], 'post_name' => $operation['slug'], 'post_parent' => 'page' === $page['post_type'] && '' !== $operation['parent_source_path'] ? ($pagesBySource[$operation['parent_source_path']] ?? 0) : 0, 'post_content' => $page['resolved_block_markup']), true); if (is_wp_error($id)) throw new RuntimeException($id->get_error_message()); update_post_meta($id, '_blocks_engine_reconciliation_identity', $page['reconciliation_identity']); $pageIds[$operation['reconciliation_identity']] = $id; $pagesBySource[$operation['source_path']] = $id; }
foreach ($resolved['operations'] as $operation) if ('site_reading' === $operation['kind']) { update_option('show_on_front', $operation['show_on_front']); update_option('page_on_front', $pageIds[$operation['front_page_reconciliation_identity']]); }
$readingOperation = array_values(array_filter($resolved['operations'], static fn(array $operation): bool => 'site_reading' === $operation['kind']))[0] ?? array();
$assert('page' === get_option('show_on_front') && $pageIds[$readingOperation['front_page_reconciliation_identity']] === (int) get_option('page_on_front'), 'WordPress applies the declared topological page and front-page operations without manual hierarchy mutation.');
$essay = get_post($pagesBySource['notes/essay.html'] ?? 0); $essayPlan = $pageDeclarations['notes/essay.html'] ?? array();
$assert($essay && 'post' === $essay->post_type && 0 === (int) $essay->post_parent && ($essayPlan['reconciliation_identity'] ?? null) === get_post_meta($essay->ID, '_blocks_engine_reconciliation_identity', true), 'Reference materialization honors operation post_type, keeps posts parentless, and persists the runtime reconciliation identity.');
$frontPage = get_post((int) get_option('page_on_front')); if (!$frontPage) throw new RuntimeException('Could not load front page.');
$editorStyles = static function (WP_Post $post): array { return get_block_editor_settings(array(), new WP_Block_Editor_Context(array('name' => 'core/edit-post', 'post' => $post)))['styles'] ?? array(); };
$frontEditorStyles = $editorStyles($frontPage);
$frontEditorCss = implode("\n", array_map(static fn(array $style): string => (string) ($style['css'] ?? ''), $frontEditorStyles));
$nestedAbout = get_post($pagesBySource['nested/about.html']); if (!$nestedAbout) throw new RuntimeException('Could not load nested about page.');
$aboutEditorStyles = $editorStyles($nestedAbout);
$aboutEditorCss = implode("\n", array_map(static fn(array $style): string => (string) ($style['css'] ?? ''), $aboutEditorStyles));
$presentationGlobalStyle = current(array_filter($frontEditorStyles, static fn(array $style): bool => true === ($style['isGlobalStyles'] ?? false) && 'user' === ($style['__unstableType'] ?? null) && str_contains((string) ($style['css'] ?? ''), 'blocks-engine-presentation:')));
$post = $frontPage;
$frontThemeJson = apply_filters('wp_theme_json_data_theme', new WP_Theme_JSON_Data(array('version' => 3), 'theme'))->get_data();
$frontThemeJsonCss = (string) ($frontThemeJson['styles']['css'] ?? '');
$post = $nestedAbout;
$aboutThemeJson = apply_filters('wp_theme_json_data_theme', new WP_Theme_JSON_Data(array('version' => 3), 'theme'))->get_data();
$aboutThemeJsonCss = (string) ($aboutThemeJson['styles']['css'] ?? '');
$assert(str_contains($frontEditorCss, '.global-presentation{display:block}') && str_contains($frontEditorCss, '.home-owned{color:#123456}') && !str_contains($frontEditorCss, '.about-owned{color:#654321}') && str_contains($frontEditorCss, 'blocks-engine-presentation:'), 'Front-page editor receives global and front-page presentation assets with content-addressed evidence.');
$assert(str_contains($aboutEditorCss, '.global-presentation{display:block}') && str_contains($aboutEditorCss, '.about-owned{color:#654321}') && !str_contains($aboutEditorCss, '.home-owned{color:#123456}') && str_contains($aboutEditorCss, 'blocks-engine-presentation:'), 'Nested-page editor receives global and route-owned presentation assets while excluding unrelated route CSS.');
$assert(false !== $presentationGlobalStyle, 'Generated presentation CSS is merged into the user Global Styles bucket that Gutenberg emits after core layout rules.');
$assert(str_contains($frontThemeJsonCss, '.home-owned{color:#123456}') && !str_contains($frontThemeJsonCss, '.about-owned{color:#654321}') && str_contains($aboutThemeJsonCss, '.about-owned{color:#654321}') && !str_contains($aboutThemeJsonCss, '.home-owned{color:#123456}'), 'Theme JSON receives only the generated presentation CSS owned by the edited route.');
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
$indexTemplate = file_get_contents($themeDir . '/templates/index.html'); if (false === $indexTemplate) throw new RuntimeException('Could not read index template.');
$queryPostIds = array();
foreach (array('Query Loop First', 'Query Loop Second') as $title) {
    $queryPostId = wp_insert_post(array('post_type' => 'post', 'post_status' => 'publish', 'post_title' => $title, 'post_content' => '<!-- wp:paragraph --><p>' . $title . ' excerpt.</p><!-- /wp:paragraph -->'), true);
    if (is_wp_error($queryPostId)) throw new RuntimeException($queryPostId->get_error_message());
    $queryPostIds[] = $queryPostId;
    $pageIds['query-loop-' . $queryPostId] = $queryPostId;
}
$indexBlocks = parse_blocks($indexTemplate);
$indexQuery = array_values(array_filter($indexBlocks, static fn(array $block): bool => 'core/query' === ($block['blockName'] ?? null)))[0] ?? array();
$indexPostTemplate = $indexQuery['innerBlocks'][0] ?? array();
$previousQuery = $wp_query;
$wp_query = new WP_Query(array('post_type' => 'post', 'post__in' => $queryPostIds, 'orderby' => 'post__in', 'posts_per_page' => 10));
$indexRendered = do_blocks($indexTemplate);
wp_reset_postdata();
$wp_query = $previousQuery;
$assert('core/query' === ($indexQuery['blockName'] ?? null) && 10 === ($indexQuery['attrs']['query']['perPage'] ?? null) && true === ($indexQuery['attrs']['query']['inherit'] ?? null) && 'core/post-template' === ($indexPostTemplate['blockName'] ?? null) && $indexTemplate === serialize_blocks($indexBlocks) && str_contains($indexRendered, 'Query Loop First') && str_contains($indexRendered, 'Query Loop Second') && 2 === substr_count($indexRendered, 'wp-block-post ') && !str_contains($indexRendered, 'No posts found.'), 'WordPress parses, serializes, and renders the generated index Query Loop once per inherited post without its no-results fallback.');
fwrite(STDOUT, "wordpress-site-plan WordPress integration passed\n");
} finally {
    foreach ($pageIds as $id) wp_delete_post((int) $id, true);
    wp_set_current_user($previousUserId);
    if ($editorUserId > 0) {
        if (!function_exists('wp_delete_user')) require_once ABSPATH . 'wp-admin/includes/user.php';
        wp_delete_user($editorUserId);
    }
    update_option('show_on_front', $previousShowOnFront); update_option('page_on_front', $previousPageOnFront);
    if ('' !== $previousTheme) switch_theme($previousTheme);
    wp_clean_themes_cache();
    if (is_dir($themeDir)) { $items = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($themeDir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST); foreach ($items as $item) $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname()); rmdir($themeDir); }
    wp_clean_themes_cache();
}
