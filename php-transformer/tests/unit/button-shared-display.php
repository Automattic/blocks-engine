<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

// Shared support styles from another route must not undo the first route's
// source display, after neutralizing core/button's synthetic wrappers.
$pages = array(
    '<style>.action{display:inline-flex;padding:14px 26px;background:red}</style><div><a class="action" href="/book">Book a fitment</a></div>',
    (string) file_get_contents(dirname(__DIR__) . '/fixtures/button-intrinsic-height.html'),
);
foreach ($pages as $html) {
    $result = (new HtmlTransformer())->transform($html)->toArray();
    $css = implode('', array_column(array_filter($result['assets'], static fn(array $a): bool => 'css' === ($a['kind'] ?? '')), 'content'));
    $restore = ':where(.blocks-engine-layout-child-button.blocks-engine-layout-child-button-display-inline-flex)>.wp-block-button__link{display:inline-flex!important}';
    if (!str_contains($css, $restore)) {
        throw new RuntimeException('Every route support sheet must restore inline-flex source buttons');
    }
    if (strpos($css, $restore) < strpos($css, 'display:inline!important')) {
        throw new RuntimeException('Source display restoration must follow the generic inline reset');
    }
}
// A native flex item is flattened onto its link. Its responsive display
// must beat the same important inline repair, not leave the menu label visible.
$result = (new HtmlTransformer())->transform(
    '<style>.row{display:flex}@media(min-width:768px){.desktop-hidden{display:none}}</style>'
    . '<div class="row"><button class="desktop-hidden">Menu</button></div>'
)->toArray();
$css = implode('', array_column(array_filter($result['assets'], static fn(array $a): bool => 'css' === ($a['kind'] ?? '')), 'content'));
if (!preg_match('/@media[^{}]*768px[^{}]*\\{(?:[^{}]*\\{[^{}]*\\})*[^{}]*wp-block-button__link[^{}]*\\{display:none!important\\}/', $css)) {
    throw new RuntimeException('Conditional native button display must outrank the flattened-item inline repair');
}
echo "Shared button source display: passed\n";
