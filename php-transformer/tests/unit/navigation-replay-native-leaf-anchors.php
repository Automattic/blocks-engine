<?php
declare(strict_types=1);

/**
 * Projection lowers authored subjects onto paragraph and image carriers, whose
 * own links then appear as `a` in the projected arms. Those anchors are never
 * core/navigation items: no `<nav>` survives inside a `<p>`, and a navigation
 * link's parent is its item, not a core/image wrapper. Replaying such arms as
 * navigation only adds theme CSS that no element matches. Source navigation
 * anchors still replay.
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\WordPressCompatCss;

$failures = array();
$project = static fn (string $css): string => (new WordPressCompatCss())->projectNavigationStylesheet($css, array());

$leaves = array(
    'p.blocks-engine-inline-layout-carrier > a > *',
    ':where(.blocks-engine-image).wp-block-image > a > img',
    ':is(:where(.blocks-engine-image).wp-block-image:where(.x)) > a > img',
    ':where(:where(.blocks-engine-image).wp-block-image > a > img)',
    '.footer p.note a',
);
foreach ($leaves as $selector) {
    $projected = $project('@layer base{' . $selector . '{margin:0}}');
    if (str_contains($projected, 'wp-block-navigation')) $failures[] = 'native leaf anchor was replayed as navigation: ' . $selector . ' => ' . $projected;
}

foreach (array('.sitenav a', '.site-header .menu > a', 'p + .menu a', 'p ~ .menu a', '.menu:has(p) a') as $selector) {
    $projected = $project('@layer base{' . $selector . '{color:#0a7d55}}');
    if (!str_contains($projected, 'wp-block-navigation-item__content')) $failures[] = 'source navigation anchor was not replayed: ' . $selector;
}

if (array() !== $failures) {
    fwrite(STDERR, implode(PHP_EOL, $failures) . PHP_EOL);
    exit(1);
}
echo "Navigation replay skips native leaf anchors passed\n";
