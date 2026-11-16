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
echo "Shared button source display: passed\n";
