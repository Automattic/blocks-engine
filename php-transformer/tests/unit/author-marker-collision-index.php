<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\AuthorStyleAnalysis;

$count = 0;
$assert = static function (bool $condition, string $message) use (&$count): void {
    ++$count;
    if ( ! $condition ) {
        throw new RuntimeException($message);
    }
};

$seedOf = static fn (string $identity): string => substr(hash('sha256', $identity), 0, 12);
$bodyOf = static function (string $html): DOMElement {
    $document = new DOMDocument();
    $document->loadHTML($html);
    $body = $document->getElementsByTagName('body')->item(0);
    if ( ! $body instanceof DOMElement ) {
        throw new RuntimeException('No body in test HTML.');
    }
    return $body;
};

// A page whose HTML already states the marker the transform would allocate:
// the allocator must skip to the next counter, byte-identically to the scan.
$seed = $seedOf("tag\0p");
$html = '<html><body><p class="blocks-engine-source-p-' . $seed . '-0">Collision</p></body></html>';
$styles = new AuthorStyleAnalysis($html, '', array(), $bodyOf($html));
$assert('blocks-engine-source-p-' . $seed . '-1' === $styles->allocateStableMarker('source-p', "tag\0p"), 'a source collision moves to the next counter');

// The prefix alone must not fabricate a collision: only a stated marker does.
$other = $seedOf("tag\0blockquote");
$html = '<html><body>blocks-engine-source-p-' . $other . '</body></html>';
$styles = new AuthorStyleAnalysis($html, '', array(), $bodyOf($html));
$assert('blocks-engine-source-p-' . $other . '-0' === $styles->allocateStableMarker('source-p', "tag\0blockquote"), 'a bare prefix without a marker never collides');

// Allocations must match a straight str_contains() scan on sources chosen to
// separate a marker stated exactly from text that merely shares a prefix.
$stated = array(
    'exact' => static fn (string $kind, string $seed): string => 'blocks-engine-' . $kind . '-' . $seed . '-0',
    'longer' => static fn (string $kind, string $seed): string => 'blocks-engine-' . $kind . '-' . $seed . '-01',
    'uppercase' => static fn (string $kind, string $seed): string => 'BLOCKS-ENGINE-' . $kind . '-' . $seed . '-0',
    'broken' => static fn (string $kind, string $seed): string => 'blocks-engine-' . $kind . '-' . $seed . '.-0',
    'nine' => static fn (string $kind, string $seed): string => 'blocks-engine-' . $kind . '-' . $seed . '-9',
    'nested' => static fn (string $kind, string $seed): string => 'blocks-engine-x-blocks-engine-' . $kind . '-' . $seed . '-0',
);
$cases = array(
    // An exact token in the HTML: the counter moves past it.
    array('exact html token', 'table', "tag\0table", 'html', 'exact'),
    // `-01` contains `-0` as a substring, so `-0` collides and `-1` is free.
    array('longer counter in css', 'richtext', "tag\0richtext", 'css', 'longer'),
    // A marker for another kind with the same seed is a different string.
    array('other kind, same seed', 'attribute', "tag\0attribute", 'html', 'exact'),
    // The scan is case-sensitive; an uppercase prefix states no marker.
    array('uppercase prefix', 'navigation-item', "tag\0nav", 'css', 'uppercase'),
    // Punctuation breaks the marker-character run before the counter.
    array('punctuation before the counter', 'source-nav', "tag\0nav-element", 'html', 'broken'),
    // The same marker stated in both texts still moves to the next counter.
    array('stated in both texts', 'source-section', "tag\0section", 'both', 'exact'),
    // A stated counter leaves every lower counter free.
    array('counter nine stated', 'control', "tag\0control", 'html', 'nine'),
    // A marker nested inside another prefix's marker-character run.
    array('nested in another run', 'native-button', "tag\0button", 'css', 'nested'),
    // Nothing stated at all: the first candidate is free.
    array('nothing stated', 'media-text-image', "tag\0media", 'neither', 'exact'),
);
$htmlParts = array('<html><body>');
$cssParts = array('/* authored */');
foreach ( $cases as $case ) {
    $seed = $seedOf($case[2]);
    if ( 'html' === $case[3] || 'both' === $case[3] ) {
        $htmlParts[] = '<p data-x="x' . $stated[$case[4]]($case[1], $seed) . 'y">t</p>';
    }
    if ( 'css' === $case[3] || 'both' === $case[3] ) {
        $cssParts[] = '.x{content:"z' . $stated[$case[4]]($case[1], $seed) . 'w"}';
    }
}
$html = implode("\n", $htmlParts) . '</body></html>';
$css = implode("\n", $cssParts);
$styles = new AuthorStyleAnalysis($html, $css, array(), $bodyOf($html));
foreach ( $cases as $case ) {
    $seed = $seedOf($case[2]);
    $counter = 0;
    while ( str_contains($html, 'blocks-engine-' . $case[1] . '-' . $seed . '-' . $counter) || str_contains($css, 'blocks-engine-' . $case[1] . '-' . $seed . '-' . $counter) ) {
        ++$counter;
    }
    $assert(
        'blocks-engine-' . $case[1] . '-' . $seed . '-' . $counter === $styles->allocateStableMarker($case[1], $case[2]),
        'stable marker for ' . $case[0] . ' matches the scan'
    );
}

// The document-seeded allocator shares the guarantee, starting from the
// counter slot the specificity shims reserved, against a source stating one
// of its own markers.
$htmlWithoutMarker = '<html><body><div><!--  --><p>x</p></div></body></html>';
$probe = new AuthorStyleAnalysis($htmlWithoutMarker, '', array(), $bodyOf($htmlWithoutMarker));
$documentSeed = (new ReflectionProperty(AuthorStyleAnalysis::class, 'markerSeed'))->getValue($probe);
$html = '<html><body><div><!-- blocks-engine-control-' . $documentSeed . '-3 --><p>x</p></div></body></html>';
$styles = new AuthorStyleAnalysis($html, '', array(), $bodyOf($html));
$assert($documentSeed === (new ReflectionProperty(AuthorStyleAnalysis::class, 'markerSeed'))->getValue($styles), 'stated markers are stripped from the seed identity');
$counter = 3;
for ( $i = 0; $i < 40; ++$i ) {
    $expected = '';
    do {
        $expected = 'blocks-engine-control-' . $documentSeed . '-' . $counter++;
    } while ( str_contains($html, $expected) );
    $assert($expected === $styles->allocateMarker('control'), 'document marker ' . $i . ' matches the scan');
}

echo "Author marker collision index tests passed ({$count} assertions)\n";
