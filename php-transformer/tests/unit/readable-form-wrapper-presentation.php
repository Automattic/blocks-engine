<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

$failures = array();
$assert = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) $failures[] = $message;
};
$css = '.field-shell{height:60px;padding:12px;background:white;border:1px solid #ddd;--field-color:#555}'
    . '.field-shell input{width:100%;height:100%;color:var(--field-color)}'
    . '.submit-shell{height:60px}.field-shell[data-accent]::after{content:"";border:1px solid blue}';
$result = (new HtmlTransformer())->transform(
    '<style>' . $css . '</style><form>'
    . '<label class="field-label"><div class="field-shell" data-accent="true"><input name="name" placeholder="Name"></div></label>'
    . '<div class="submit-shell"><button type="submit">Submit</button></div></form>',
    array()
)->toArray();
$markup = $result['serialized_blocks'];
$document = new DOMDocument();
$document->loadHTML($markup, LIBXML_NOERROR | LIBXML_NOWARNING);
$xpath = new DOMXPath($document);
$assert(1 === $xpath->query('//form/label[contains(@class,"field-label")]/div[contains(@class,"field-shell")]/input[@name="name"]')->length, 'styled field ancestry and direct input participation survive');
$assert(1 === $xpath->query('//div[contains(@class,"field-shell")][@data-accent="true"]')->length, 'attribute-owned pseudo-border remains addressable');
$assert(1 === $xpath->query('//form/div[contains(@class,"submit-shell")]/button[@type="submit"]')->length, 'submit retains its sizing parent and semantics');
$assert(!str_contains($markup, '<div class="wp-block-group"><input'), 'no anonymous group changes a source field flex item');

$neutral = (new HtmlTransformer())->transform('<form><div><input name="plain" placeholder="Name"></div></form>', array())->toArray();
$assert(!str_contains($neutral['serialized_blocks'], '/layout-shell'), 'unadorned containers without declared layout still reduce');
$labelled = (new HtmlTransformer())->transform('<form><label class="caption">Name<input class="entry" name="name" style="width:100%"></label></form>', array())->toArray();
$labelDocument = new DOMDocument();
$labelDocument->loadHTML($labelled['serialized_blocks'], LIBXML_NOERROR | LIBXML_NOWARNING);
$assert(0 === (new DOMXPath($labelDocument))->query('//label/label')->length, 'retaining fields does not nest reconstructed labels');

if ($failures) {
    fwrite(STDERR, implode("\n", $failures) . "\n");
    exit(1);
}
echo "Readable form wrapper presentation passed: 6 assertions\n";
