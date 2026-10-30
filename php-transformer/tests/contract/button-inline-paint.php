<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;
use Automattic\BlocksEngine\PhpTransformer\Css\CssSelectorMatcher;
use Automattic\BlocksEngine\PhpTransformer\Css\CssStylesheetTransformer;

$assert = static function (bool $condition, string $message): void {
    if (! $condition) throw new RuntimeException($message);
};
$source = file_get_contents(dirname(__DIR__) . '/fixtures/button-inline-paint.html');
$result = (new HtmlTransformer())->transform($source)->toArray();
$document = new DOMDocument();
@$document->loadHTML($result['serialized_blocks']);
$link = (new DOMXPath($document))->query('//a[@href="/trial/"]')->item(0);
$assert($link instanceof DOMElement, 'Source link survives.');
$wrapper = $link->parentNode;
$assert($wrapper instanceof DOMElement && str_contains($wrapper->getAttribute('class'), 'wp-block-button'), 'Native button wrapper survives.');
foreach (array('padding-top:16px', 'padding-bottom:16px', 'padding-left:28px', 'padding-right:28px', 'background-color:#38A348', 'color:#0E0F0C', 'font-weight:700', 'font-size:17px', 'border-radius:999px') as $declaration) {
    $assert(str_contains($link->getAttribute('style'), $declaration), 'Link owns ' . $declaration);
}
$paint = '/(?:^|;)\s*(?:padding(?:-[\w-]+)?|background(?:-[\w-]+)?|color|border(?:-[\w-]+)?|font(?:-[\w-]+)?)\s*:/';
$assert(! preg_match($paint, $wrapper->getAttribute('style')), 'Wrapper has no inline paint or padding.');
foreach ($result['assets'] ?? array() as $asset) {
    if ('css' !== ($asset['kind'] ?? '')) continue;
    preg_match_all('/([^{}]+)\{([^{}]*)\}/', $asset['content'], $rules, PREG_SET_ORDER);
    foreach ($rules as $rule) {
        if (! preg_match($paint, $rule[2])) continue;
        foreach (CssStylesheetTransformer::splitSelectorList(trim($rule[1])) ?? array() as $selector) {
            $parsed = CssSelectorMatcher::parse($selector);
            $assert($parsed['supported'], 'Paint selector is testable: ' . $selector);
            $assert(! CssSelectorMatcher::matches($wrapper, $parsed)['matches'], 'Wrapper must not duplicate link chrome: ' . $rule[0]);
        }
    }
}
echo "Inline button paint belongs only to the link.\n";
