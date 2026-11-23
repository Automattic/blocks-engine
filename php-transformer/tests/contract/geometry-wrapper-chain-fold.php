<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\WordPressSitePlan;

$assert = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};
$html = '<div class="card"><div class="node-image"><div class="non-clickable" style="width:240px;height:160px"><div class="frame"><div class="crop"><div class="inner"><img src="https://example.com/a.jpg" alt="Boat" width="240" height="160"></div></div></div></div></div><p>Caption text</p></div>';
$result = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array(
    'index.html' => '<html><head><link rel="stylesheet" href="/style.css"></head><body>' . $html . '</body></html>',
    'style.css' => '.frame{position:relative}.crop{overflow:hidden;border-radius:8px}.inner{width:100%}',
)))->toArray();
$plan = (new WordPressSitePlan())->fromCompilerResult($result);
$markup = $plan['pages'][0]['canonical_block_markup'];
$css = implode("\n", array_column($plan['assets'], 'content'));
// Short render-safe geometry chains stay native: every wrapper is its own
// core/group, in source order, keeping its classes and geometry carrier.
$assert(! str_contains($markup, 'layout-shell'), 'The image chain stays native core blocks.');
$assert(preg_match_all('/<!-- wp:group (\{.*?\}) -->/', $markup, $groups) === 6, 'The card and its five wrappers are each a group.');
$classes = array_map(static fn (string $json): string => (string) (json_decode($json, true, 512, JSON_THROW_ON_ERROR)['className'] ?? ''), $groups[1]);
foreach (array('card', 'node-image', 'non-clickable', 'frame', 'crop', 'inner') as $index => $class) {
    $assert(in_array($class, explode(' ', $classes[$index]), true), 'Wrapper order and identity: ' . $class);
}
$assert((bool) preg_match('/\bbe-inline-geometry-[a-f0-9]{16}(?:-[a-f0-9]{16})?\b/', $classes[2], $geometry), 'Geometry class remains on non-clickable.');
$assert((bool) preg_match('/\.' . preg_quote($geometry[0], '/') . '\{([^}]+)\}/', $css, $rule), 'Matching geometry CSS is emitted.');
$assert((bool) preg_match('/\bwidth:240px\b/', $rule[1]) && (bool) preg_match('/\bheight:160px\b/', $rule[1]), 'Geometry retains exact dimensions.');
$rendered = preg_replace('/<!--.*?-->/s', '', $markup);
$document = new DOMDocument();
@$document->loadHTML($rendered);
$xpath = new DOMXPath($document);
$assert($xpath->query('//div[contains(concat(" ",normalize-space(@class)," ")," card ")]/div/div/div/div/div//img')->length === 1, 'Saved wrapper chain contains the image.');
$assert(str_contains($markup, '<!-- wp:image ') && str_contains($markup, 'Caption text'), 'Image and caption stay editable.');
fwrite(STDOUT, "geometry-wrapper-chain-fold contract passed\n");
