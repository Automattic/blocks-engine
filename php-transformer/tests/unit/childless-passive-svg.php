<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Support\SvgMaterializer;

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    ++$assertions;
    if (!$condition) {
        fwrite(STDERR, 'FAIL: ' . $message . PHP_EOL);
        exit(1);
    }
};

// Direct character data is not painted by SVG; only a <text> element paints text.
$fixtures = array(
    'stray character data' => ' d="m145.9 673.7-5.1-30.5s5.9-34.7 …"',
    'whitespace' => " \n\t ",
    'empty' => '',
    'normal artwork' => '<path d="M0 0h696v812H0z"/>',
);
foreach ($fixtures as $label => $content) {
    $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 696 812">' . $content . '</svg>';
    $document = new DOMDocument();
    $document->loadXML($svg);
    $assert(SvgMaterializer::isPassiveSvgMarkup($document->documentElement), "{$label}: markup remains passive.");

    $result = (new HtmlTransformer())->transform($svg)->toArray();
    $markup = (string) ($result['serialized_blocks'] ?? '');
    $assets = array_values(array_filter($result['assets'] ?? array(), static fn(array $asset): bool => 'inline-svg' === ($asset['source'] ?? null)));
    $assert(str_contains($markup, '<!-- wp:image') && !str_contains($markup, '<!-- wp:html'), "{$label}: materializes through the native image path.");
    $assert(array() === array_filter($result['fallbacks'] ?? array(), static fn(array $fallback): bool => 'html_inline_svg_fallback' === ($fallback['diagnostic_code'] ?? null)), "{$label}: no inline SVG fallback.");
    $assert(1 === count($assets), "{$label}: emits a passive SVG asset.");
    $asset = (string) ($assets[0]['content'] ?? '');
    $assert(str_contains($asset, 'viewBox="0 0 696 812"'), "{$label}: retains the viewBox.");
    $assert(str_contains($markup, 'width:696px;height:812px') && str_contains($asset, 'width="696" height="812"'), "{$label}: retains box geometry.");
    if ('normal artwork' === $label) {
        $assert(str_contains($asset, '<path d="M0 0h696v812H0z"'), 'Normal path artwork survives unchanged.');
    }
}

foreach (array(
    '<svg viewBox="0 0 696 812"><script>alert(1)</script></svg>' => 'html_unsafe_inline_svg',
    '<svg viewBox="0 0 696 812" onload="alert(1)">stray text</svg>' => 'html_unsafe_inline_svg',
    '<svg viewBox="0 0 696 812"><foreignObject>stray text</foreignObject></svg>' => 'html_inline_svg_fallback',
) as $svg => $diagnostic) {
    $document = new DOMDocument();
    $document->loadXML($svg);
    $assert(!SvgMaterializer::isPassiveSvgMarkup($document->documentElement), 'Active SVG does not classify as passive.');
    $result = (new HtmlTransformer())->transform($svg)->toArray();
    $assert(!str_contains((string) ($result['serialized_blocks'] ?? ''), '<!-- wp:image'), 'Active SVG does not enter the passive image path.');
    $assert($diagnostic === ($result['fallbacks'][0]['diagnostic_code'] ?? null), 'Active SVG retains its existing fallback diagnostic.');
}

fwrite(STDOUT, 'Childless passive SVG tests: ' . $assertions . " passed\n");
