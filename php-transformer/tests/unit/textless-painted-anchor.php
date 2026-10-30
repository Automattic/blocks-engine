<?php
declare(strict_types=1);

/** Text-less CSS-painted links use the native empty-destination link host. */
require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;
use Automattic\BlocksEngine\PhpTransformer\WordPress\BlockValidityValidator;

$failures = 0;
$passes = 0;
$assert = static function (bool $condition, string $message, string $detail = '') use (&$failures, &$passes): void {
    if ( $condition ) {
        ++$passes;
        return;
    }
    ++$failures;
    fwrite(STDERR, 'FAIL: ' . $message . ( '' !== $detail ? ' - ' . $detail : '' ) . PHP_EOL);
};

$page = <<<'HTML'
<!DOCTYPE html>
<html lang="en"><head><meta charset="utf-8"><title>Scroll to top link</title>
<style>
#toTop{display:none;width:46px;height:46px;border-radius:46px;overflow:hidden;background:#c90 no-repeat;position:fixed;bottom:40px;right:10px}
#toTopHover{display:block;width:46px;height:46px}
</style></head>
<body>
<header><p>Site header</p></header>
<main><h1>Page</h1><p>Body text.</p></main>
<footer><p>Site footer</p></footer>
<a href="#" id="toTop" style="right: 50%; margin-right: -580px; display: none;"><span id="toTopHover"></span></a>
</body></html>
HTML;

// Also exercise nested decoration without any plugin-specific id, CSS, or placement.
$cases = array(
    'reduced page' => $page,
    'generic nested decoration' => '<main><a href="#" id="painted-link" style="display:none"><span class="decoration"><span id="painted-child"></span></span></a></main>',
);
foreach ( $cases as $label => $html ) {
    $result = (new HtmlTransformer())->transform($html)->toArray();
    $markup = (string) ($result['serialized_blocks'] ?? '');
    $id = 'reduced page' === $label ? 'toTop' : 'painted-link';
    $childId = 'reduced page' === $label ? 'toTopHover' : 'painted-child';
    $unsupported = array_filter(
        $result['fallbacks'] ?? array(),
        static fn (array $finding): bool => 'html_unsupported_element' === ($finding['diagnostic_code'] ?? '')
    );
    $assert(array() === $unsupported, "$label: no unsupported-element diagnostic", json_encode($result['fallbacks'] ?? array()));
    $assert(! str_contains($markup, '<!-- wp:html'), "$label: link stays native", $markup);
    $assert(1 === substr_count($markup, 'href="#"'), "$label: destination survives exactly once", $markup);
    $assert(1 === substr_count($markup, 'id="' . $id . '"'), "$label: anchor identity survives exactly once", $markup);
    $assert(str_contains($markup, 'id="' . $childId . '"'), "$label: decorative descendant identity survives", $markup);
    $assert('pass' === (new BlockValidityValidator())->validateBlocks($result['blocks'] ?? array())['status'], "$label: native output is editor-valid");
    $css = implode("\n", array_column($result['assets'] ?? array(), 'content'));
    $carrier = array();
    $assert(
        str_contains($css, '#' . $id . '{display:none;')
            || (1 === preg_match('/\bbe-inline-geometry-[a-f0-9]+\b/', $markup, $carrier)
                && str_contains($css, '.' . $carrier[0] . '{display:none}')),
        "$label: source hidden presentation survives through source CSS or native geometry carrier",
        $css
    );
    if ( 'reduced page' === $label ) {
        $assert(str_contains($css, '#c90') && str_contains($css, '46px') && str_contains($css, 'fixed'), 'source box paint, size and positioning survive in CSS');
        $assert(str_contains($markup, 'margin-right: -580px') || str_contains($markup, 'margin-right:-580px'), 'source inline positioning survives', $markup);
        $assert(str_contains($markup, 'Site footer') && str_contains($markup, 'Body text.'), 'page content survives');
    }
}

if ( $failures > 0 ) {
    fwrite(STDERR, "textless painted anchor: {$passes} passed, {$failures} FAILED\n");
    exit(1);
}
fwrite(STDOUT, "OK: textless painted anchor ({$passes} assertions)\n");
