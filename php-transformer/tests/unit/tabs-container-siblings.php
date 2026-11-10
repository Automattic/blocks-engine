<?php
declare(strict_types=1);

/**
 * A tabs container keeps the siblings it does not consume: a heading before
 * the tablist stays as blocks around core/tabs instead of being dropped, and
 * a sibling between the tablist and its panels declines the pattern.
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

$tabs = static fn (string $between): string => '<section class="detail" id="detail"><div id="detail-heading"><h2>Project heading</h2></div>'
    . '<div class="tabs" role="tablist"><button role="tab" aria-selected="true" aria-controls="p0">Overview</button><button role="tab" aria-selected="false" aria-controls="p1">Notes</button></div>'
    . $between . '<div id="p0" role="tabpanel"><p>Overview text</p></div><div id="p1" role="tabpanel" hidden><p>Notes text</p></div><p>Trailing note</p></section>';
$markup = static fn (string $body): string => (string) ( ( new HtmlTransformer() )->transform('<!doctype html><html><body>' . $body . '</body></html>')->toArray()['serialized_blocks'] ?? '' );

$failures = array();
$wrapped = $markup($tabs(''));
foreach ( array( 'Project heading', 'Trailing note', '<!-- wp:tabs', 'Overview text', 'Notes text' ) as $needle ) {
    if ( ! str_contains($wrapped, $needle) ) {
        $failures[] = "missing {$needle}";
    }
}
if ( ! preg_match('/<!-- wp:group .*?"anchor":"detail".*?Project heading.*?<!-- wp:tabs .*?Notes text.*?Trailing note/s', $wrapped) ) {
    $failures[] = 'expected heading, tabs, trailing note in order inside the container group';
}
$interleaved = $markup($tabs('<p>Between</p>'));
if ( str_contains($interleaved, '<!-- wp:tabs') || ! str_contains($interleaved, 'Between') || ! str_contains($interleaved, 'Project heading') ) {
    $failures[] = 'a sibling between the tablist and panels must decline core/tabs and keep its content';
}

if ( array() !== $failures ) {
    fwrite(STDERR, 'tabs-container-siblings FAILED: ' . implode('; ', $failures) . PHP_EOL);
    exit(1);
}
echo "tabs-container-siblings: passed\n";
