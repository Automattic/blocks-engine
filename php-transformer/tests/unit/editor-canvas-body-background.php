<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\Css\EditorCanvasBodyBackground;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;
use Automattic\BlocksEngine\PhpTransformer\VisualParity\StaticCssCascade;

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

// Reduced import from #3602: core resets body margin in both rendering contexts.
foreach (array(
    array('', true, '8px'),
    array('body{margin:0}', true, '0'),
    array('', false, '0'),
) as [$marginCss, $isDocument, $expected]) {
    $html = '<!doctype html><html><head><meta charset="utf-8"><title>Body margin</title>'
        . '<style>body{background:#f3f5f8;font-family:sans-serif}.bar{background:#111f38;color:#fff;padding:12px}.hero{background:#2b4a7a;color:#fff;padding:40px}'
        . $marginCss . '</style></head><body><div class="bar">Call 346-336-6686</div><section class="hero"><h1>Steel buildings</h1><p>Engineered to your spec.</p></section></body></html>';
    if (! $isDocument) {
        $html = preg_replace('/<!doctype html>|<\/?(?:html|head|body)\b[^>]*>|<meta[^>]*>|<title>.*?<\/title>/i', '', $html) ?? '';
    }
    $import = (new HtmlTransformer())->transform($html)->toArray();
    $css = implode("\n", array_map(
        static fn (array $asset): string => (string) ($asset['content'] ?? ''),
        array_filter($import['assets'] ?? array(), static fn (array $asset): bool => 'css' === ($asset['kind'] ?? ''))
    ));
    foreach (array('', 'editor-styles-wrapper') as $bodyClass) {
        $document = new DOMDocument();
        $document->loadHTML('<html><body class="' . $bodyClass . '"><div>Band</div></body></html>');
        $body = $document->getElementsByTagName('body')->item(0);
        $properties = '0' === $expected ? array('margin') : array('margin-top', 'margin-right', 'margin-bottom', 'margin-left');
        $resolved = (new StaticCssCascade($document, ':where(body){margin:0}' . $css))->resolve($body, $properties, array());
        $expectedSides = array_fill_keys($properties, $expected);
        ksort($expectedSides);
        $assert($expectedSides === $resolved, 'import resolves ' . $expected . ' over core body reset in ' . ($bodyClass ?: 'frontend'));
    }
    if ('8px' === $expected) {
        $assert(str_contains($css, 'body,body.editor-styles-wrapper{margin-top:8px;margin-right:8px;margin-bottom:8px;margin-left:8px}'), 'import restores the 8px UA margin on the frontend and editor canvas (x=8, width=1424 at 1440px)');
    } elseif ($isDocument) {
        $assert(str_contains($css, 'margin:0') && ! str_contains($css, 'margin-top:8px'), 'author margin 0 survives without a UA override (x=0, width=1440 at 1440px)');
    } else {
        $assert(! str_contains($css, 'body,body.editor-styles-wrapper{margin-'), 'a fragment leaves the host body margin unchanged (x=0, width=1440 at 1440px)');
    }
}

if ($failures > 0) {
    fwrite(STDERR, "editor-canvas-body-background: {$failures} failure(s), {$passes} passed\n");
    exit(1);
}
echo "editor-canvas-body-background: {$passes} passed\n";
