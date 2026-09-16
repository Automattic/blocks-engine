<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

$out = ( new HtmlTransformer() )->transform(
    '<main><button class="lightbox" type="button">'
    . '<span class="v6-visually-hidden" aria-hidden="true">View fullsize</span>'
    . '<img src="logo.png" alt="Hero logo overlay">'
    . '</button></main>'
)->toArray();
$markup = (string) ( $out['serialized_blocks'] ?? '' );

if ( ! str_contains($markup, '<!-- wp:image') || ! str_contains($markup, 'src="logo.png"') || ! str_contains($markup, 'alt="Hero logo overlay"') ) {
    fwrite(STDERR, "FAIL: lightbox image button must emit core/image with its src and alt\n" . $markup . "\n");
    exit(1);
}
if ( str_contains($markup, 'Hero logo overlay') && preg_match('/wp:button[\s\S]*Hero logo overlay/', $markup) ) {
    fwrite(STDERR, "FAIL: overlay alt must not become core/button label text\n" . $markup . "\n");
    exit(1);
}

$templated = ( new HtmlTransformer() )->transform(
    '<main><button class="lightbox" type="button">'
    . '<span class="v6-visually-hidden">View fullsize</span>'
    . '<img src="logo.png" alt="Hero logo overlay">'
    . '<template class="js-cdk-image-lightbox-template"><div>Dialog copy that must not become a button label</div></template>'
    . '</button></main>'
)->toArray();
$templatedMarkup = (string) ( $templated['serialized_blocks'] ?? '' );
if ( ! str_contains($templatedMarkup, '<!-- wp:image') || ! str_contains($templatedMarkup, 'src="logo.png"') ) {
    fwrite(STDERR, "FAIL: lightbox template contents must not hide the content image\n" . $templatedMarkup . "\n");
    exit(1);
}
if ( preg_match('/wp:button[\s\S]*Hero logo overlay/', $templatedMarkup) ) {
    fwrite(STDERR, "FAIL: templated lightbox overlay alt must not become core/button label text\n" . $templatedMarkup . "\n");
    exit(1);
}

fwrite(STDOUT, "image carrier hidden label tests: passed\n");
