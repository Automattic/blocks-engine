<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

$placeholder = 'data:image/gif;base64,R0lGODlhAQABAAD/ACwAAAAAAQABAAACADs=';
$contexts = array(
    'lowered control' => array('<button type="button" aria-label="Choose photo" aria-pressed="false">%s</button>', 'authored-button'),
    'standalone' => array('<main>%s</main>', '<!-- wp:image'),
    'preserved table markup' => array('<table><tr><td>%s</td></tr></table>', '<!-- wp:table'),
);
$cases = array(
    'placeholder' => array($placeholder, 'data-src="/photo.jpg"', '/photo.jpg'),
    'empty source' => array('', 'data-src="/photo.jpg"', '/photo.jpg'),
    'lazy source' => array($placeholder, 'data-lazy-src="/photo.jpg"', '/photo.jpg'),
    'original source' => array($placeholder, 'data-original="/photo.jpg"', '/photo.jpg'),
    'image source' => array($placeholder, 'data-image-src="/photo.jpg"', '/photo.jpg'),
    'real source wins' => array('/already.jpg', 'data-src="/photo.jpg"', '/already.jpg'),
    'attribute priority' => array($placeholder, 'data-src="/first.jpg" data-original="/second.jpg"', '/first.jpg'),
    'unsafe source skipped' => array($placeholder, 'data-src="javascript:alert(1)" data-original="/safe.jpg"', '/safe.jpg'),
    'unsafe source only' => array($placeholder, 'data-src="javascript:alert(1)"', $placeholder),
    'no alternative' => array($placeholder, '', $placeholder),
);
foreach ( $contexts as $context => [$template, $marker] ) {
    foreach ( $cases as $name => [$src, $attributes, $expected] ) {
        $image = '<img src="' . $src . '" ' . $attributes . ' alt="Photo">';
        $out = (new HtmlTransformer())->transform(sprintf($template, $image))->toArray();
        $markup = (string) ($out['serialized_blocks'] ?? '');
        $document = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $document->loadHTML($markup);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        $images = $document->getElementsByTagName('img');
        if ( ! str_contains($markup, $marker) || 0 === $images->length ) {
            fwrite(STDERR, "FAIL: $context / $name must retain its image and conversion path\n$markup\n");
            exit(1);
        }
        foreach ( $images as $outputImage ) {
            if ( $expected !== $outputImage->getAttribute('src') ) {
                fwrite(STDERR, "FAIL: $context / $name expected src=$expected\n$markup\n");
                exit(1);
            }
        }
    }
}
fwrite(STDOUT, "lazy image source normalization tests: passed\n");
