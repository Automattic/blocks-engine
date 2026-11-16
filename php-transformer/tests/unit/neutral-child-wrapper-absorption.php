<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

$transform = static fn (string $html): array => (new HtmlTransformer())->transform($html)->toArray();
$assert = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};

$css = '<style>.surface{padding:2rem;background:#eee}p{margin-top:0;margin-bottom:1rem}.flush{margin-bottom:0}</style>';
$result = $transform($css . '<div class="surface"><p class="flush">Editable copy</p></div>');
$block = $result['blocks'][0];
$assert('core/paragraph' === $block['blockName'], 'A neutral paragraph does not require a separate styled Group.');
$assert(str_contains($block['attrs']['className'], 'surface') && str_contains($block['attrs']['className'], 'flush'), 'Both source identities survive on the native paragraph.');
$assert(str_contains($result['serialized_blocks'], 'Editable copy'), 'Rich text survives absorption.');
$assert('pass' === $result['source_reports']['wp_block_validity']['status'], 'The absorbed paragraph remains editor-valid.');

foreach (array(
    '<div class="surface"><p>Default margin</p></div>',
    '<div class="surface"><p class="flush" id="target">Identity</p></div>',
    '<div class="surface" style="display:flex"><p class="flush">Flex item</p></div>',
    '<style>.surface > p{color:red}</style><div class="surface"><p class="flush">Selector boundary</p></div>',
) as $html) {
    $kept = $transform($css . $html);
    $assert('core/group' === $kept['blocks'][0]['blockName'], 'Independent geometry, identity and selector boundaries survive.');
}

$branch = $transform('<style>.frame{padding:2rem}</style><div class="frame"><div class="neutral"><h2>Title</h2><p>Copy</p></div></div>');
$assert('core/group' === $branch['blocks'][0]['blockName'] && 2 === count($branch['blocks'][0]['innerBlocks']), 'A neutral branching child merges into its styled native parent.');

$inherited = $transform('<style>.surface{padding:2rem;background:#eee;--ink:red}.surface p{margin:0;color:var(--ink)}</style><div class="surface"><p>Inherited ink</p></div>');
$assert('core/group' === $inherited['blocks'][0]['blockName'], 'A consumed custom-property carrier keeps its source boundary.');
$spacing = $transform('<style>.surface{padding:2rem;background:#eee;margin:3rem}p{margin:0}</style><div class="surface"><p>Separate spacing owners</p></div>');
$assert('core/group' === $spacing['blocks'][0]['blockName'], 'A child margin reset must not compete with the wrapper margin on one host.');
$assert('core/paragraph' === $spacing['blocks'][0]['innerBlocks'][0]['blockName'], 'The paragraph remains independently editable inside the spacing box.');

foreach (array('border:4px solid red', 'border-top:4px solid red', 'border-radius:12px') as $paint) {
    $border = $transform('<style>.surface{padding:2rem;background:#eee;' . $paint . '}p{margin:0;border:0;border-radius:0}</style><div class="surface"><p>Separate border owners</p></div>');
    $assert('core/group' === $border['blocks'][0]['blockName'], 'A neutral child border reset must not erase the parent border or radius.');
    $assert('core/paragraph' === $border['blocks'][0]['innerBlocks'][0]['blockName'], 'The text stays a native paragraph within its painted box.');
}

$nested = $transform('<style>.surface{padding:2rem;background:#eee}.painted{padding:1rem;background:red}</style><div class="surface"><div class="neutral"><div class="painted"><h2>Title</h2><p>Copy</p></div></div></div>');
$assert('core/group' === $nested['blocks'][0]['innerBlocks'][0]['blockName'], 'A promoted styled descendant retains its independent box inside the surface.');
$assert(!str_contains($nested['blocks'][0]['attrs']['className'], 'painted'), 'The descendant paint must not be transferred to the outer surface.');
$assert(str_contains($nested['blocks'][0]['innerBlocks'][0]['attrs']['className'], 'painted'), 'The painted box keeps its source selector identity.');

$sizing = $transform('<style>.surface{width:200px;padding:2rem;background:#eee;box-sizing:content-box}p{margin:0;box-sizing:border-box}</style><div class="surface"><p>Separate sizing owners</p></div>');
$assert('core/group' === $sizing['blocks'][0]['blockName'], 'A child box-sizing rule must not change the padded parent sizing model.');
$assert('core/paragraph' === $sizing['blocks'][0]['innerBlocks'][0]['blockName'], 'The sizing boundary retains a native editable paragraph.');

// Font-relative geometry is resolved against each box's own text cascade.
// A neutral child can still change the parent's geometry after absorption.
$textBoundary = $transform('<style>.surface{padding:2em;background:#eee}.copy{margin:0;font-size:10px}</style><div class="surface"><p class="copy">Independent text cascade</p></div>');
$assert('core/group' === $textBoundary['blocks'][0]['blockName'], 'Font-relative parent geometry retains its text inheritance boundary.');
$assert('core/paragraph' === $textBoundary['blocks'][0]['innerBlocks'][0]['blockName'], 'The text cascade remains on the editable paragraph.');

// Responsive padding carriers and normal-flow text inside a flex item retain
// the outer box, rather than promoting its sizing onto an unrelated ancestor.
$responsive = $transform('<style>*{box-sizing:border-box}.container{--gutter:2rem;width:100%;padding-right:calc(var(--gutter)/2);padding-left:calc(var(--gutter)/2);margin:auto}.row{display:flex}.surface{padding:3rem;background:#eee}.copy{margin:0;line-height:1.75}@media(min-width:900px){.container{max-width:960px}}</style><div class="container"><div class="neutral"><div class="row"><div class="surface"><p class="copy">Responsive copy</p></div></div><p>Sibling</p></div></div>');
$container = $responsive['blocks'][0];
$assert(str_contains($container['attrs']['className'], 'neutral'), 'A neutral branching child shares the responsive padded container.');
$assert(2 === count($container['innerBlocks']), 'Container absorption removes only the redundant child boundary.');
$paragraph = $container['innerBlocks'][0]['innerBlocks'][0];
$assert('core/paragraph' === $paragraph['blockName'], 'A painted flex item can absorb its margin-free paragraph.');
$assert(str_contains($paragraph['attrs']['className'], 'surface'), 'The paragraph retains the painted flex item identity.');
$assert('pass' === $responsive['source_reports']['wp_block_validity']['status'], 'Responsive absorption stays editor-valid.');

$lineBox = $transform('<style>.surface{padding:2lh;background:#eee}p{margin:0;line-height:2}</style><div class="surface"><p>Line-relative sizing</p></div>');
$assert('core/group' === $lineBox['blocks'][0]['blockName'], 'Line-height-relative parent geometry retains its text boundary.');

// A universal zero-padding reset is not evidence of a padded surface.
// In particular, two independently animated boxes must not share one host.
$animation = $transform('<style>*{box-sizing:border-box;margin:0;padding:0}.outer{position:absolute;right:6%;top:50%;transform:translateY(-50%);animation:appear 2s both;pointer-events:none}.inner{animation:float 8s infinite}</style><div class="outer" aria-hidden="true"><div class="inner"><h2>Floating illustration</h2><p>Caption</p></div></div>');
$assert(!str_contains($animation['blocks'][0]['attrs']['className'] ?? '', 'inner'), 'A reset-only parent must not absorb the independently animated child.');
$assert(str_contains($animation['serialized_blocks'], 'inner'), 'The inner animation keeps its own saved host.');

fwrite(STDOUT, "Neutral child wrapper absorption passed\n");
