<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\Css\CssSelectorMatcher;
use Automattic\BlocksEngine\PhpTransformer\Css\CssStylesheetTransformer;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

// Reduced home page from #4308 / #4309: the full-height grid overlay must
// remain out of flow when Core adds its editor block positioning reset.
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
$css = implode("\n", array_column(array_filter($result['assets'], static fn (array $asset): bool => 'css' === ($asset['kind'] ?? '') && 'after-author' === ($asset['stylesheet_placement'] ?? '')), 'content'));
$core = CssSelectorMatcher::specificity(CssSelectorMatcher::parse('.block-editor-block-list__layout .block-editor-block-list__block'));
$document = new DOMDocument();
$document->loadHTML('<html><body class="editor-styles-wrapper"><div class="block-editor-block-list__layout"><div class="pin block-editor-block-list__block"></div></div></body></html>');
$pin = $document->getElementsByTagName('div')->item(1);
$found = false;
(new CssStylesheetTransformer())->visitStyleRules($css, static function (string $selector, string $body) use (&$found, $assert, $core, $document, $pin): void {
    if (!str_contains($selector, 'editor-styles-wrapper') || !str_contains($selector, '.pin') || !preg_match('/(?:^|;)position:fixed(?:;|$)/', $body)) {
        return;
    }
    $parsed = CssSelectorMatcher::parse($selector);
    $assert(CssSelectorMatcher::specificity($parsed) > $core, 'The fixed-position repair beats Core\'s two-class selector.');
    $assert(CssSelectorMatcher::matches($pin, $parsed)['matches'], 'The editor repair selects the overlay block.');
    $document->getElementsByTagName('body')->item(0)->removeAttribute('class');
    $assert(!CssSelectorMatcher::matches($pin, $parsed)['matches'], 'The repair does not match on the frontend.');
    $found = true;
});
$assert($found, 'Generated editor support CSS restores position:fixed for the pin wrapper.');
echo "editor-canvas-fixed-position: passed (fixed wrapper, specificity above Core, frontend excluded)\n";
