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
$assert(substr_count($markup, '<!-- wp:group ') === 1, 'Only the card remains a group.');
$assert(substr_count($markup, '<!-- wp:custom/layout-shell ') === 1, 'The image chain becomes one shell.');
$assert((bool) preg_match('/<!-- wp:group ([^\n]*?) -->/', $markup, $group), 'Card group exists.');
$assert(str_contains(json_decode($group[1], true, 512, JSON_THROW_ON_ERROR)['className'] ?? '', 'card'), 'Card owns the shell and caption.');
$assert((bool) preg_match('/<!-- wp:custom\/layout-shell (.*?) -->/s', $markup, $shell), 'Shell attributes exist.');
$attrs = json_decode($shell[1], true, 512, JSON_THROW_ON_ERROR);
$assert(count($attrs['wrappers']) === 5, 'All five wrappers fold together.');
foreach (array('node-image', 'non-clickable', 'frame', 'crop', 'inner') as $index => $class) {
    $wrapper = $attrs['wrappers'][$index];
    $assert($wrapper['tagName'] === 'div' && in_array($class, explode(' ', $wrapper['attributes']['class'] ?? ''), true), 'Wrapper order and identity: ' . $class);
}
$assert((bool) preg_match('/\bbe-inline-geometry-[a-f0-9]{16}(?:-[a-f0-9]{16})?\b/', $attrs['wrappers'][1]['attributes']['class'], $geometry), 'Geometry class remains on non-clickable.');
$assert((bool) preg_match('/\.' . preg_quote($geometry[0], '/') . '\{([^}]+)\}/', $css, $rule), 'Matching geometry CSS is emitted.');
$assert((bool) preg_match('/\bwidth:240px\b/', $rule[1]) && (bool) preg_match('/\bheight:160px\b/', $rule[1]), 'Geometry retains exact dimensions.');
$rendered = preg_replace('/<!--.*?-->/s', '', $markup);
$document = new DOMDocument();
@$document->loadHTML($rendered);
$xpath = new DOMXPath($document);
$assert($xpath->query('//div[contains(concat(" ",normalize-space(@class)," ")," card ")]/div/div/div/div/div//img')->length === 1, 'Saved wrapper chain contains the image.');
$assert(str_contains($markup, '<!-- wp:image ') && str_contains($markup, 'Caption text'), 'Image and caption stay editable.');
fwrite(STDOUT, "geometry-wrapper-chain-fold contract passed\n");
