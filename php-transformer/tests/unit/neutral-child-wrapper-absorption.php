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

fwrite(STDOUT, "Neutral child wrapper absorption passed\n");
