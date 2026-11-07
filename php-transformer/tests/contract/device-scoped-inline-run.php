<?php
declare(strict_types=1);

/**
 * A phrasing run whose items match device-scoped rules stays one inline run.
 *
 * Device-scoped selectors mark their subjects with attribute classes. Those
 * classes ride on inline elements, so they must not split the run into
 * per-child blocks that turn a classed span into a block-level group.
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

$body = '<main><nav class="nav" style="display:flex"><div class="crumbs"><a href="/" class="crumb">Home</a><span class="sep"> / </span><a class="crumb" aria-disabled="true">Product</a></div></nav></main>';
$scope = static fn (string $device): string => ':where([data-blocks-engine-capture-device-document="' . $device . '"]) ';
$html = '<!doctype html><html><head><style data-blocks-engine-capture-device-style="desktop">'
    . $scope('desktop') . '.nav .crumb,' . $scope('desktop') . '.nav .sep{color:rgba(var(--c));font:var(--f)}</style></head><body>'
    . '<div data-blocks-engine-capture-device-document="desktop">' . $body . '</div>'
    . '<div data-blocks-engine-capture-device-document="mobile">' . $body . '</div></body></html>';

$markup = (string) ((new ArtifactCompiler())->compile(array( 'entrypoint' => 'index.html', 'files' => array( 'index.html' => $html ) ))->toArray()['source_reports']['wordpress_site_plan']['pages'][0]['canonical_block_markup'] ?? '');

foreach ( array(
    'every crumb run keeps its spaced separator as an inline paragraph' => 2 === preg_match_all('/<p class="sep blocks-engine-synthetic-paragraph[^"]*"> \/ <\/p>/', $markup),
    'no separator becomes a block-level group' => 0 === preg_match('/<div class="wp-block-group sep\b/', $markup),
    'the desktop separator keeps its device rule marker' => 1 === preg_match('/<p class="sep blocks-engine-synthetic-paragraph blocks-engine-attribute-[a-f0-9]+-0">/', $markup),
) as $label => $ok ) {
    if ( ! $ok ) {
        fwrite(STDERR, "FAIL: {$label}\n");
        exit(1);
    }
}

echo "Device-scoped inline run contract passed\n";
