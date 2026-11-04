<?php
declare(strict_types=1);

require __DIR__ . '/../../vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

/**
 * A heading with no text carries no heading semantics, so its one media
 * element lowers as the media block it visually is (#4303): an iframe takes
 * the standalone embed route exactly as a bare iframe does, and an image
 * takes the paragraph image route, keeping its source, width and alignment.
 * `<br>` line breaks and whitespace are not text. Regression fixtures are
 * the two Mozello pages from the issue.
 *
 * Headings that hold any text keep their RichText image behavior (#3496),
 * several media elements or non-media content keep the heading routes, and
 * the paragraph route is untouched: a `<br>` beside a paragraph image is
 * still RichText there.
 */

$findBlock = static function (array $blocks, string $name) use (&$findBlock): ?array {
    foreach ( $blocks as $block ) {
        if ( $name === ($block['blockName'] ?? '') ) {
            return $block;
        }
        $nested = $findBlock($block['innerBlocks'] ?? array(), $name);
        if ( null !== $nested ) {
            return $nested;
        }
    }
    return null;
};
$transform = static fn (string $html, array $options = array()): array => ( new HtmlTransformer() )->transform($html, $options)->toArray();
$fixture   = static fn (string $name): string => (string) file_get_contents(dirname(__DIR__) . '/fixtures/' . $name);

$failures = array();

// 1. The issue's iframe page: the heading-wrapped YouTube iframe becomes the
//    same core/embed a bare iframe produces, with no core/html island.
$result = $transform($fixture('issue-4303-heading-iframe.html'));
$markup = (string) ($result['serialized_blocks'] ?? '');
$embed  = $findBlock($result['blocks'] ?? array(), 'core/embed');
if ( null === $embed
    || str_contains($markup, '<!-- wp:html')
    || 'https://www.youtube.com/watch?v=9l3IKCl79mo' !== ($embed['attrs']['url'] ?? '')
    || 'youtube' !== ($embed['attrs']['providerNameSlug'] ?? '')
    || null !== $findBlock($result['blocks'] ?? array(), 'core/heading')
    || 'pass' !== ($result['source_reports']['wp_block_validity']['status'] ?? '') ) {
    $failures[] = 'Text-less heading iframe lowers to the core/embed route: ' . $markup;
}

// 2. The issue's image page: the heading-wrapped logo becomes a core/image
//    block with its width, alignment class and source preserved, and the
//    stylesheet centering still addresses the figure through its kept class.
$result = $transform($fixture('issue-4303-heading-image.html'));
$markup = (string) ($result['serialized_blocks'] ?? '');
$image  = $findBlock($result['blocks'] ?? array(), 'core/image');
$sheet  = implode("\n", array_column(
    array_filter($result['assets'] ?? array(), static fn (array $asset): bool => 'css' === ($asset['kind'] ?? '')),
    'content'
));
if ( null === $image
    || str_contains($markup, '<!-- wp:html')
    || ! str_contains($markup, 'src="https://example.com/logo.png"')
    || ! str_contains($markup, 'style="width:655px')
    || ! str_contains($markup, 'img-center')
    || null !== $findBlock($result['blocks'] ?? array(), 'core/heading')
    || ! preg_match('/\.img-center[^{}]*\{[^{}]*margin:0 auto/', $sheet)
    || ! preg_match('/\.be-inline-geometry-[a-f0-9]+\{width:655px/', $sheet)
    || 'pass' !== ($result['source_reports']['wp_block_validity']['status'] ?? '') ) {
    $failures[] = 'Text-less heading image lowers to a sized, centered core/image: ' . $markup;
}

// 3. Both routes fire on every heading level, with or without a trailing br.
foreach ( range(1, 6) as $level ) {
    foreach ( array( '', '<br>' ) as $break ) {
        $result = $transform('<h' . $level . '><iframe src="https://www.youtube.com/embed/9l3IKCl79mo" allowfullscreen width="464" height="261"></iframe>' . $break . '</h' . $level . '>');
        $embed  = $findBlock($result['blocks'] ?? array(), 'core/embed');
        $markup = (string) ($result['serialized_blocks'] ?? '');
        if ( null === $embed
            || str_contains($markup, '<!-- wp:html')
            || 'https://www.youtube.com/watch?v=9l3IKCl79mo' !== ($embed['attrs']['url'] ?? '') ) {
            $failures[] = 'h' . $level . ' iframe lowers to core/embed' . ( '' === $break ? '' : ' with a trailing br' ) . ': ' . $markup;
        }

        $result = $transform('<h' . $level . '><img alt="L" src="/media/logo.png" width="655" style="width:655px" class="img-center">' . $break . '</h' . $level . '>');
        $image  = $findBlock($result['blocks'] ?? array(), 'core/image');
        $markup = (string) ($result['serialized_blocks'] ?? '');
        if ( null === $image
            || str_contains($markup, '<!-- wp:html')
            || ! str_contains($markup, 'src="/media/logo.png"')
            || null !== $findBlock($result['blocks'] ?? array(), 'core/heading') ) {
            $failures[] = 'h' . $level . ' image lowers to core/image' . ( '' === $break ? '' : ' with a trailing br' ) . ': ' . $markup;
        }
    }
}

// 4. Custom iframe elements take their own embed route from a text-less
//    heading, wrapping the embed in the host group the standalone route uses.
$result = $transform('<main><h2><media-iframe data-url="https://www.youtube.com/embed/dQw4w9WgXcQ" width="560" height="315"></media-iframe></h2></main>');
$markup = (string) ($result['serialized_blocks'] ?? '');
if ( str_contains($markup, '<!-- wp:html')
    || ! str_contains($markup, 'https://www.youtube.com/watch?v=dQw4w9WgXcQ')
    || 'pass' !== ($result['source_reports']['wp_block_validity']['status'] ?? '') ) {
    $failures[] = 'Text-less heading custom iframe keeps its embed route: ' . $markup;
}

// 5. Headings with text, several media elements, or non-media content keep
//    today's routes; the paragraph route is untouched by the line breaks.
$boundaries = array(
    'heading text with an iframe stays core/html'      => array( '<h1>Watch<iframe src="https://www.youtube.com/embed/9l3IKCl79mo" width="464" height="261"></iframe></h1>', 'core/html' ),
    'heading with two iframes stays core/html'         => array( '<h1><iframe src="https://a.test/e1" width="10" height="10"></iframe><iframe src="https://a.test/e2" width="10" height="10"></iframe></h1>', 'core/html' ),
    'heading text with an image stays a heading'       => array( '<h2><img alt="L" src="/m.png" width="40" height="40"> Title</h2>', 'core/heading' ),
    'heading with only a button keeps a heading'       => array( '<h2><button type="button">Open</button></h2>', 'core/heading' ),
    'paragraph image keeps its br as RichText'         => array( '<p><img alt="L" src="/m.png" width="40" height="40"><br></p>', 'core/paragraph' ),
    'heading br keeps its box'                         => array( '<h1><br></h1>', 'core/heading' ),
);
foreach ( $boundaries as $label => [ $html, $expected ] ) {
    $result = $transform($html);
    if ( $expected !== ($result['blocks'][0]['blockName'] ?? '') ) {
        $failures[] = 'Boundary: ' . $label . ': ' . (string) ($result['serialized_blocks'] ?? '');
    }
}

// 6. A bare iframe beside the lowered heading route still produces the same
//    embed the heading route produces, so both paths stay in step.
$standalone = $transform('<main><iframe src="https://www.youtube.com/embed/9l3IKCl79mo" allowfullscreen width="464" height="261"></iframe></main>');
$fromHeading = $transform('<main><h1><iframe src="https://www.youtube.com/embed/9l3IKCl79mo" allowfullscreen width="464" height="261"></iframe></h1></main>');
$standaloneEmbed = $findBlock($standalone['blocks'] ?? array(), 'core/embed');
$headingEmbed    = $findBlock($fromHeading['blocks'] ?? array(), 'core/embed');
$sameEmbed = static fn (array $embed): array => array(
    'url'              => $embed['attrs']['url'] ?? '',
    'type'             => $embed['attrs']['type'] ?? '',
    'providerNameSlug' => $embed['attrs']['providerNameSlug'] ?? '',
    'responsive'       => $embed['attrs']['responsive'] ?? null,
    'aspect'           => str_contains((string) ($embed['attrs']['className'] ?? ''), 'wp-embed-aspect-16-9 wp-has-aspect-ratio'),
    'figure'           => str_contains((string) ($embed['attrs']['className'] ?? ''), 'blocks-engine-synthetic-embed-figure'),
);
if ( null === $standaloneEmbed || null === $headingEmbed
    || $sameEmbed($standaloneEmbed) !== $sameEmbed($headingEmbed) ) {
    $failures[] = 'The heading route produces the embed a bare iframe produces: '
        . (string) ($fromHeading['serialized_blocks'] ?? '');
}

if ( array() !== $failures ) {
    fwrite(STDERR, implode("\n", $failures) . "\n");
    exit(1);
}

echo "Text-less heading media lowering passed.\n";
