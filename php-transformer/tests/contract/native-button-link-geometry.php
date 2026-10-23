<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

$assert = static function (bool $condition, string $message): void {
    if (! $condition) throw new RuntimeException($message);
};

// Source markup that is already core's button save output keeps its own
// wrappers. A width rule on its link (here an !important reset that wins over
// a width:100% rule) must still reach the emitted link.
$css = <<<'CSS'
.wp-block-button__link{display:inline-block}
:where(.wp-block-button__link){border-radius:9999px;padding:10px 20px}
.wp-block-buttons .wp-block-button__link{width:100%}
.wp-block-buttons .wp-block-button__link{width:unset !important}
CSS;
$html = '<!doctype html><html><head><link rel="stylesheet" href="s.css"></head><body><main><h1>T</h1>'
    . '<div class="wp-block-buttons is-layout-flex"><div class="wp-block-button has-custom-width wp-block-button__width-25 aligncenter">'
    . '<a class="wp-block-button__link wp-element-button" href="/contact/">Contact</a></div></div></main></body></html>';
$result = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array('index.html' => $html, 's.css' => $css)))->toArray();

$stylesheets = '';
array_walk_recursive($result, static function ($value) use (&$stylesheets): void {
    if (is_string($value) && str_contains($value, 'unset !important')) $stylesheets .= $value . "\n";
});
$projected = array();
preg_match_all('/([^{}]*)\{([^{}]*width:\s*unset[^{}]*)\}/', $stylesheets, $rules, PREG_SET_ORDER);
foreach ($rules as $rule) {
    foreach (explode(',', $rule[1]) as $selector) {
        if (str_contains($selector, 'blocks-engine-control-')) $projected[] = trim($selector);
    }
}
$assert(array() !== $projected, 'The link width reset is projected onto the emitted control.');
foreach ($projected as $selector) {
    $assert(str_contains($selector, 'wp-block-button__link'), 'Every projected arm targets the emitted link, not a wrapper: ' . $selector);
    $assert(! str_contains($selector, ':where(.wp-block-buttons)'), 'No arm requires the control marker to be the buttons row: ' . $selector);
}

$markup = (string) $result['serialized_blocks'];
$document = new DOMDocument();
@$document->loadHTML($markup);
$xpath = new DOMXPath($document);
$link = $xpath->query('//div[contains(concat(" ",normalize-space(@class)," ")," wp-block-button ")]/a')->item(0);
$assert($link instanceof DOMElement, 'Rendered topology is buttons > button > link.');
preg_match('/blocks-engine-control-[0-9a-f]+-\d+/', $link->parentNode->getAttribute('class'), $marker);
$assert(isset($marker[0]) && str_contains(implode(',', $projected), $marker[0]), 'The projected arm reaches this button\'s control marker.');
echo "Native button link geometry contract passed.\n";
