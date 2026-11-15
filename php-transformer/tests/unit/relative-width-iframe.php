<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    ++$assertions;
    if ( ! $condition ) {
        throw new RuntimeException($message);
    }
};

$source = <<<'HTML'
<!doctype html><html><head><title>Map</title></head><body><main><h1>Map</h1>
<iframe title="Map" width="100%" height="470" allowfullscreen src="https://maps.google.com/maps?q=34.05%2C-118.38&amp;z=14&amp;output=embed"></iframe>
</main></body></html>
HTML;

foreach ( array(
    'attributes' => $source,
    'resolved height' => str_replace('height="470"', 'style="height:470px"', $source),
    'resolved dimensions' => str_replace('width="100%" height="470"', 'class="map"', str_replace('</head>', '<style>.map{width:100%;height:470px}</style></head>', $source)),
) as $label => $html ) {
    $result = (new HtmlTransformer())->transform($html)->toArray();
    $markup = (string) ($result['serialized_blocks'] ?? '');
    $assert(str_contains($markup, 'wp:custom/visual-iframe'), $label . ': container-relative width and positive height use visual-iframe');
    if ( 'attributes' === $label ) {
        $assert(str_contains($markup, 'width="100%" height="470"'), 'saved iframe preserves the explicit relative width and positive height');
        $assert(str_contains($markup, '"width":"100%"') && str_contains($markup, '"height":"470"'), 'visual-iframe attributes preserve the reduced page dimensions');
    } else {
        $css = implode("\n", array_column($result['assets'] ?? array(), 'content'));
        $assert(str_contains($css . $markup, 'height:470px'), $label . ': source CSS continues to own the explicit height');
    }
    $assert(array() === ($result['fallbacks'] ?? null), $label . ': no fallbacks are reported');
}

foreach ( array(
    'both relative' => 'width="100%" height="100%"',
    'relative height' => 'width="600" height="100%"',
    'zero height' => 'width="100%" height="0"',
) as $label => $dimensions ) {
    $result = (new HtmlTransformer())->transform(str_replace('width="100%" height="470"', $dimensions, $source))->toArray();
    $markup = (string) ($result['serialized_blocks'] ?? '');
    $assert(! str_contains($markup, 'wp:custom/visual-iframe'), $label . ': unbounded iframe does not use visual-iframe');
    $assert(array() !== ($result['fallbacks'] ?? array()), $label . ': unbounded iframe keeps its fallback');
}

echo "Relative width iframe tests passed ({$assertions} assertions)\n";
