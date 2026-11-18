<?php
declare(strict_types=1);

/**
 * Issue #6496: a gallery tile is a native <button> around a photo that fills
 * the control plus a caption positioned over it. The image is the control's
 * content, not an icon beside a label, so it must stay an editable core/image
 * inside the button instead of collapsing into core/button alt text.
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

$html = <<<'HTML'
<!doctype html><html lang="en"><head><meta charset="utf-8"><title>Tiles</title>
<style>
img{display:block;width:100%;object-fit:cover}
.grid{display:grid;grid-template-columns:1fr 1fr;grid-template-rows:205px;gap:16px}
.tile{padding:0;border:0;position:relative;overflow:hidden;background:#dfd8c9}
.tile img{height:100%}
.overlay{position:absolute;inset:auto 0 0;padding:20px;color:#fff;background:linear-gradient(transparent,#0009)}
</style></head><body><main><h1>Photos</h1><div class="grid">
<button type="button" class="tile" aria-label="Enlarge photograph: Dome"><img src="dome.jpg" alt="A white building with a golden dome"><span class="overlay">Dome</span></button>
<button type="button" class="tile" aria-label="Enlarge photograph: Street"><img src="street.jpg" alt="A street with trees"><span class="overlay">Street</span></button>
</div></main></body></html>
HTML;

$markup = (string) ( new HtmlTransformer() )->transform($html)->toArray()['serialized_blocks'];
$fail = static function (string $message) use ($markup): never {
    fwrite(STDERR, 'FAIL: ' . $message . PHP_EOL . $markup . PHP_EOL);
    exit(1);
};

if ( str_contains($markup, '<!-- wp:button ') ) {
    $fail('a photo tile must not lower to core/button');
}
foreach ( array( 'dome.jpg' => 'A white building with a golden dome', 'street.jpg' => 'A street with trees' ) as $src => $alt ) {
    if ( 1 !== preg_match('#<!-- wp:image [^>]*-->\s*<figure[^>]*><img src="' . preg_quote($src, '#') . '" alt="' . preg_quote($alt, '#') . '"#', $markup) ) {
        $fail('tile image ' . $src . ' must survive as a core/image with its alt');
    }
}
foreach ( array( 'Dome', 'Street' ) as $caption ) {
    if ( 1 !== substr_count($markup, '>' . $caption . '<') ) {
        $fail('caption ' . $caption . ' must appear exactly once');
    }
}
if ( 2 !== substr_count($markup, '<button aria-label="Enlarge photograph: ') ) {
    $fail('each tile must keep its native button and accessible name');
}

echo "Control-filling image button passed\n";
