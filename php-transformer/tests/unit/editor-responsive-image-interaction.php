<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\ThemeBootstrap;

// Exercise the public theme bootstrap output, not the compatibility constant.
$bootstrap = ThemeBootstrap::functionsPhp(array());
foreach (array(
    ':root .editor-styles-wrapper .block-editor-block-list__block.wp-block-image:is(.blocks-engine-synthetic-image-figure-inline,.blocks-engine-synthetic-flex-image-figure){display:block!important}',
    ':root .editor-styles-wrapper .block-editor-block-list__block:is(.data-blocks-engine-capture-desktop-only,.data-blocks-engine-capture-mobile-only){display:block!important}',
) as $rule) {
    if (!str_contains($bootstrap, $rule)) {
        throw new RuntimeException('Generated editor settings must expose responsive images and their selectable figure boxes.');
    }
}
echo "editor-responsive-image-interaction: passed\n";
