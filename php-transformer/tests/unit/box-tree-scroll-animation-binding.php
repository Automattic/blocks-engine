<?php
declare(strict_types=1);

require __DIR__ . '/../../vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\ThemeBootstrap;

// A box-tree route retires the author sheet. A scroll-driven parallax the
// capture observed running must still reach the route (#5874): its
// keyframes, its timeline and its range, gated as the author gated them,
// instead of a bare animation-name that names no keyframes and a frozen
// transform the animation painted at capture. A narrower capture that saw
// no animation keeps its own static layout.
$assert = static function (bool $ok, string $message): void {
    if (!$ok) throw new RuntimeException($message);
};
$author = '@keyframes motion-parallaxScroll-legacy{0%{transform:translateY(calc(-1 * var(--motion-parallax-to))) rotate(var(--comp-rotate-z, 0))}100%{transform:translateY(var(--motion-parallax-to)) rotate(var(--comp-rotate-z, 0))}}'
    . '[data-interact-key="a"] :is(.container):is(#comp-a){--vw-tmln-0:--trigger-49}'
    . '@media (prefers-reduced-motion: no-preference){[data-interact-key="a"] :is(.container):is(#comp-a){--motion-parallax-to:-7.5vh;--anm-0:motion-parallaxScroll-legacy auto linear both 1 ;--anm-tmln-0:--trigger-49;--anm-rng-0:cover calc(0% + 7.5vh) cover calc(100% + -7.5vh)}}'
    . '[data-interact-key="a"] :is(.container):is(#comp-a){animation:var(--anm-0);animation-composition:var(--anm-cmps-0);animation-timeline:var(--anm-tmln-0);animation-range:var(--anm-rng-0);view-timeline:var(--vw-tmln-0)}';
$html = '<!doctype html><html data-blocks-engine-node-id="n1"><head data-blocks-engine-node-id="n2"><title data-blocks-engine-node-id="n3">Parallax</title><link data-blocks-engine-node-id="styles" rel="stylesheet" href="author.css"></head>'
    . '<body data-blocks-engine-node-id="n4"><main data-interact-key="a" data-blocks-engine-node-id="n5"><div id="comp-a" class="container" data-blocks-engine-node-id="n6"><p data-blocks-engine-node-id="n7">Parallax.</p></div></main></body></html>';
$tree = json_decode(file_get_contents(__DIR__ . '/../fixtures/box-tree/index.json'), true, 32, JSON_THROW_ON_ERROR);
$box = $tree['nodes']['n6']['viewports'];
$tree['nodes'] = array_intersect_key($tree['nodes'], array_flip(array('n1', 'n2', 'n3', 'n4', 'n5')));
$tree['nodes']['n2']['children'][] = 'styles';
$tree['nodes']['styles'] = array('children' => array(), 'viewports' => $tree['nodes']['n3']['viewports']);
$tree['nodes']['n5']['children'] = array('n6');
$animated = static fn(array $observation, string $matrix): array => array('border_box' => $observation['border_box'], 'used_styles' => array_replace($observation['used_styles'], array('transform' => $matrix, 'animation-name' => 'motion-parallaxScroll-legacy')));
$tree['nodes']['n6'] = array('children' => array('n7'), 'viewports' => array(
    'desktop' => $animated($box['desktop'], 'matrix(1, 0, 0, 1, 0, 67.5)'),
    'mobile' => array_replace_recursive($box['mobile'], array('used_styles' => array('transform' => 'matrix(1, 0, 0, 1, 13, 17)', 'animation-name' => 'none'))),
));
$tree['nodes']['n7'] = array('children' => array(), 'viewports' => $box);
$tree['source_hash'] = hash('sha256', $html);

$result = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array(
    'index.html' => $html,
    'author.css' => $author,
    'box-tree/index.json' => json_encode($tree),
)));
$plan = $result->toWordPressSitePlanView()['wordpress_site_plan'];
$assets = array_column($plan['assets'], null, 'target_path');
$css = '';
foreach (ThemeBootstrap::documentStylesheets($plan, 'index.html') as $row) $css .= ($assets[$row['target_path']]['content'] ?? '') . "\n";

$assert(!str_contains($css, '[data-interact-key'), 'The author selectors are retired on the evidence route: ' . $css);
$assert(1 === substr_count($css, '@keyframes motion-parallaxScroll-legacy{'), 'The observed animation ships its keyframes once: ' . $css);
$assert(1 === preg_match('/@media \(min-width:391px\)\{@media \(prefers-reduced-motion: no-preference\)\{\.(be-[a-z0-9-]+)\{animation:motion-parallaxScroll-legacy auto linear both 1;animation-timeline:--trigger-49;animation-range:cover calc\(0% \+ 7\.5vh\) cover calc\(100% \+ -7\.5vh\);--motion-parallax-to:-7\.5vh\}\}\.\1\{view-timeline:--trigger-49\}\}/', $css, $carrier), 'The animation, its timeline, range and keyframe input keep the author condition, and the element names the view timeline it drives, at the widths that ran it: ' . $css);
$assert(1 !== preg_match('/\\.' . preg_quote($carrier[1], '/') . '\\{[^}]*animation-composition/', $css), 'An undefined substitution is dropped from the carrier, as the browser computes it: ' . $css);
$assert(!str_contains($css, 'matrix(1, 0, 0, 1, 0, 67.5)') && !str_contains($css, 'matrix(1,0,0,1,0,67.5)'), 'The frozen parallax frame is not restated: ' . $css);
$assert(str_contains($result->toArray()['serialized_blocks'], $carrier[1]), 'The binding carrier rides the block.');
$assert(1 === preg_match('/@media[^{}]*max-width:390px[^{}]*\{[^{}]*\{[^{}]*transform:matrix\(1,\s*0,\s*0,\s*1,\s*13,\s*17\)/', $css), 'The non-animated mobile capture keeps its static transform in its own width band: ' . $css);

echo "Box-tree scroll animation binding passed\n";
