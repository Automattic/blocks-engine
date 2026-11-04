<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

// Bootstrap hides `.navbar` for print. A print-only rule never draws a screen
// box, so the screen conversion must match the same page without it.
$source = file_get_contents(dirname(__DIR__) . '/fixtures/navigation-print-hidden-landmark.html');
$withoutPrint = str_replace('@media print{.navbar{display:none}}', '', $source);
if ( $withoutPrint === $source ) {
    throw new RuntimeException('fixture lost its print rule');
}

foreach ( array( '@media print', '@media only print', '@media speech' ) as $query ) {
    $withPrint = (new HtmlTransformer())->transform(str_replace('@media print', $query, $source))->toArray()['serialized_blocks'];
    $screenOnly = (new HtmlTransformer())->transform($withoutPrint)->toArray()['serialized_blocks'];
    // Native inner-subject marker namespaces include stylesheet context;
    // a dormant print rule may change their hash, but not their topology.
    $normalize = static fn(string $markup): string => preg_replace('/blocks-engine-semantic-[a-f0-9]+-/', 'blocks-engine-semantic-context-', $markup) ?? $markup;
    if ( $normalize($withPrint) !== $normalize($screenOnly) ) {
        throw new RuntimeException("$query rule changed the screen conversion");
    }
    if ( str_contains($withPrint, '"orientation":"vertical"') ) {
        throw new RuntimeException("$query rule stacked the desktop menu row");
    }
}

echo "Print media is not a viewport variant passed\n";
