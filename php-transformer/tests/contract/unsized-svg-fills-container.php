<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\WordPress\Runtime;

/**
 * Contract coverage for an inline SVG with a viewBox and no size of its own.
 *
 * Such an SVG has an aspect ratio but no intrinsic size, so CSS gives it the
 * width of its containing block. Its native image keeps that: the image fills
 * its figure at the viewBox ratio instead of rendering at the viewBox's pixel
 * width. An SVG whose own attributes size it keeps that size.
 */

$assert = static function (bool $condition, string $message): void {
    if (! $condition) {
        throw new RuntimeException($message);
    }
};

$path = '<path d="M60 250V150l140-70 140 70v100Z" fill="#9b6"/><circle cx="200" cy="120" r="30" fill="#c63"/>';
$html = '<!doctype html><html><head><title>Roots</title><style>img,svg{max-width:100%;display:block}.art{width:546px;padding:36px;background:#eef3e6}.badge{width:300px}</style></head><body><main>'
    . '<section><h2>Roots</h2><div class="art"><svg viewBox="0 0 400 300" role="img" aria-label="A glasshouse">' . $path . '</svg></div></section>'
    . '<section><h2>Badge</h2><div class="badge"><svg viewBox="0 0 400 300" width="120" height="90" role="img" aria-label="A badge">' . $path . '</svg></div></section>'
    . '</main></body></html>';

$result = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array('index.html' => $html)))->toArray();
$plan   = $result['source_reports']['wordpress_site_plan'] ?? array();
$page   = (string) (current($plan['pages'] ?? array())['canonical_block_markup'] ?? '');
$css    = (string) json_encode($result);

$images = array();
$walk   = static function (array $blocks) use (&$walk, &$images): void {
    foreach ($blocks as $block) {
        if ('core/image' === ($block['blockName'] ?? null)) {
            $images[] = $block;
        }
        $walk($block['innerBlocks'] ?? array());
    }
};
$walk((new Runtime())->parseBlocks($page));
$assert(2 === count($images), 'Both SVGs become native images.');

$carrier = static function (array $image): string {
    return preg_match('/\bbe-inline-geometry-[a-f0-9]{16}\b/', (string) ($image['attrs']['className'] ?? ''), $m) ? $m[0] : '';
};
$rule = static function (string $class) use ($css): string {
    return '' !== $class && preg_match('/\.wp-block-image\.' . $class . '>img,\.wp-block-image\.' . $class . '>a>img\{([^}]*)\}/', stripslashes($css), $m) ? $m[1] : '';
};

$fill = $rule($carrier($images[0]));
$assert(1 === preg_match('/(?:^|;)width:100%(?:;|$)/', $fill) && str_contains($fill, 'height:auto'), 'The unsized SVG image fills its containing block width: ' . $fill);
$assert(! isset($images[0]['attrs']['width']) && ! isset($images[0]['attrs']['height']), 'The unsized SVG image carries no viewBox pixel size.');

$sized = $rule($carrier($images[1]));
$assert(1 !== preg_match('/(?:^|;)width:100%(?:;|$)/', $sized), 'An SVG sized by its own attributes keeps that size: ' . $sized);
$assert(1 === preg_match('/(?:^|;)width:auto(?:;|$)/', $sized), 'The sized SVG image keeps its own width: ' . $sized);

echo "Unsized SVG fills container contract passed.\n";
