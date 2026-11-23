<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

$assert = static function (bool $condition, string $message): void {
    if (! $condition) throw new RuntimeException($message);
};
$find = static function (array $blocks) use (&$find): ?array {
    foreach ($blocks as $block) {
        if ('core/embed' === $block['blockName']) return $block;
        $found = $find($block['innerBlocks'] ?? array());
        if (null !== $found) return $found;
    }
    return null;
};
$source = require dirname(__DIR__) . '/fixtures/ratio-box-provider-iframe.php';
$result = (new HtmlTransformer())->transform($source)->toArray();
$embed = $find($result['blocks']);
$assert(null !== $embed, 'The provider frame stays a core/embed.');
$attrs = $embed['attrs'];
$assert('youtube' === ($attrs['providerNameSlug'] ?? '') && 'https://www.youtube.com/watch?v=demo123' === ($attrs['url'] ?? ''), 'A frame filling its ratio box is a provider embed core can oEmbed.');
$assert(! isset($attrs['metadata']['blocksEngineIframe']), 'The provider embed does not fall back to the frame renderer.');
$classes = explode(' ', (string) ($attrs['className'] ?? ''));
$assert(in_array('blocks-engine-fill-embed-figure', $classes, true), 'The embed records that it fills its containing block.');
$assert(in_array('wp-embed-aspect-16-9', $classes, true) && true === ($attrs['responsive'] ?? null), 'The 60% ratio box keeps its nearest core aspect class.');
$assert('pass' === ($result['source_reports']['wp_block_validity']['status'] ?? ''), 'Saved blocks must remain editor-valid.');

// A rule that leaves the frame in flow at some viewport keeps the frame renderer.
$conditional = str_replace('iframe{border:none}', 'iframe{border:none}@media(max-width:600px){.movie iframe{position:static;height:300px}}', $source);
$conditionalEmbed = $find((new HtmlTransformer())->transform($conditional)->toArray()['blocks']);
$assert(null !== $conditionalEmbed && isset($conditionalEmbed['attrs']['metadata']['blocksEngineIframe']), 'Conditionally in-flow frames keep their source-sized frame.');

// The standard embed code's default referrer policy is what core's frame uses too.
$defaultPolicy = str_replace('allowfullscreen=""', 'allowfullscreen="" referrerpolicy="strict-origin-when-cross-origin"', $source);
$defaultPolicyEmbed = $find((new HtmlTransformer())->transform($defaultPolicy)->toArray()['blocks']);
$assert(null !== $defaultPolicyEmbed && 'youtube' === ($defaultPolicyEmbed['attrs']['providerNameSlug'] ?? ''), 'A default referrer policy still takes the provider path.');

$keepsFrame = array(
    'playback parameters' => str_replace('embed/demo123"', 'embed/demo123?start=90"', $source),
    'a sandbox' => str_replace('allowfullscreen=""', 'allowfullscreen="" sandbox="allow-scripts"', $source),
    'a stricter referrer policy' => str_replace('allowfullscreen=""', 'allowfullscreen="" referrerpolicy="no-referrer"', $source),
    'a fixed position' => str_replace('iframe{border:none}', 'iframe{border:none}.movie iframe{position:fixed}', $source),
    'an inline fixed position' => str_replace('allowfullscreen=""', 'allowfullscreen="" style="position:fixed"', $source),
    'lazy playback parameters' => str_replace('src="https://www.youtube.com/embed/demo123"', 'src="about:blank" data-src="https://www.youtube.com/embed/demo123?start=90"', $source),
    'size attributes under zero insets' => str_replace(
        array( '.movie div,.movie iframe{position:absolute;top:0;left:0;display:block;width:100%;height:100%}', 'width="100%" height="444"' ),
        array( '.movie div{position:absolute;top:0;left:0;display:block;width:100%;height:100%}.movie iframe{position:absolute;inset:0}', 'width="320" height="180"' ),
        $source
    ),
    'a viewport-specific offset' => str_replace('iframe{border:none}', 'iframe{border:none}@media(max-width:600px){.movie iframe{top:30px}}', $source),
);
foreach ( $keepsFrame as $case => $html ) {
    $embed = $find((new HtmlTransformer())->transform($html)->toArray()['blocks']);
    $classes = explode(' ', (string) ($embed['attrs']['className'] ?? ''));
    $assert(null === $embed || ! in_array('blocks-engine-fill-embed-figure', $classes, true), "A frame with {$case} keeps the source-sized frame.");
}
echo "PASS: ratio-box provider iframe\n";
