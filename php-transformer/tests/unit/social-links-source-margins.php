<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

$assert = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};
$source = file_get_contents(dirname(__DIR__) . '/fixtures/social-profile-cluster.html');
$reset = ':where(ul.blocks-engine-social-source-non-list){margin-top:0;margin-bottom:0}';
foreach (array('span', 'div') as $tag) {
    $input = str_replace(array('<span class="links">', '</span></div>'), array('<' . $tag . ' class="links">', '</' . $tag . '></div>'), $source);
    $result = (new HtmlTransformer())->transform($input)->toArray();
    $block = $result['blocks'][0]['innerBlocks'][0] ?? array();
    $assert('core/social-links' === ($block['blockName'] ?? ''), 'The inner profile cluster lowers to social-links');
    $markup = $result['serialized_blocks'];
    $assert(str_contains($markup, '<ul class="wp-block-social-links') && str_contains($markup, 'blocks-engine-social-source-non-list'), 'The lowered ul carries the margin repair marker');
    $css = implode("\n", array_column($result['assets'], 'content'));
    $assert(str_contains($css, $reset), 'Compiled CSS resets both UA margins at zero specificity, below all author selectors');
    $assert('pass' === ($result['source_reports']['wp_block_validity']['status'] ?? ''), 'Social-links save markup remains valid');
}

// Source lists keep their original UA margins rather than inheriting this repair.
$list = str_replace(array('<span class="links">', '</span></div>'), array('<ul class="links">', '</ul></div>'), $source);
$result = (new HtmlTransformer())->transform($list)->toArray();
$assert(!str_contains($result['serialized_blocks'], 'blocks-engine-social-source-non-list'), 'Existing lists are not reset');

foreach (array(
    '<style>.links{margin-top:13px;margin-bottom:19px}@media(max-width:600px){.links{margin-top:7px}}</style>' . $source,
    str_replace('class="links"', 'class="links" style="margin-top:13px;margin-bottom:19px"', $source),
    '<style>.links{margin-block:13px 19px}</style>' . $source,
) as $input) {
    $result = (new HtmlTransformer())->transform($input)->toArray();
    $output = $result['serialized_blocks'] . implode("\n", array_column($result['assets'], 'content'));
    $assert(str_contains($output, '13px') && str_contains($output, '19px'), 'Authored physical, logical and inline margins survive lowering');
    $assert(str_contains($output, $reset), 'Authored margins override the zero-specificity default rather than suppressing the repair');
}
echo "Social links source margins passed.\n";
