<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

$out = (new HtmlTransformer())->transform(
    '<style>.frame img{width:100%;height:100%;object-fit:cover;object-position:center 25%}'
    . '@media(max-width:600px){.frame img{object-position:20% 30%!important}}'
    . '.frame img{filter:saturate(.9)}'
    . '.portrait{object-position:75% 10%}'
    . '.frame img,.portrait{opacity:.9}</style>'
    . '<div class="frame"><img class="portrait" src="portrait.jpg" alt="Portrait"></div>'
)->toArray();
$css = implode('', array_column($out['source_reports']['author_stylesheet_projections'], 'content'));
$fail = static function (string $message) use ($css): never {
    fwrite(STDERR, "FAIL: $message\n$css\n");
    exit(1);
};
foreach (array('center 25%', '20% 30%!important') as $position) {
    if (!str_contains($css, 'object-position:' . $position)) $fail('authored leaf focal point lost');
}
preg_match_all('/([^{}]+)\{([^{}]*)\}/', $css, $rules, PREG_SET_ORDER);
$movedBridge = false;
foreach ($rules as $rule) {
    if (!str_contains($rule[1], '> img')) continue;
    if (str_contains($rule[1], '.frame ') && str_contains($rule[2], 'object-position:')) {
        $fail('bare image bridge must not override the leaf focal point');
    }
    if (str_contains($rule[1], '.portrait.wp-block-image') && str_contains($rule[2], 'object-position:inherit')) {
        $movedBridge = true;
    }
}
if (!$movedBridge) $fail('class-owned focal points must still inherit from the figure');
echo "Image leaf focal point cascade passed\n";
