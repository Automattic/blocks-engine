<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\Support\HtmlSerialization;

$assert = static function (bool $condition, string $message): void {
    if (! $condition) {
        throw new RuntimeException($message);
    }
};

// A full page parsed with the UTF-8 hint, then serialized and parsed again the
// way a page passes through more than one projector.
$html = "<!DOCTYPE html>\n<html><body><p title='{\"a\":1}'>caf\u{e9}</p></body></html>";
for ($pass = 1; $pass <= 3; ++$pass) {
    $document = new DOMDocument();
    $previous = libxml_use_internal_errors(true);
    $document->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_NONET);
    libxml_clear_errors();
    libxml_use_internal_errors($previous);
    $html = HtmlSerialization::documentHtml($document);
    $assert(is_string($html), "pass {$pass} serializes");
    $assert(! str_contains($html, 'xml encoding'), "pass {$pass} leaves no encoding hint in the page (libxml " . LIBXML_DOTTED_VERSION . "): {$html}");
    $assert(str_contains($html, "caf\u{e9}") || str_contains($html, 'caf&eacute;'), "pass {$pass} keeps UTF-8 text");
}

// Author comments that merely mention xml stay in the page.
$document = new DOMDocument();
$previous = libxml_use_internal_errors(true);
$document->loadHTML('<?xml encoding="UTF-8"><html><body><!-- xml encoding notes --><p>x</p></body></html>', LIBXML_NONET);
libxml_clear_errors();
libxml_use_internal_errors($previous);
$assert(str_contains((string) HtmlSerialization::documentHtml($document), '<!-- xml encoding notes -->'), 'author comments survive');

echo "HTML serialization encoding-hint tests passed.\n";
