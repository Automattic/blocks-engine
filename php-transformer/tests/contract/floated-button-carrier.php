<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\Css\CssSelectorMatcher;
use Automattic\BlocksEngine\PhpTransformer\Css\CssStylesheetTransformer;

$assert = static function (bool $condition, string $message): void {
    if (! $condition) throw new RuntimeException($message);
};
$html = '<!doctype html><html><head><link rel="stylesheet" href="styles.css"></head><body><main><div class="row"><div class="col">Tile</div><div class="col">Tile</div><div class="col">Tile</div><a class="pull-right btn" href="/servicos/">Mais Serviços</a></div></main></body></html>';
$css = '.col{float:left;width:33.333%}.pull-right{float:right!important;clear:both}.btn{display:inline-block;white-space:nowrap;padding:5px 10px;border:1px solid #123456;background:#234567}';
$result = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array('index.html' => $html, 'styles.css' => $css)))->toArray();
$document = new DOMDocument();
@$document->loadHTML($result['serialized_blocks']);
$link = (new DOMXPath($document))->query('//a[@href="/servicos/"]')->item(0);
$assert($link instanceof DOMElement, 'Source link survives.');
$button = $link->parentNode;
$wrapper = $button->parentNode;
$assert($button instanceof DOMElement && $wrapper instanceof DOMElement && str_contains($button->getAttribute('class'), 'wp-block-button') && str_contains($wrapper->getAttribute('class'), 'wp-block-buttons'), 'Link lowers to buttons > button > link.');
$owners = array();
foreach ($result['assets'] as $asset) {
    if ('css' !== ($asset['kind'] ?? '')) continue;
    preg_match_all('/([^{}]+)\{([^{}]*)\}/', $asset['content'] ?? '', $rules, PREG_SET_ORDER);
    foreach ($rules as $rule) {
        foreach (CssStylesheetTransformer::splitSelectorList(trim($rule[1])) ?? array() as $selector) {
            $parsed = CssSelectorMatcher::parse($selector);
            foreach (array('float' => 'right!important', 'clear' => 'both', 'width' => 'fit-content') as $property => $value) {
                if (! str_contains($rule[2], $property . ':' . $value)) continue;
                $assert($parsed['supported'], 'Carrier selector is testable: ' . $selector);
                foreach (array($wrapper, $button, $link) as $index => $element) {
                    if (! CssSelectorMatcher::matches($element, $parsed)['matches']) continue;
                    $owners[$property][$index] = true;
                    if ('width' !== $property) $assert(0 === $index, 'Float participation must not reach an inner button box: ' . $selector);
                }
            }
        }
    }
}
$assert(isset($owners['float'][0]), 'Outer buttons wrapper owns float:right!important.');
$assert(isset($owners['clear'][0]), 'Outer buttons wrapper owns clear:both.');
$assert(isset($owners['width'][0]), 'Outer float is sized to its content.');
foreach (array('padding-left:10px', 'border-width:1px', 'background-color:#234567') as $paint) {
    $assert(str_contains($link->getAttribute('style'), $paint), 'Paint and box decoration stay on the link: ' . $paint);
}
echo "Floated button carrier contract passed.\n";
