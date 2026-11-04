<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\Css\CssStylesheetTransformer;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

// #4309: keep fixed site chrome in flow per #1788, without page-height stretch.
$html = <<<'HTML'
<!doctype html><html><head><meta charset="utf-8"><title>Home</title><style>
body{margin:0;font-family:sans-serif}
.pin{position:fixed;top:0;left:0;width:100%;height:100%;pointer-events:none;display:grid}
header{pointer-events:auto;height:80px;background:#eee;display:flex;align-items:center;justify-content:space-between;padding:0 20px}
main{padding:120px 20px 40px}.page{display:grid}
</style></head><body><div class="page"><div class="inner">
<div class="pin"><header><a href="/">Acme</a><nav><a href="/">Home</a> <a href="/about/">About</a></nav></header></div>
<main><h1>Welcome home</h1><p>Home page body text.</p></main>
</div></div><footer><p>Footer text</p></footer></body></html>
HTML;
$result = (new HtmlTransformer())->transform($html)->toArray();
$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};
$assert(str_contains($result['serialized_blocks'], 'pin'), 'The overlay wrapper survives as a block.');
$css = implode("\n", array_column(array_filter($result['assets'], static fn (array $asset): bool => 'css' === ($asset['kind'] ?? '')), 'content'));
$editorHeight = false;
$frontendFixed = false;
(new CssStylesheetTransformer())->visitStyleRules($css, static function (string $selector, string $body) use (&$editorHeight, &$frontendFixed, $assert): void {
    $editor = str_contains($selector, '.editor-styles-wrapper');
    $fixed = (bool) preg_match('/(?:^|;)\s*position\s*:\s*fixed\b/i', $body);
    $assert(!$editor || !$fixed, 'No editor rule may re-force fixed site chrome over the document.');
    if (!str_contains($selector, '.pin')) return;
    if ($editor && str_contains($selector, ':root .editor-styles-wrapper') && preg_match('/(?:^|;)height:auto(?:;|$)/', $body)) {
        $editorHeight = true;
    }
    if (!$editor && $fixed && preg_match('/(?:^|;)height:100%(?:;|$)/', $body)) {
        $frontendFixed = true;
    }
});
$assert($editorHeight, 'The editor-scoped pin rule resets full-page height to auto.');
$assert($frontendFixed, 'The frontend author rule retains position:fixed and height:100%.');
// #4363: a fixed layer must overlay the grid start, not auto-place after row 3.
$html = <<<'HTML'
<style>
.page{display:grid;grid-template-rows:auto auto auto}
.pin{position:fixed;top:0;left:0;width:100%;height:100%;pointer-events:none}
.pin header{pointer-events:auto;height:80px}
.sections{display:contents}
.s1{grid-area:1/1/2/2;height:600px}
.s2{grid-area:2/1/3/2;height:600px}
.foot{grid-area:3/1/4/2;height:200px}
</style>
<div class="page">
  <div class="pin"><header><a href="#top">Logo</a><nav><a href="#s2">Two</a></nav></header></div>
  <div class="sections"><section class="s1">One</section><section class="s2">Two</section></div>
  <footer class="foot">Footer</footer>
</div>
HTML;
$result = (new HtmlTransformer())->transform($html)->toArray();
$css = implode("\n", array_column(array_filter($result['assets'], static fn (array $asset): bool => 'css' === ($asset['kind'] ?? '')), 'content'));
$editorPlacement = false;
$frontendUnchanged = false;
(new CssStylesheetTransformer())->visitStyleRules($css, static function (string $selector, string $body) use (&$editorPlacement, &$frontendUnchanged, $assert): void {
    $editor = str_contains($selector, '.editor-styles-wrapper');
    $assert(!$editor || !preg_match('/(?:^|;)\s*position\s*:\s*fixed\b/i', $body), 'No editor rule may set position:fixed.');
    if (!str_contains($selector, '.pin') || str_contains($selector, 'header')) return;
    if ($editor && str_contains($selector, ':root .editor-styles-wrapper')) {
        $editorPlacement = str_contains($body, 'grid-row:1;grid-column:1/-1;align-self:start');
        $assert(!str_contains($body, 'z-index:'), 'Do not invent a stacking level absent from the source.');
    }
    if (!$editor && 'position:fixed;top:0;left:0;width:100%;height:100%;pointer-events:none' === $body) {
        $frontendUnchanged = true;
    }
});
$assert($editorPlacement, 'The editor pin overlays the start of the grid without auto-placement.');
$assert($frontendUnchanged, 'The frontend pin declarations remain unchanged.');
echo "editor-canvas-fixed-position: passed\n";
