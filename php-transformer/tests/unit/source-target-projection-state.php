<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Session\SourceTargetProjectionState;

$state = new SourceTargetProjectionState();
$state->record('nav:nth-of-type(1) > ul:nth-of-type(1)', '.wp-block-navigation__container', 'padding:0!important');
$state->record('nav:nth-of-type(2) > ul:nth-of-type(1)', '.wp-block-navigation__container', 'padding:0!important');

for ($index = 3; $index <= 100; ++$index) {
    $state->record('nav:nth-of-type(' . $index . ')', '.wp-block-navigation__container', 'padding:0!important');
}
$state->record('nav:nth-of-type(101)', '.wp-block-navigation__responsive-container', 'margin:0!important');

if (100 !== count($state->correspondences()) || array(
    '.wp-block-navigation__container{padding:0!important}',
    '.wp-block-navigation__responsive-container{margin:0!important}',
) !== $state->rules()) {
    fwrite(STDERR, "Source-target projection state contract failed\n");
    exit(1);
}

fwrite(STDOUT, "Source-target projection state contract passed\n");
