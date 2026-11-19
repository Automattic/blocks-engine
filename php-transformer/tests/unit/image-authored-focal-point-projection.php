<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

// Bare img selectors remain on the leaf. A more specific repair bridge must
// not replace their focal point with the synthetic figure's default center.
$out = (new HtmlTransformer())->transform(
    '<style>.frame img{width:100%;height:100%;object-fit:cover;object-position:center 25%}'
    . '@media(max-width:600px){.frame img{object-position:20% 30%!important}}'
    . '.portrait{object-position:75% 10%}</style>'
    . '<div class="frame"><img class="portrait" src="portrait.jpg" alt="Portrait"></div>'
)->toArray();
$css = implode('', array_column($out['source_reports']['author_stylesheet_projections'], 'content'));
foreach (array('center 25%', '20% 30%!important') as $position) {
    if (!preg_match('/> img\{[^}]*object-position:' . preg_quote($position, '/') . '(?:;|\})/', $css)) {
        fwrite(STDERR, "FAIL: the leaf bridge must preserve authored focal point $position\n$css\n");
        exit(1);
    }
}
// A class subject moves to the source-box figure: its leaf still inherits.
if (!preg_match('/> img\{[^}]*object-position:inherit(?:;|\})/', $css)) {
    fwrite(STDERR, "FAIL: moved class subjects must retain inheritance\n$css\n");
    exit(1);
}
echo "Image authored focal point projection passed\n";
