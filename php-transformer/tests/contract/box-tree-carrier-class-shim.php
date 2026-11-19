<?php
declare(strict_types=1);

require __DIR__ . '/../../vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\Css\CssSelectorMatcher;
use Automattic\BlocksEngine\PhpTransformer\Css\CssStylesheetTransformer;

// A box-tree carrier repeats its class (`.c.c`) to outrank core block and
// layout rules, in its base rule and again in every breakpoint. Absolutely
// laid out sources give nearly every element its own carrier, so the theme
// paid that second class name hundreds of times per route (#6145). The theme
// ships it as a class shim no emitted markup names (`.c:not(.be-c-0)`): the
// same elements at the same weight.
$html = file_get_contents(__DIR__ . '/../fixtures/box-tree/index.html');
$tree = json_decode(file_get_contents(__DIR__ . '/../fixtures/box-tree/index.json'), true, 32, JSON_THROW_ON_ERROR);
$tree['source_hash'] = hash('sha256', $html);
foreach (array('n5', 'n6') as $node) {
    $tree['nodes'][$node]['viewports']['desktop']['used_styles']['width'] = '1200px';
    $tree['nodes'][$node]['viewports']['mobile']['used_styles']['width'] = '300px';
}
$assert = static function (bool $ok, string $message): void {
    if (!$ok) throw new RuntimeException($message);
    echo "PASS: $message\n";
};

$compiled = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array('index.html' => $html, 'box-tree/index.json' => json_encode($tree))));
$plan = $compiled->toWordPressSitePlanView()['wordpress_site_plan'];
$css = implode("\n", array_column(array_filter($plan['assets'], static fn(array $asset): bool => 'css' === ($asset['kind'] ?? null) && is_string($asset['content'] ?? null)), 'content'));
$markup = implode("\n", array_filter(array_column($plan['pages'], 'canonical_block_markup'), 'is_string'));

$carriers = array();
$repeated = array();
(new CssStylesheetTransformer())->visitStyleRules($css, static function (string $selector) use (&$carriers, &$repeated): void {
    foreach (CssStylesheetTransformer::splitSelectorList($selector) ?? array() as $arm) {
        if (preg_match('/\.(be-inline-geometry-[a-f0-9]{16})\.\1(?![\w-])/', $arm)) $repeated[] = $arm;
        if (preg_match('/^\.(be-inline-geometry-[a-f0-9]{16}):not\(\.(be-c-\d+)\)$/', trim($arm), $match)) $carriers[] = array($arm, $match[1], $match[2]);
    }
});
$assert(array() === $repeated, "No carrier arm names its class twice:\n" . implode("\n", array_slice($repeated, 0, 3)));
$assert(array() !== $carriers, "Carrier rules ship their weight as a class shim:\n" . $css);
foreach ($carriers as [$arm, $class, $shim]) {
    $assert(str_contains($markup, $class), 'The carrier ' . $class . ' is on emitted markup.');
    $assert(!preg_match('/class="[^"]*\b' . preg_quote($shim, '/') . '\b/', $markup), 'No emitted element carries the shim ' . $shim . '.');
    $assert(
        CssSelectorMatcher::specificityCounts(CssSelectorMatcher::parse('.' . $class . '.' . $class)) === CssSelectorMatcher::specificityCounts(CssSelectorMatcher::parse($arm)),
        $arm . ' weighs what the repeated carrier class weighs.'
    );
}

echo "box-tree-carrier-class-shim: ok\n";
