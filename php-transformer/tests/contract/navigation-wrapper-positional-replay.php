<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\Css\CssStylesheetTransformer;

$nav = '<div class="nav-wrapper"><nav><div class="collection"><a href="/a">A</a></div><div class="folder"><div class="folder-toggle">B</div><div class="subnav"><div class="collection"><a href="/b1">B1</a></div></div></div><div class="collection"><a href="/c">C</a></div></nav></div>';
$css = '.nav-wrapper nav>div{display:inline-block}.nav-wrapper nav>div>a,.nav-wrapper nav>div .folder-toggle{padding:10px 14px}';
$positions = array('last-child', 'first-child', 'nth-child(2n + 1)');
foreach ($positions as $position) {
    $css .= '.nav-wrapper nav>div:' . $position . '>a,.nav-wrapper nav>div:' . $position . ' .folder-toggle{padding-right:0}';
}
$result = (new ArtifactCompiler())->compile(array('files' => array(
    array('path' => 'index.html', 'kind' => 'html', 'content' => '<!doctype html><html><head><style>' . $css . '</style></head><body><header>' . $nav . '</header><main><p>x</p></main></body></html>'),
)))->toArray();
$seen = array();
foreach ($result['assets'] as $asset) {
    if (!str_ends_with($asset['path'] ?? '', '.css')) continue;
    (new CssStylesheetTransformer())->visitStyleRules($asset['content'], static function (string $selector, string $body) use (&$seen, $positions): void {
        if (!str_contains($body, 'padding-right:0') || !str_contains($selector, 'wp-block-navigation')) return;
        foreach (CssStylesheetTransformer::splitSelectorList($selector) ?? array() as $single) {
            foreach ($positions as $position) {
                if (str_contains($single, '.wp-block-navigation-item:' . $position . ' > .wp-block-navigation-item__content')) {
                    $seen[$position] = true;
                    continue 2;
                }
            }
            throw new RuntimeException('Positional replay escapes its item: ' . $single);
        }
    });
}
foreach ($positions as $position) {
    if (!isset($seen[$position])) throw new RuntimeException('Missing item replay for ' . $position);
}
echo "navigation-wrapper-positional-replay: passed\n";
