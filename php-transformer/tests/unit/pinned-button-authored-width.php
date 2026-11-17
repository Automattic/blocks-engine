<?php
declare(strict_types=1);

/** Pinned controls retain authored sizing instead of an unconditional fill. */
require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

$html = <<<'HTML'
<!doctype html><html><head><meta charset="utf-8"><title>Button fixture</title><style>
body{margin:0;font-family:sans-serif}
.box{position:relative;width:110px;height:40px;margin:40px}
.btn{box-sizing:border-box;display:flex;align-items:center;justify-content:center;width:max-content;min-width:100%;background:#5d1ff2;color:#fff;white-space:nowrap}
[data-variant] .box .btn{position:absolute;inset:0}
</style></head><body><main><div data-variant="solid"><div class="box"><a class="btn" href="/about/">Get in touch with the team</a></div></div></main></body></html>
HTML;
$out = (new HtmlTransformer())->transform($html)->toArray();
$css = implode('', array_map(
    static fn (array $asset): string => 'css' === ($asset['kind'] ?? '') ? (string) ($asset['content'] ?? '') : '',
    $out['assets'] ?? array()
));
$failures = 0;
$assert = static function (bool $condition, string $message) use (&$failures): void {
    if ( ! $condition ) {
        ++$failures;
        fwrite(STDERR, 'FAIL: ' . $message . PHP_EOL);
    }
};
$assert(str_contains((string) ($out['serialized_blocks'] ?? ''), '<!-- wp:button '), 'link converts to core/button');
$assert(str_contains($css, 'width:max-content') && str_contains($css, 'min-width:100%'), 'authored intrinsic width and minimum survive projection');
$assert((bool) preg_match('/[^{}]*wp-block-button__link[^{}]*\{[^{}]*position:absolute/', $css), 'attribute-gated positioning stays on the link');
$assert((bool) preg_match('/[^{}]*wp-block-button__link[^{}]*\{[^{}]*inset:0/', $css), 'attribute-gated inset stays on the link');
$assert(! preg_match('/[^{}]*wp-block-button__link[^{}]*\{[^{}]*(?<![\w-])width:100%!important/', $css), 'pinned link does not override authored width with an important fill');
$assert((bool) preg_match('/[^{}]*wp-block-button__link[^{}]*\{[^{}]*height:100%!important/', $css), 'pinned height fill is retained');
if ( $failures > 0 ) {
    exit(1);
}
fwrite(STDOUT, "pinned button authored width tests passed\n");
