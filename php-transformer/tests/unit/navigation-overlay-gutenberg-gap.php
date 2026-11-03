<?php
declare(strict_types=1);

/**
 * A menu converted to Core's overlay gains Core's separate close button, so the
 * source report names that Gutenberg gap; a menu without an overlay does not.
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

$failures = 0;
$links = '<a href="/a">A</a><a href="/b">B</a><a href="/c">C</a>';
foreach ( array(
    'toggled menu' => array('<header><a href="/">Brand</a><button class="mobile-menu" aria-controls="nav" aria-expanded="false">Menu</button><nav id="nav" aria-label="Main navigation">' . $links . '</nav></header><main><p>Body</p></main>', array('navigation-overlay-close-control')),
    'plain menu'   => array('<header><a href="/">Brand</a><nav aria-label="Main navigation">' . $links . '</nav></header><main><p>Body</p></main>', array()),
) as $case => [$html, $expected] ) {
    $result = ( new HtmlTransformer() )->transform($html, array())->toArray();
    $gaps = array_column($result['source_reports']['gutenberg_gaps'] ?? array(), 'id');
    if ( $gaps !== $expected ) {
        ++$failures;
        fwrite(STDERR, "FAIL: {$case} gaps " . json_encode($gaps) . "\n" . ($result['serialized_blocks'] ?? '') . "\n");
    }
}
if ( $failures > 0 ) {
    exit(1);
}
echo "navigation overlay gutenberg gap: pass\n";
