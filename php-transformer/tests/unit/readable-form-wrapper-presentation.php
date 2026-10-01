<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;
use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

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

$cells = '';
foreach (array('first', 'second', 'third') as $name) {
    $cells .= '<td style="width:33.333333333333%"><div class="field"><input name="' . $name . '"></div></td>';
}
$deepHtml = '<form method="post" action="#" class="deep-form">' . str_repeat('<div>', 9)
    . '<table><tbody><tr>' . $cells . '</tr></tbody></table>' . str_repeat('</div>', 9) . '<button type="submit">Send</button></form>';
$deep = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array('index.html' => $deepHtml)))->toArray();
$declaration = current(array_filter($deep['source_reports']['wordpress_site_plan']['runtime_declarations'] ?? array(), static fn(array $entry): bool => 'forms' === ($entry['type'] ?? null)));
$widths = array_filter($declaration['payload']['entities'][0]['layout_graph']['nodes'] ?? array(), static fn(array $node): bool => 'td' === ($node['source']['tag'] ?? null) && '33.333333333333%' === ($node['layout']['width'] ?? null));
$assert(3 === count($widths), 'deep retained form wrappers still produce a self-contained runtime declaration with all source cell widths');
$deepDocument = new DOMDocument();
$deepDocument->loadHTML($deep['serialized_blocks'], LIBXML_NOERROR | LIBXML_NOWARNING);
$assert(3 === (new DOMXPath($deepDocument))->query('//table/tbody/tr/td/div')->length, 'folded layout-shell chains preserve source table ancestry');

if ($failures) {
    fwrite(STDERR, implode("\n", $failures) . "\n");
    exit(1);
}
echo "Readable form wrapper presentation passed: 8 assertions\n";
