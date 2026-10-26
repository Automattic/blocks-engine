<?php
declare(strict_types=1);

/**
 * Block markup input (#3518): the elements a block saves itself (a cover's
 * background span and image, an image caption, a media & text media figure)
 * stay exactly as written, wherever the block's inner blocks sit. Only HTML in
 * a block's inner-block area without block delimiters is converted.
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

$failures = 0;
$assertions = 0;
$assert = static function (bool $condition, string $message, string $detail = '') use (&$failures, &$assertions): void {
    ++$assertions;
    if ( ! $condition ) {
        ++$failures;
        fwrite(STDERR, 'FAIL: ' . $message . PHP_EOL . ( '' === $detail ? '' : $detail . PHP_EOL ));
    }
};
$serialize = static fn (string $html): string => ( new HtmlTransformer() )->transform($html, array())->toArray()['serialized_blocks'];
$blockNames = static fn (string $markup): array => preg_match_all('/<!-- wp:([a-z][a-z0-9\/-]*)/', $markup, $m) ? $m[1] : array();

$heroSpan = '<span aria-hidden="true" class="wp-block-cover__background has-ink-background-color has-background-dim"></span>';
$heroImage = '<figure class="wp-block-image size-full hero-bg"><img src="https://example.com/hero.jpg" alt="A dog and a cat"/></figure>';
$coverImage = '<img class="wp-block-cover__image-background" alt="" src="https://example.com/a.jpg" data-object-fit="cover"/>';
$caption = '<figcaption class="wp-element-caption">A caption</figcaption>';
$mediaFigure = '<figure class="wp-block-media-text__media"><img src="https://example.com/m.jpg" alt=""/></figure>';

// Each case: input markup and the own elements that must come out byte for byte.
$cases = array(
    'cover with an image block between its background span and inner container' => array(
        'input' => '<!-- wp:cover {"align":"full","className":"hero","isUserOverlayColor":true,"overlayColor":"ink","dimRatio":45} -->
<div class="wp-block-cover alignfull hero">
	' . $heroSpan . '
	<!-- wp:image {"sizeSlug":"full","className":"hero-bg"} -->
	' . $heroImage . '
	<!-- /wp:image -->
	<div class="wp-block-cover__inner-container">
		<!-- wp:heading {"level":1} --><h1 class="wp-block-heading">A happy home</h1><!-- /wp:heading -->
	</div>
</div>
<!-- /wp:cover -->',
        'own' => array( $heroSpan, $heroImage, '<div class="wp-block-cover__inner-container">' ),
    ),
    'cover with an overlay span' => array(
        'input' => '<!-- wp:cover {"align":"full","overlayColor":"ink","isUserOverlayColor":true,"dimRatio":45} -->
<div class="wp-block-cover alignfull">' . $heroSpan . '<div class="wp-block-cover__inner-container"><!-- wp:paragraph --><p>Hello</p><!-- /wp:paragraph --></div></div>
<!-- /wp:cover -->',
        'own' => array( $heroSpan ),
    ),
    'cover with a background image' => array(
        'input' => '<!-- wp:cover {"url":"https://example.com/a.jpg","dimRatio":50,"isUserOverlayColor":true} -->
<div class="wp-block-cover">' . $coverImage . '<span aria-hidden="true" class="wp-block-cover__background has-background-dim"></span><div class="wp-block-cover__inner-container"><!-- wp:paragraph --><p>Hello</p><!-- /wp:paragraph --></div></div>
<!-- /wp:cover -->',
        'own' => array( $coverImage, '<span aria-hidden="true" class="wp-block-cover__background has-background-dim"></span>' ),
    ),
    'image with a caption' => array(
        'input' => '<!-- wp:image {"sizeSlug":"large"} -->
<figure class="wp-block-image size-large"><img src="https://example.com/b.jpg" alt=""/>' . $caption . '</figure>
<!-- /wp:image -->',
        'own' => array( $caption ),
    ),
    'media & text keeps its media figure beside its content area' => array(
        'input' => '<!-- wp:media-text {"mediaType":"image"} -->
<div class="wp-block-media-text is-stacked-on-mobile">' . $mediaFigure . '<div class="wp-block-media-text__content"><!-- wp:paragraph --><p>Beside the media</p><!-- /wp:paragraph --></div></div>
<!-- /wp:media-text -->',
        'own' => array( $mediaFigure ),
    ),
);

foreach ( $cases as $name => $case ) {
    $input = $case['input'];
    $output = $serialize($input);
    foreach ( $case['own'] as $own ) {
        $assert(substr_count($output, $own) === substr_count($input, $own), $name . ': keeps its own element ' . $own, $output);
    }
    $assert($blockNames($output) === $blockNames($input), $name . ': no block is added, removed or moved', $output);
    $assert(false === strpos($output, 'blocks-engine-empty-visual-group'), $name . ': no engine visual group', $output);
    $assert(substr_count($output, '<img') === substr_count($input, '<img'), $name . ': every image is kept', $output);
}

// Stray HTML in a block's inner-block area is still converted.
$stray = array(
    'group' => array( '<!-- wp:group --><div class="wp-block-group"><div class="note"><p>Loose text</p></div></div><!-- /wp:group -->', 'Loose text' ),
    'group beside an inner block' => array( '<!-- wp:group --><div class="wp-block-group"><!-- wp:paragraph --><p>Kept</p><!-- /wp:paragraph --><p>Loose sibling</p></div><!-- /wp:group -->', 'Loose sibling' ),
    'cover inner container beside an inner block' => array( '<!-- wp:cover {"dimRatio":50} --><div class="wp-block-cover">' . $heroSpan . '<div class="wp-block-cover__inner-container"><p>Loose cover text</p><!-- wp:paragraph --><p>Kept</p><!-- /wp:paragraph --></div></div><!-- /wp:cover -->', 'Loose cover text' ),
);
foreach ( $stray as $name => [ $input, $text ] ) {
    $output = $serialize($input);
    $assert(1 === preg_match('/<!-- wp:paragraph[^>]*-->\s*<p[^>]*>' . preg_quote($text, '/') . '<\/p>/', $output), 'stray HTML inside a ' . $name . ' is converted to a block', $output);
}

if ( $failures > 0 ) {
    fwrite(STDERR, sprintf('%d of %d cover own markup assertions failed.', $failures, $assertions) . PHP_EOL);
    exit(1);
}

echo sprintf('cover own markup: %d assertions passed.', $assertions) . PHP_EOL;
