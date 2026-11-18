<?php
declare(strict_types=1);

/** Pending substitutions must not promote losing typography into inline styles. */
require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

$html = <<<'HTML'
<!doctype html><html><head><meta charset="utf-8"><title>Var font button</title><style>
body{margin:0;font-family:sans-serif;--text-font:normal normal 400 18px/1.4 Georgia,serif}
.wrap{padding:24px}
.ghost{font-family:"Courier New",monospace;font-weight:300;border:1px solid transparent;border-radius:999px}
.primary{background:#5d1ff2;color:#fff;font:var(--text-font);padding:8px 16px;cursor:pointer}
</style></head><body><main><div class="wrap"><button class="primary ghost" type="button">Play video</button></div></main></body></html>
HTML;
$result = (new HtmlTransformer())->transform($html, array())->toArray();
$serialized = $result['serialized_blocks'];
$css = implode("\n", array_column($result['assets'], 'content'));
$assert = static function (bool $ok, string $message): void {
    if (!$ok) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
};
$assert(str_contains($serialized, 'wp:button '), 'the source control remains a native button');
$assert(!str_contains($serialized, 'font-family:') && !str_contains($serialized, 'font-weight:'), 'losing family and weight must not be lifted inline');
$assert(!str_contains($serialized, 'line-height:'), 'pending font leading must not be overridden by a UA line-height');
$assert(str_contains($css, 'font:var(--text-font)') && str_contains($css, '--text-font:normal normal 400 18px/1.4 Georgia,serif'), 'the authored winner and its token remain stylesheet-owned');
$assert(str_contains($serialized, 'padding-top:8px') && str_contains($serialized, 'border-radius:999px'), 'unrelated native supports remain intact');
fwrite(STDOUT, "Pending shorthand cascade passed\n");
