<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\Css\CssStylesheetTransformer;
use Automattic\BlocksEngine\PhpTransformer\Css\RouteSelectorMatchIndex;

// Cascade proof work keys on content: routes that repeat one template, and
// one shared shell appended to every route, are resolved once per distinct
// input, never once per route (Automattic/blocks-engine#7443).
$assert = static function (bool $ok, string $message): void {
    if (!$ok) throw new RuntimeException($message);
};
$document = static function (string $markup, string $bodyClass = 'page'): DOMDocument {
    $dom = new DOMDocument();
    $dom->loadHTML('<html><body class="' . $bodyClass . '">' . $markup . '</body></html>', LIBXML_NOERROR);
    return $dom;
};
$shell = '<header class="shell"><nav><ul>';
for ($i = 0; $i < 30; ++$i) $shell .= '<li class="nav-' . $i . '"><a class="link link-' . $i . '" href="#' . $i . '">Item</a></li>';
$shell .= '</ul></nav></header><footer class="shell site-footer">' . str_repeat('<p class="note">Line</p><div><span>Meta</span></div>', 40) . '</footer>';
$shared = '';
for ($r = 0; $r < 120; ++$r) {
    $shared .= match ($r % 4) {
        0 => '.shell .nav-' . ($r % 30) . ' a{color:#' . sprintf('%06x', $r) . '}',
        1 => 'footer p,.site-footer span{color:#' . sprintf('%06x', $r) . '}',
        2 => 'main > section div{margin:' . $r . 'px}',
        default => '.shell li > a,.card + .card{margin:' . $r . 'px;color:red}',
    };
}
// The page rule differs from the shared sheet, so every later shared rule
// that sets color or margin must be proven not to compete on each route.
$page = '.route-delta{color:blue;margin:0}' . $shared;

/**
 * @param callable(int):string $content Route body content by route number.
 * @return array{resolutions:int, executions:int, delta:array<string,string>}
 */
$run = static function (int $routes, callable $content) use ($document, $shell, $shared, $page): array {
    $documents = array();
    for ($r = 0; $r < $routes; ++$r) $documents['route-' . $r] = $document('<main>' . $content($r) . '</main>' . $shell);
    $index = new RouteSelectorMatchIndex($documents);
    $transformer = new CssStylesheetTransformer();
    $delta = array();
    foreach (array_keys($documents) as $owner) $delta[$owner] = $transformer->rulesAbsentFrom(array($page), array($shared), true, $index, $owner);
    return $index->workCounts() + array('delta' => $delta);
};

// Identical-template routes differ only in text: one resolution per arm.
$template = static fn (int $r): string => '<h1>Route ' . $r . '</h1><section><div class="card route-delta">Body ' . $r . '</div><div class="card">More</div></section>';
$small = $run(4, $template);
$large = $run(8, $template);
$assert($small['resolutions'] > 0, 'The fixture exercises cascade proofs');
$assert($large['resolutions'] === $small['resolutions'], 'Doubling identical-template routes must not add selector resolutions: ' . json_encode(array($small['resolutions'], $large['resolutions'])));
$assert($large['executions'] === $small['executions'], 'Doubling identical-template routes must not add matcher executions: ' . json_encode(array($small['executions'], $large['executions'])));
$assert(1 === count(array_unique($large['delta'])) && $large['delta']['route-0'] === $small['delta']['route-0'], 'Shared resolutions keep each route delta');

// Distinct routes share one shell: shell elements are matched once for
// selectors that read only ancestors, wherever the shell lands. Only each
// added route's own few elements cost new executions.
$distinct = static fn (int $r): string => '<section class="s-' . $r . '"><div class="card route-delta">Body</div><div class="card"></div></section>';
$small = $run(4, $distinct);
$large = $run(8, $distinct);
$assert($large['resolutions'] > $small['resolutions'], 'Distinct routes are resolved separately');
$assert(10 * ($large['executions'] - $small['executions']) < $small['executions'], 'Shared-shell matches must not scale with route count: ' . json_encode(array($small['executions'], $large['executions'])));

// Shared results equal an index that sees each route alone.
$routes = array(
    'a' => $document('<main><p>Text</p><p class="x"></p></main>' . $shell),
    'b' => $document('<main><p>Other</p><p class="x"></p></main>' . $shell),
    // Same tags and attributes, but :empty now differs.
    'c' => $document('<main><p></p><p class="x">Text</p></main>' . $shell),
    // Same markup under a different body: ancestor chains differ.
    'd' => $document('<main><p>Text</p><p class="x"></p></main>' . $shell, 'other'),
);
$index = new RouteSelectorMatchIndex($routes);
foreach (array('p:empty', 'main p.x', '.page .shell a', 'li:first-child > a::before', 'p + p', ':root .note', 'body:not(.other) span') as $arm) {
    foreach ($routes as $owner => $dom) {
        $alone = (new RouteSelectorMatchIndex(array($owner => $dom)))->matchedElements($arm, $owner);
        $assert($index->matchedElements($arm, $owner) === $alone, 'Content-keyed result differs for ' . $arm . ' on ' . $owner);
    }
}
fwrite(STDOUT, "Route cascade resolution reuse passed\n");
