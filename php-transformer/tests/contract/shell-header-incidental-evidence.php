<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\WordPressSitePlan;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\ShellExtraction;

$assert = static function (bool $condition, string $message): void { if (!$condition) throw new RuntimeException($message); };
$compile = static fn(array $files): array => (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => $files))->toArray();
$nav = static fn(string $evidence): string => '<nav><ul><li style="position:relative;width:88px;height:38px"' . $evidence . '><a href="/">Home</a></li><li><a href="/about/">About</a></li></ul></nav>';
$document = static function (string $evidence, string $title) use ($nav): string {
    $markup = '';
    foreach (array('desktop', 'mobile') as $device) {
        $markup .= '<div class="data-blocks-engine-capture-' . $device . '-document" data-blocks-engine-capture-document-scope=""><header>' . ('desktop' === $device ? $nav($evidence) : '<nav class="mobile-menu" style="display:none"><ul><li><a href="/">Home</a></li><li><a href="/about/">About</a></li></ul></nav>') . '</header><main><h1>' . $title . '</h1></main></div>';
    }
    return '<html><body>' . $markup . '</body></html>';
};
$evidence = ' data-blocks-engine-capture-device-inline="{&quot;tablet&quot;:&quot;position:relative;width:93px;height:38px&quot;}"';
$result = $compile(array('index.html' => $document('', 'Home'), 'about/index.html' => $document($evidence, 'About')));
foreach ($result['source_reports']['compiled_site']['pages'] as $page) {
    preg_match_all('/<!-- wp:navigation (\{.*?\}) -->/', $page['block_markup'], $matches);
    $assert(count($matches[1]) > 0, 'The capture projects real Core navigation.');
    foreach ($matches[1] as $json) $assert('never' === (json_decode($json, true)['overlayMenu'] ?? null), 'Device-inline descendant evidence must not enable an overlay: ' . $page['source_path']);
}
$plan = $result['source_reports']['wordpress_site_plan'];
WordPressSitePlan::assertValid($plan);
$assert(1 === count(array_filter($plan['template_parts'], static fn(array $part): bool => 'header' === $part['area'])), 'Both capture routes extract one shared header.');
foreach ($plan['pages'] as $page) $assert(!str_contains($page['canonical_block_markup'], '<header'), 'Neither route retains its header.');

// Also exercise raw source style whitespace through projection.
$plain = static fn(string $style, string $title): string => '<header><nav style="' . $style . '"><ul><li style="position: relative; box-sizing: border-box"><a href="/">Home</a></li><li><a href="/about/">About</a></li></ul></nav></header><main><h1>' . $title . '</h1></main>';
$result = $compile(array('index.html' => $plain('visibility:inherit;overflow-x:visible', 'Home'), 'about/index.html' => $plain('visibility: inherit; overflow-x: visible', 'About')));
$plan = $result['source_reports']['wordpress_site_plan'];
WordPressSitePlan::assertValid($plan);
$assert(1 === count(array_filter($plan['template_parts'], static fn(array $part): bool => 'header' === $part['area'])), 'Whitespace-only declaration differences extract one shared header.');
foreach ($plan['pages'] as $page) $assert(!str_contains($page['canonical_block_markup'], '<header'), 'Whitespace differences do not retain route-owned headers.');
// Shell extraction must also compare declaration strings stored in nested
// block JSON, not just rendered HTML style attributes. Start without source
// shell artifacts so extraction compares the supplied canonical headers.
$result = $compile(array('index.html' => '<main><h1>Home</h1></main>', 'about/index.html' => '<main><h1>About</h1></main>'));
foreach ($result['source_reports']['compiled_site']['pages'] as &$page) {
    $style = 'index.html' === $page['source_path'] ? 'visibility:inherit;overflow-x:visible' : 'visibility: inherit;overflow-x: visible';
    $itemStyle = 'index.html' === $page['source_path'] ? 'position:relative;box-sizing:border-box' : 'position: relative;box-sizing: border-box';
    $attrs = json_encode(array('overlayMenu' => 'never', 'style' => $style, 'items' => array(array('itemStyle' => $itemStyle))));
    $page['block_markup'] = '<!-- wp:group {"tagName":"header"} --><header class="wp-block-group"><!-- wp:navigation ' . $attrs . ' --><!-- wp:navigation-link {"label":"Home","url":"/"} /--><!-- wp:navigation-link {"label":"About","url":"/about/"} /--><!-- /wp:navigation --></header><!-- /wp:group --><!-- wp:paragraph --><p>' . $page['source_path'] . '</p><!-- /wp:paragraph -->';
}
unset($page);
$headers = array_column($result['source_reports']['compiled_site']['pages'], 'block_markup');
$other = str_replace('about/index.html', 'index.html', $headers[1]);
$assert(ShellExtraction::identityMarkup($headers[0]) === ShellExtraction::identityMarkup($other), 'Serialized style whitespace has one shell identity.');
$assert(ShellExtraction::identityMarkup($headers[0]) !== ShellExtraction::identityMarkup(str_replace('inherit', 'hidden', $other)), 'Different declaration values remain different shell identities.');
$plan = (new WordPressSitePlan())->fromResult($result);
WordPressSitePlan::assertValid($plan);
$assert(1 === count(array_filter($plan['template_parts'], static fn(array $part): bool => 'header' === $part['area'])), 'Serialized style and nested itemStyle whitespace extract one shared header.');
foreach ($plan['pages'] as $page) $assert(!str_contains($page['canonical_block_markup'], '<header'), 'Serialized styles do not retain route-owned headers.');
fwrite(STDOUT, "Shell header incidental-evidence contract passed.\n");
