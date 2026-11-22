<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\Css\CssStylesheetTransformer;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\LayoutGeometryState;

$assert = static function (bool $condition, string $message): void {
    if (! $condition) throw new RuntimeException($message);
    echo "PASS: $message\n";
};
$html = '<!doctype html><html><head><title>Shared geometry</title></head><body><main>'
    . '<div style="width:137px;min-height:43px">First</div>'
    . '<div style="width:137px;min-height:43px">Second</div>'
    . '</main></body></html>';
$result = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array('index.html' => $html)))->toArray();
$dom = new DOMDocument();
@$dom->loadHTML($result['serialized_blocks']);
$xpath = new DOMXPath($dom);
$carriers = array();
foreach (array('First', 'Second') as $text) {
    $element = $xpath->query('//*[text()="' . $text . '"]')->item(0);
    $assert($element instanceof DOMElement, "$text survives compilation");
    preg_match('/be-inline-geometry-[a-f0-9]{16}/', $element->getAttribute('class'), $match);
    $assert(isset($match[0]), "$text has a geometry carrier");
    $carriers[] = $match[0];
}
$assert($carriers[0] === $carriers[1], 'Identical geometry compiles to one shared carrier');
$rules = 0;
$css = implode("\n", array_column($result['assets'], 'content'));
(new CssStylesheetTransformer())->visitStyleRules($css, static function (string $selector, string $body) use (&$rules, $carriers): void {
    if (str_contains($selector, $carriers[0])) ++$rules;
});
$assert(1 === $rules, 'The stylesheet emits the shared geometry rule only once');

// Final mutations, conditional rules and pseudos all participate in identity.
$state = new LayoutGeometryState();
$a = $state->allocateCarrier('first');
$b = $state->allocateCarrier('second');
$c = $state->allocateCarrier('third');
foreach (array($a, $b, $c) as $class) $state->registerRule($class, '.' . $class . '{width:137px}');
$state->appendRule($a, '@media(max-width:600px){.' . $a . ':hover{width:80px}}');
$state->appendRule($b, '@media(max-width:600px){.' . $b . ':hover{width:80px}}');
$state->appendRule($c, '@media(max-width:600px){.' . $c . ':focus{width:80px}}');
$map = $state->canonicalizeCarriers();
$assert($map[$a] === $map[$b] && $map[$a] !== $map[$c], 'Complete appended conditional and pseudo rules determine identity');
