<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

$assert = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};

// One authored rule styles the header button and a hero button, so lowering
// groups both controls in one selector. The hero's later, equally specific
// rule wins in the source. Shared chrome loads after the route stylesheet, so
// the grouped rule must lift only the header's alternative.
$css = '.page .btn{display:block;background:#123456;color:#fff;padding:10px 20px}.page .cta{display:flex;justify-content:center}';
$document = static fn (string $route, string $main): string => '<!doctype html><html><head><style>' . $css . '</style></head><body><div class="page">'
    . '<header data-route="' . $route . '"><a class="btn" href="tel:+100">Call</a></header>'
    . '<main>' . $main . '</main></div></body></html>';
$plan = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array(
    'index.html' => $document('home', '<h1>Home</h1><div class="hero"><a class="btn cta" href="about.html">Quote</a></div>'),
    'about.html' => $document('about', '<h1>About</h1><p>Text</p>'),
)))->toArray()['source_reports']['wordpress_site_plan'];

$header = current(array_filter($plan['template_parts'], static fn (array $part): bool => 'header' === ($part['area'] ?? null)));
$assert(is_array($header), 'The equal compiled headers share one part.');
$home = current(array_filter($plan['pages'], static fn (array $page): bool => str_contains($page['canonical_block_markup'], 'Quote')));
$assert(1 === preg_match('/blocks-engine-control-[a-f0-9]{12}-\d+/', $home['canonical_block_markup'], $hero), 'The hero link lowers to a control.');
$hero = $hero[0];
$assert(!str_contains($header['canonical_block_markup'], $hero), 'The hero control is route content.');

$routeCss = ''; $chromeCss = '';
foreach ($plan['assets'] as $asset) {
    if ('css' !== ($asset['kind'] ?? null)) continue;
    if (str_contains((string) ($asset['target_path'] ?? ''), '/shared-chrome-')) $chromeCss .= $asset['content'];
    elseif (str_contains((string) $asset['content'], $hero)) $routeCss .= $asset['content'];
}
$assert(!str_contains($chromeCss, $hero), 'Shared chrome lifts no alternative naming route content.');
$block = strpos($routeCss, 'display:block');
$flex = strpos($routeCss, 'display:flex');
$assert(false !== $block && false !== $flex && $block < $flex, 'The route keeps the hero alternative before its later rule.');

echo "shared-chrome-grouped-control-alternatives contract passed\n";
