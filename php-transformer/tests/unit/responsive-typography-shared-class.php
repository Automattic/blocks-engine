<?php
declare(strict_types=1);

/**
 * Elements whose responsive font sizes are identical share one generated
 * responsive-typography class, so the theme ships each rule set once rather
 * than once per element (theme_css_weight, Automattic/blocks-engine#6145).
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

$html = '<!doctype html><html><head><style>'
    . '.section-heading{font-size:calc(1.275rem + .3vw)}@media (min-width:1200px){.section-heading{font-size:1.5rem}}'
    . '.lead{font-size:1rem}@media (min-width:1200px){.lead{font-size:1.25rem}}'
    . '</style></head><body><main>'
    . '<section><h2 class="section-heading">Services</h2><p class="lead">One</p></section>'
    . '<section><h2 class="section-heading">Portfolio</h2><p class="lead">Two</p></section>'
    . '<section><h2 class="section-heading">About</h2><p>Three</p></section>'
    . '</main></body></html>';
$result = (new ArtifactCompiler())->compile(array(
    'entrypoint' => 'index.html',
    'files' => array('index.html' => $html),
))->toArray();
$markup = (string) $result['serialized_blocks'];
$css = implode("\n", array_column($result['assets'], 'content'));

$failures = array();
$assert = static function (bool $ok, string $message) use (&$failures): void {
    if (!$ok) $failures[] = $message;
};

$document = new DOMDocument();
@$document->loadHTML('<html><body>' . $markup . '</body></html>');
$classOf = static function (DOMElement $element): string {
    preg_match('/\bblocks-engine-responsive-typography-[a-f0-9]+\b/', $element->getAttribute('class'), $match);
    return $match[0] ?? '';
};
$headings = array_map($classOf, iterator_to_array($document->getElementsByTagName('h2')));
$assert(3 === count($headings) && !in_array('', $headings, true), 'each responsive heading carries a responsive typography class');
$assert(1 === count(array_unique($headings)), 'headings with identical responsive sizes share one class: ' . implode(', ', array_unique($headings)));

$leads = array_values(array_filter(array_map($classOf, iterator_to_array($document->getElementsByTagName('p')))));
$assert(2 === count($leads) && 1 === count(array_unique($leads)), 'paragraphs with identical responsive sizes share one class');
$assert(array() !== $leads && !in_array($leads[0], $headings, true), 'different responsive sizes keep distinct classes');

$heading = $headings[0] ?? '';
$assert('' !== $heading && 1 === substr_count($css, ':root .' . $heading . '{font-size:calc(1.275rem + .3vw)}'), 'the shared base size rule is emitted once');
$assert('' !== $heading && 1 === substr_count($css, '{:root .' . $heading . '{font-size:'), 'the shared conditional size rule is emitted once');

if ($failures) {
    foreach ($failures as $message) fwrite(STDERR, "FAIL: {$message}\n");
    exit(1);
}
echo "responsive typography shared class passed\n";
