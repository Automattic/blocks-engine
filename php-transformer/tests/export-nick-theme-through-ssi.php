<?php

use Automattic\BlocksEngine\PhpTransformer\Path\ArtifactPath;

$engineRoot = (string) (getenv('BLOCKS_ENGINE_PHP_TRANSFORMER_ROOT') ?: dirname(__DIR__));
require_once WP_PLUGIN_DIR . '/static-site-importer/vendor/autoload.php';
foreach (Composer\Autoload\ClassLoader::getRegisteredLoaders() as $loader) {
    $loader->setPsr4('Automattic\\BlocksEngine\\PhpTransformer\\', rtrim($engineRoot, '/') . '/src/', true);
}
require_once WP_PLUGIN_DIR . '/static-site-importer/static-site-importer.php';

$themeSlug = 'nick-theme-restoration';
$pageId = (int) get_option('ssi_nick_theme_source_page_id', 0);
$declaration = get_option('ssi_nick_theme_preference_ownership', array());
if (0 === $pageId || !is_array($declaration) || 'theme_control' !== ($declaration['kind'] ?? null)) {
    throw new RuntimeException('The prior SSI import did not persist its canonical theme ownership declaration and page id.');
}
$exportDeclaration = $declaration;
$exportDeclaration['source_path'] = 'website/index.html';
$exportDeclaration['payload']['ownership']['source_path'] = 'website/index.html';
unset($exportDeclaration['reconciliation_identity'], $exportDeclaration['payload_hash'], $exportDeclaration['content_hash']);
$registry = class_exists('WP_Block_Type_Registry') ? WP_Block_Type_Registry::get_instance() : null;
$registeredBlockName = '';
$registeredBlock = null;
if ($registry) {
    foreach ($registry->get_all_registered() as $name => $type) {
        if (str_ends_with((string) $name, '/theme-toggle')) {
            $registeredBlockName = (string) $name;
            $registeredBlock = $type;
        }
    }
}
$post = get_post($pageId);
if (!$post instanceof WP_Post) throw new RuntimeException('The imported Nick source page is unavailable to the fresh export request.');
$frontMarkup = apply_filters('the_content', $post->post_content);
$export = Static_Site_Importer_Theme_Exporter::export_theme(array(
    'theme_slug' => $themeSlug,
    'entrypoint' => 'website/index.html',
    'include_pages' => array($pageId),
    'source_metadata' => array('runtime_declarations' => array($exportDeclaration)),
));
if (is_wp_error($export)) throw new RuntimeException($export->get_error_code() . ': ' . $export->get_error_message());
$artifact = $export['website_artifact'] ?? null;
if (!is_array($artifact)) throw new RuntimeException('SSI theme export returned no website artifact.');
file_put_contents(get_theme_root() . '/' . $themeSlug . '/ssi-exported-artifact.json', wp_json_encode($artifact, JSON_UNESCAPED_SLASHES));
$artifact['runtime_declarations'] = array($exportDeclaration);
$owner = $declaration['payload']['ownership'] ?? array();
if ('' === $registeredBlockName) throw new RuntimeException('The generated theme-toggle block is not registered in the fresh export request.');
$exportedDocuments = array_values(array_filter($artifact['files'] ?? array(), static fn (array $file): bool => 'html' === ($file['kind'] ?? '') || str_ends_with((string) ($file['path'] ?? ''), '.html')));
$exportedHtml = implode("\n", array_map(static fn (array $file): string => (string) ($file['content'] ?? ''), $exportedDocuments));
$document = new DOMDocument();
@$document->loadHTML($exportedHtml, LIBXML_NONET | LIBXML_NOWARNING | LIBXML_NOERROR);
$selector = (string) ($declaration['payload']['ownership']['group_selector'] ?? '');
$parsedAnchor = '' !== $selector ? Automattic\BlocksEngine\PhpTransformer\Css\CssSelectorMatcher::parse($selector) : array('supported' => false);
$anchorMatches = array();
foreach ($document->getElementsByTagName('*') as $candidate) {
    if (!$candidate instanceof DOMElement) continue;
    $match = Automattic\BlocksEngine\PhpTransformer\Css\CssSelectorMatcher::matches($candidate, $parsedAnchor);
    if (($match['supported'] ?? false) && ($match['matches'] ?? false)) $anchorMatches[] = array('tag' => $candidate->tagName, 'class' => $candidate->getAttribute('class'), 'children' => $candidate->childElementCount);
}
$controlGroups = array();
foreach ($document->getElementsByTagName('div') as $candidate) {
    if (3 !== $candidate->childElementCount) continue;
    $controls = array();
    foreach ($candidate->childNodes as $child) {
        if (!$child instanceof DOMElement || 'button' !== strtolower($child->tagName)) { $controls = array(); break; }
        $svg = null;
        foreach ($child->childNodes as $icon) if ($icon instanceof DOMElement && 'svg' === strtolower($icon->tagName)) { $svg = $icon; break; }
        $controls[] = array('label' => $child->getAttribute('aria-label'), 'class' => $child->getAttribute('class'), 'icon' => $svg?->getAttribute('class'));
    }
    if (3 === count($controls) && array_column($controls, 'label') === array('Light theme', 'System theme', 'Dark theme')) $controlGroups[] = array('class' => $candidate->getAttribute('class'), 'markup' => $candidate->ownerDocument->saveHTML($candidate), 'controls' => $controls);
}
$compile = (new Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler())->compile($artifact);
$compiledTheme = $compile->sourceReports['compiled_site']['theme'] ?? array();
$activeThemeStylesheets = $compiledTheme['stylesheets'] ?? array();
$activeThemeCss = (string) ($compiledTheme['static_css'] ?? '');
$exportStylesheetLinks = array();
foreach ($document->getElementsByTagName('link') as $link) {
    if ($link instanceof DOMElement && preg_match('/(?:^|\s)stylesheet(?:\s|$)/i', $link->getAttribute('rel'))) $exportStylesheetLinks[] = $link->getAttribute('href');
}
$linkedStylesheetAssetPaths = array_values(array_filter(array_map(
    static fn (string $href): string => ArtifactPath::resolveRelativePath($href, (string) ($artifact['entrypoint'] ?? '')),
    $exportStylesheetLinks
)));
$ownerStylesheet = current($owner['stylesheet_evidence'] ?? array()) ?: array();
$ownerAssetPath = ArtifactPath::safeRelativePath(dirname((string) ($artifact['entrypoint'] ?? '')) . '/assets/' . (string) ($ownerStylesheet['path'] ?? ''));
$ownerStylesheetLinked = array_filter($exportStylesheetLinks, static function (string $href) use ($ownerStylesheet): bool {
    $path = parse_url($href, PHP_URL_PATH);
    return is_string($path) && str_ends_with(ltrim($path, '/'), (string) ($ownerStylesheet['path'] ?? ''));
});
$ownerCss = (string) ($ownerStylesheet['content'] ?? '');
preg_match('/\.mt-8\{[^}]+\}/', $ownerCss, $nonControlRuleMatch);
$nonControlRule = $nonControlRuleMatch[0] ?? '';
$activeThemeStylesheetAssets = array_values(array_filter($compile->assets, static fn (array $asset): bool => in_array($asset['path'] ?? null, $activeThemeStylesheets, true)));
$nonControlRuleInActiveThemeCss = array_filter($activeThemeStylesheetAssets, static fn (array $asset): bool => '' !== $nonControlRule && str_contains((string) ($asset['content'] ?? ''), $nonControlRule));
$footer = $document->getElementsByTagName('footer')->item(0);
$footerHasUnrelatedGeometryClass = $footer instanceof DOMElement && in_array('mt-8', preg_split('/\s+/', trim($footer->getAttribute('class'))) ?: array(), true);
$footerOutsideControlGroup = $footerHasUnrelatedGeometryClass && 0 === count(array_filter($anchorMatches, static fn (array $anchor): bool => 'footer' === ($anchor['tag'] ?? null)));
if ('' === $ownerAssetPath || !in_array($ownerAssetPath, array_column($artifact['files'] ?? array(), 'path'), true)
    || array() !== $ownerStylesheetLinked || in_array($ownerAssetPath, $activeThemeStylesheets, true)
    || array_diff($linkedStylesheetAssetPaths, $activeThemeStylesheets) !== array()
    || '' === $nonControlRule || !$footerOutsideControlGroup || str_contains($activeThemeCss, $nonControlRule)
    || array() !== $nonControlRuleInActiveThemeCss) {
    throw new RuntimeException('Detached owner CSS escaped provenance scope: the actual footer .mt-8 rule must remain an unlinked evidence asset, not active cascade input.');
}
$find = static function (array $blocks) use (&$find): array {
    foreach ($blocks as $block) {
        if (is_array($block) && str_ends_with((string) ($block['blockName'] ?? ''), '/theme-toggle')) return $block;
        $nested = is_array($block['innerBlocks'] ?? null) ? $find($block['innerBlocks']) : array();
        if (array() !== $nested) return $nested;
    }
    return array();
};
$restoredBlock = $find($compile->blocks);
$companion = $compile->toWordPressSitePlanView()['companion_plugin_payload']['blocks'] ?? array();
$artifact['runtime_declarations'] = array($exportDeclaration);
$reimport = Static_Site_Importer_Theme_Generator::import_website_artifact($artifact, array(
    'slug' => 'nick-theme-restoration-reimport',
    'name' => 'Nick Diego Reimport',
    'url' => 'https://nickdiego.com',
    'theme_materialization' => 'block',
    'require_proven_dynamic_client_assets' => false,
    'overwrite' => true,
));
if (is_wp_error($reimport)) throw new RuntimeException($reimport->get_error_code() . ': ' . $reimport->get_error_message());
$reimportPlan = $reimport['materialization_receipt']['plan'] ?? array();
$reimportTemplateParts = array();
foreach ($reimportPlan['template_parts'] ?? array() as $part) {
    if (!is_array($part)) continue;
    $markup = (string) ($part['canonical_block_markup'] ?? '');
    $reimportTemplateParts[] = array('slug' => $part['slug'] ?? null, 'source_path' => $part['source_path'] ?? null, 'has_theme_block' => str_contains($markup, '/theme-toggle'), 'canonical_bytes' => strlen($markup));
}
$reimportPageIds = array_values($reimport['pages'] ?? array());
$reimportPages = array();
foreach ($reimportPageIds as $pageId) {
    $page = get_post((int) $pageId);
    if (!$page instanceof WP_Post) continue;
    $reimportPages[] = array('id' => (int) $page->ID, 'title' => (string) $page->post_title, 'has_theme_block' => str_contains((string) $page->post_content, '/theme-toggle'), 'content_bytes' => strlen((string) $page->post_content), 'content' => (string) $page->post_content);
}
$reimportDeclaration = current(array_filter($reimport['materialization_receipt']['plan']['runtime_declarations'] ?? array(), static fn (array $row): bool => 'theme_control' === ($row['kind'] ?? null))) ?: array();
$diagnostic = array(
    'fresh_request' => true,
    'registered_theme_toggle_block_name' => $registeredBlockName,
    'registered_before_export' => '' !== $registeredBlockName,
    'registered_render_callback' => is_object($registeredBlock) && is_callable($registeredBlock->render_callback ?? null),
    'page_id' => $pageId,
    'post_content_bytes' => strlen((string) $post->post_content),
    'frontend_markup_bytes' => strlen((string) $frontMarkup),
    'frontend_has_three_names' => str_contains((string) $frontMarkup, 'Light theme') && str_contains((string) $frontMarkup, 'System theme') && str_contains((string) $frontMarkup, 'Dark theme'),
    'registered_block_name' => $registeredBlockName,
    'export_schema' => $artifact['schema'] ?? null,
    'export_entrypoint' => $artifact['entrypoint'] ?? null,
    'export_files' => array_map(static fn (array $file): array => array('path' => $file['path'] ?? '', 'kind' => $file['kind'] ?? '', 'bytes' => strlen((string) ($file['content'] ?? ''))), $artifact['files'] ?? array()),
    'export_has_three_names' => str_contains($exportedHtml, 'Light theme') && str_contains($exportedHtml, 'System theme') && str_contains($exportedHtml, 'Dark theme'),
    'export_group_class_present' => str_contains($exportedHtml, 'transition-opacity duration-200'),
    'export_original_anchor_match_count' => count($anchorMatches),
    'export_original_anchor_matches' => $anchorMatches,
    'export_control_groups' => $controlGroups,
    'export_stylesheet_links' => $exportStylesheetLinks,
    'exported_document_prefix' => substr($exportedHtml, 0, 2500),
    'detached_owner_stylesheet' => array('path' => $ownerAssetPath, 'source_path' => $ownerStylesheet['path'] ?? null, 'sha256' => $ownerStylesheet['sha256'] ?? null, 'asset_sha256' => current(array_filter($artifact['files'] ?? array(), static fn (array $file): bool => $ownerAssetPath === ($file['path'] ?? null)))['sha256'] ?? null, 'retained_as_asset' => in_array($ownerAssetPath, array_column($artifact['files'] ?? array(), 'path'), true), 'linked_by_export_html' => array() !== $ownerStylesheetLinked, 'active_in_theme_stylesheets' => in_array($ownerAssetPath, $activeThemeStylesheets, true)),
    'noncontrol_css_scope_probe' => array('selector' => '.mt-8', 'rule' => $nonControlRule, 'matches_export_footer_outside_control_group' => $footerOutsideControlGroup, 'active_theme_css_contains_rule' => str_contains($activeThemeCss, $nonControlRule), 'active_asset_paths_with_rule' => array_column($nonControlRuleInActiveThemeCss, 'path')),
    'linked_stylesheet_asset_paths' => $linkedStylesheetAssetPaths,
    'active_theme_stylesheet_paths' => $activeThemeStylesheets,
    'export_metadata_runtime_hash' => $artifact['provenance']['source_metadata']['runtime_declarations'][0]['payload']['ownership']['runtime_script_sha256'] ?? null,
    'reimport_plan_runtime_hash' => $compile->toWordPressSitePlanView()['wordpress_site_plan']['runtime_declarations'][0]['payload']['ownership']['runtime_script_sha256'] ?? null,
    'reimport_block_name' => $restoredBlock['blockName'] ?? null,
    'reimport_block_fallbacks' => count($compile->fallbacks),
    'reimport_companion_blocks' => array_column($companion, 'name'),
    'ssi_reimport' => array(
        'status' => $reimport['materialization_receipt']['status'] ?? null,
        'theme_slug' => $reimport['theme_slug'] ?? '',
        'page_ids' => $reimportPageIds,
        'pages' => $reimportPages,
        'template_parts' => $reimportTemplateParts,
        'runtime_script_sha256' => $reimportDeclaration['payload']['ownership']['runtime_script_sha256'] ?? null,
        'companion_block_names' => $reimport['materialization_receipt']['completed']['companion_plugin']['block_names'] ?? array(),
    ),
    'reimport_fallback_count' => count($compile->fallbacks),
    'qualified_runtime_path' => $owner['runtime_script_path'] ?? null,
    'qualified_runtime_sha256' => $owner['runtime_script_sha256'] ?? null,
);
file_put_contents(get_theme_root() . '/' . $themeSlug . '/ssi-export-reimport-diagnostic.json', wp_json_encode($diagnostic, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
echo wp_json_encode($diagnostic, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
