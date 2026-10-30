<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\Css\CssStylesheetTransformer;

$assert = static function (bool $condition, string $message): void {
    if (! $condition) throw new RuntimeException($message);
};

$css = '.header-grid{display:grid;grid-template-columns:min-content auto repeat(2,min-content)}'
    . '.cta a{white-space:nowrap;background:#234;color:#fff;padding:12px 24px;display:inline-block;border-radius:4px}.title{white-space:pre!important}.summary{white-space:nowrap}'
    . '.items li{white-space:nowrap}.wrapping{white-space:normal}.preserved{white-space:pre-wrap}';
$html = '<!doctype html><html><head><title>Layout</title><link rel="stylesheet" href="site.css"></head><body>'
    . '<main><div class="header-grid"><div class="cta"><a class="button" href="next.html">Explore the collection</a></div>'
    . '<h1 class="title">Collection title</h1><p class="summary">Collection summary</p>'
    . '<ul class="items"><li>First item</li></ul></div><p class="wrapping">Wrapping text</p>'
    . '<p class="preserved">Preserved text</p></main></body></html>';
$plan = (new ArtifactCompiler())->compile(array(
    'entrypoint' => 'index.html',
    'files' => array('index.html' => $html, 'site.css' => $css),
))->toWordPressSitePlanView()['wordpress_site_plan'];

$assets = array();
foreach ($plan['assets'] as $asset) {
    if ('css' === ($asset['kind'] ?? null)) $assets[$asset['target_path']] = (string) $asset['content'];
}
// One stylesheet serves both contexts; the override is reachable only
// through the editor canvas scope.
$editor = $assets['assets/site.css'] ?? '';
$assert(! isset($assets['assets/site.editor.css']), 'No editor copy of the stylesheet is emitted.');
$frontend = '';
(new CssStylesheetTransformer())->visitStyleRules($editor, static function (string $selector, string $body) use (&$frontend): void {
    if (! str_contains($selector, 'editor-styles-wrapper')) $frontend .= $selector . '{' . $body . '}';
});
$assert('' !== $frontend, 'The stylesheet must be emitted.');
$assert(! str_contains($frontend, 'text-wrap-mode'), 'Rules outside the editor canvas must not receive the editor wrapping override.');
$assert(str_contains($frontend, 'white-space:nowrap') && str_contains($frontend, 'white-space:pre!important'), 'Frontend keeps authored whitespace declarations.');
$assert(str_contains($frontend, 'grid-template-columns:min-content auto repeat(2,min-content)'), 'The fixture retains its intrinsic grid tracks.');

$nowrapSelectors = array();
$frontendRules = '';
(new CssStylesheetTransformer())->visitStyleRules($editor, static function (string $selector, string $body) use (&$nowrapSelectors, &$frontendRules, $assert): void {
    if (! str_contains($selector, 'editor-styles-wrapper')) {
        $frontendRules .= $selector . '{' . $body . '}';
    }
    if (str_contains($body, 'text-wrap-mode:nowrap!important')) {
        $assert(str_contains($selector, ':root .editor-styles-wrapper'), 'Wrapping overrides must be editor-scoped.');
        $assert(! str_contains($body, 'white-space:'), 'The override must leave RichText whitespace preservation alone.');
        $nowrapSelectors[] = $selector;
    }
});
$selectors = implode(',', $nowrapSelectors);
foreach (array('title', 'summary', 'items') as $class) {
    $assert(str_contains($selectors, '.' . $class), 'Missing editor nowrap override for ' . $class . '.');
}
$assert(str_contains($selectors, 'wp-block-button__link'), 'The CTA override must target the converted button text.');
$assert(! str_contains($selectors, '.wrapping') && ! str_contains($selectors, '.preserved'), 'Wrapping values must not receive a nowrap override.');
$assert(str_contains(preg_replace('/\s+/', '', $frontend), preg_replace('/\s+/', '', $frontendRules)), 'Removing editor-only rules leaves frontend rules unchanged and in order.');

echo "editor-rich-text-nowrap: ok\n";
