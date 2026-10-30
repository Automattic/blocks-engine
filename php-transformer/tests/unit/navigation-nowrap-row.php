<?php
declare(strict_types=1);

// A source flex nav row never wraps unless the author says so (`flex-wrap`'s
// initial value is `nowrap`). core/navigation wraps by default, so a narrow
// viewport drops whole items to a second line. The row is carried as core's
// native `layout.flexWrap:nowrap`, and the engine's responsive container rule
// follows core's wrap setting instead of forcing `wrap` (#2987).

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

$assert = static function (bool $condition, string $message, string $context = ''): void {
    if (!$condition) { fwrite(STDERR, "FAIL: {$message}\n{$context}\n"); exit(1); }
};
$links = '<a href="/a">Services</a><a href="/b">Process</a><a href="/c">Before After</a><a href="/d">Contact</a>';
$navigation = static function (string $css, string $nav = '') use ($links): array {
    $html = '<style>.bar{display:flex;justify-content:space-between}' . $css . '</style><header><div class="bar"><a href="/">Brand</a>' . ('' !== $nav ? $nav : '<nav class="links">' . $links . '</nav>') . '</div></header><main><p>Body</p></main>';
    $result = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array('index.html' => $html)))->toArray();
    $markup = (string) $result['serialized_blocks'];
    preg_match('/<!-- wp:navigation (\{.*?\}) -->/', $markup, $match);
    $css = implode("\n", array_map(static fn(array $asset): string => (string) ($asset['content'] ?? ''), array_filter((array) ($result['assets'] ?? array()), static fn(array $asset): bool => 'css' === ($asset['kind'] ?? ''))));
    return array('attrs' => json_decode($match[1] ?? 'null', true), 'markup' => $markup, 'css' => $css);
};

// 1. A flex row (shown only from a breakpoint up) that never wraps.
$row = $navigation('.links{display:none;gap:32px;align-items:center}@media (min-width:768px){.links{display:flex}}');
$assert(is_array($row['attrs']), 'the nav is core/navigation', $row['markup']);
$assert('nowrap' === ($row['attrs']['layout']['flexWrap'] ?? null) && 'flex' === ($row['attrs']['layout']['type'] ?? null), 'a non-wrapping flex row is carried as native flexWrap:nowrap', json_encode($row['attrs']));

// 2. An authored wrap, at any viewport, keeps core's default.
$wrapped = $navigation('.links{display:flex;gap:32px}@media (max-width:900px){.links{flex-wrap:wrap}}');
$assert(!isset($wrapped['attrs']['layout']['flexWrap']), 'an authored wrap is not overridden', json_encode($wrapped['attrs']));
$flow = $navigation('.links{display:flex;flex-flow:row wrap;gap:32px}');
$assert(!isset($flow['attrs']['layout']['flexWrap']), 'a flex-flow wrap is not overridden', json_encode($flow['attrs']));

// 3. A column row and a non-flex row are untouched.
$column = $navigation('.links{display:flex;flex-direction:column}');
$assert('nowrap' !== ($column['attrs']['layout']['flexWrap'] ?? null), 'a column is not given a row wrap setting', json_encode($column['attrs']));
$block = $navigation('.links a{display:inline-block;margin:0 8px}');
$assert(!isset($block['attrs']['layout']['flexWrap']), 'a non-flex nav is unchanged', json_encode($block['attrs']));

// The responsive list container's wrap rule is asserted in
// tests/unit/engine-support-css-asset.php.

fwrite(STDOUT, "navigation nowrap row tests: passed\n");
