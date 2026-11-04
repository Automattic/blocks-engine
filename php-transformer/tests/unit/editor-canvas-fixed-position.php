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
echo "editor-canvas-fixed-position: passed\n";
