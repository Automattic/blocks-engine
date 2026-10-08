<?php
declare(strict_types=1);

// An attribute can be named `0` or `512` (a script can set such a name). PHP
// turns such a name into an int key in an attribute map, and string code then
// throws a TypeError under strict_types. The engine drops these names when it
// reads attributes. The DOM checks build the attribute with SimpleXML because
// DOMElement::setAttribute() refuses the name and libxml before 2.14 drops it
// while parsing; this keeps the test the same on every libxml version.

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Support\SourceDom;
use Automattic\BlocksEngine\PhpTransformer\Support\HtmlAttributeName;
use Automattic\BlocksEngine\PhpTransformer\Support\HtmlTagScanner;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\DocumentHeadContext;

$failures = 0;
$assert = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) {
        ++$failures;
        fwrite(STDERR, "FAIL: {$message}\n");
    }
};
$run = static function (string $label, callable $callback) use ($assert): mixed {
    try {
        return $callback();
    } catch (Throwable $error) {
        $assert(false, $label . ' threw ' . get_class($error) . ': ' . $error->getMessage());
        return null;
    }
};
$stringKeys = static fn (array $map): bool => array() === array_filter(array_keys($map), static fn (mixed $key): bool => !is_string($key));

// Raw tag scanning does not depend on libxml.
$head = $run('head context with an integer-named meta attribute', static fn (): ?array => DocumentHeadContext::fromHtml(
    '<html><head><meta 0="" 512="" name="description" content="Beds" class="seo"></head><body><p>Hi</p></body></html>',
    'website/index.html',
    array()
));
$meta = is_array($head) ? ($head['elements'][0]['attributes'] ?? null) : null;
$assert(array('name' => 'description', 'content' => 'Beds', 'class' => 'seo') === $meta, 'head context keeps the named meta attributes and drops the integer names');

$scanned = $run('tag scanner', static fn (): array => HtmlTagScanner::attributes('<svg 0="" 512 viewBox="0 0 1 1" -1="x" data-0="kept" 00="kept">'));
$assert(is_array($scanned) && $stringKeys($scanned), 'tag scanner returns only string keys');
$assert(array('viewbox' => '0 0 1 1', 'data-0' => 'kept', '00' => 'kept') === $scanned, 'tag scanner drops only integer names');

// DOM attribute maps.
$document = new DOMDocument();
$document->loadHTML('<?xml encoding="utf-8" ?><body><div><svg viewBox="0 0 512 512" width="24"><path d="M0 0h512v512H0z"/></svg><span class="label">Hi</span></div></body>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD | LIBXML_NOERROR);
$svg = $document->getElementsByTagName('svg')->item(0);
$span = $document->getElementsByTagName('span')->item(0);
$root = $document->getElementsByTagName('div')->item(0);
$assert(function_exists('simplexml_import_dom'), 'SimpleXML is available to build integer-named attributes');
foreach (array($svg, $span) as $element) {
    simplexml_import_dom($element)->addAttribute('0', '');
    simplexml_import_dom($element)->addAttribute('512', '');
}
$assert(4 === $svg->attributes->length, 'fixture svg carries two integer-named attributes');

$svgAttributes = $run('htmlAttributes', static fn (): array => SourceDom::htmlAttributes($svg));
$assert(is_array($svgAttributes) && $stringKeys($svgAttributes), 'htmlAttributes returns only string keys');
$assert(array('viewbox' => '0 0 512 512', 'width' => '24') === $svgAttributes, 'htmlAttributes keeps normal attributes unchanged');
$assert(array('class' => 'label') === SourceDom::htmlAttributes($span), 'htmlAttributes drops integer names on any element');

$run('removeIntegerKeyAttributes', static fn () => SourceDom::removeIntegerKeyAttributes($root));
$names = static fn (DOMElement $element): array => array_map(static fn (DOMAttr $attribute): string => $attribute->nodeName, iterator_to_array($element->attributes, false));
$assert(array('viewbox', 'width') === $names($svg), 'removal keeps normal svg attributes in source order');
$assert(array('class') === $names($span), 'removal reaches every element in the subtree');
$assert(!preg_match('/\s(?:0|512)=/', (string) $document->saveHTML($root)), 'serialized source no longer carries integer names');

// Full transform. libxml 2.14+ keeps these names while parsing, which is the
// case the checks above build by hand; older libxml drops them, so this part
// only proves something on newer libxml.
$inputs = array(
    'svg icon'    => '<div class="card"><svg 0="" 512="" viewBox="0 0 512 512" width="24" height="24"><path d="M0 0h512v512H0z"/></svg><p 0="">Hello</p></div>',
    'button span' => '<div class="card"><button class="cta"><span 0="" style="--blocks-engine-richtext-marker: a">Hello</span></button></div>',
);
foreach ($inputs as $label => $html) {
    $result = $run('transform of ' . $label, static fn (): array => (new HtmlTransformer())->transform($html)->toArray());
    $json = (string) json_encode($result, JSON_UNESCAPED_SLASHES);
    $assert(is_array($result) && str_contains($json, 'Hello'), 'transform of ' . $label . ' keeps the content');
    $assert(!preg_match('/\s(?:0|512)=\\\\"/', $json), 'transform of ' . $label . ' never re-emits integer-named attributes');
    foreach (is_array($result) ? $result['assets'] : array() as $asset) {
        if (str_ends_with((string) ($asset['path'] ?? ''), '.svg')) {
            $svgDocument = new DOMDocument();
            $assert(@$svgDocument->loadXML((string) ($asset['content'] ?? '')), 'svg asset from ' . $label . ' is well-formed XML');
        }
    }
}

// The name rule matches PHP's own array key rule.
$run('integer key rule', static function () use ($assert): void {
    foreach (array('0', '512', '-1', '00', '-0', '+1', '1e3', '1a', 'data-0', ' 1', '', '9223372036854775808') as $name) {
        $assert(is_int(array_key_first(array($name => true))) === HtmlAttributeName::isIntegerKey($name), 'integer key rule matches PHP for "' . $name . '"');
    }
});

if ($failures > 0) {
    exit(1);
}

fwrite(STDOUT, "integer-attribute-names: passed\n");
