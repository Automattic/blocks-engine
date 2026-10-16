<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\Css\EditorCanvasBodyBackground;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

$failures = 0;
$passes = 0;
$assert = static function (bool $condition, string $message) use (&$failures, &$passes): void {
    if ($condition) { ++$passes; return; }
    ++$failures;
    fwrite(STDERR, "FAIL: {$message}\n");
};

$rule = EditorCanvasBodyBackground::RULE;
$build = static fn (string $css): string => (new EditorCanvasBodyBackground())->supportCss($css);

// Gutenberg's iframe fallback `:where(body){background-color:white}` is unlayered,
// so it beats a layered author body background in the editor canvas only (#2917).
$assert($rule === $build('@layer base{body{background-color:var(--background);color:var(--foreground)}}'), 'layered body background-color is handed back to the author layer');
$assert($rule === $build('@layer theme,base;@layer base{html body.dark{background:#070709}}'), 'layered background shorthand on a compound body selector is handed back');
$assert($rule === $build('@media (prefers-color-scheme:dark){@layer base{body{background-color:#000}}}'), 'a layered background nested in a condition is handed back');
$assert($rule === $build('@layer base{h1,body{background-color:#000}}'), 'body inside a selector list is recognised');

$assert('' === $build('body{background-color:#000}'), 'an unlayered author background already wins, so nothing is emitted');
$assert('' === $build('@layer base{body{background-color:#000}}body{background:#111}'), 'an unlayered author background keeps precedence over the layered one');
$assert('' === $build('@layer base{body{color:#fff}.card{background:#000}}'), 'without a layered body background the editor fallback stays');
$assert('' === $build('@layer base{body .card{background:#000}.body{background:#000}}'), 'descendant and look-alike selectors do not count as the body');

// Wired into the compiled after-author support stylesheet, editor-only by selector.
$result = ( new HtmlTransformer() )->transform(
    '<style>@layer base,utilities;@layer base{body{background-color:#070709;color:#f3f2ee}}@layer utilities{.lede{font-size:2rem}}</style><main><p class="lede">Hello</p></main>'
)->toArray();
$afterAuthor = implode("\n", array_map(
    static fn (array $asset): string => (string) ($asset['content'] ?? ''),
    array_filter(is_array($result['assets'] ?? null) ? $result['assets'] : array(), static fn (array $asset): bool => 'css' === ($asset['kind'] ?? '') && 'after-author' === ($asset['stylesheet_placement'] ?? ''))
));
$assert(str_contains($afterAuthor, $rule), 'a compiled layered-body page ships the editor canvas background hand-back after author CSS');

$plain = ( new HtmlTransformer() )->transform('<style>body{background-color:#070709}</style><main><p>Hello</p></main>')->toArray();
$assert(! str_contains(json_encode($plain) ?: '', 'revert-layer'), 'a compiled unlayered-body page ships no hand-back');

if ($failures > 0) {
    fwrite(STDERR, "editor-canvas-body-background: {$failures} failure(s), {$passes} passed\n");
    exit(1);
}
echo "editor-canvas-body-background: {$passes} passed\n";
