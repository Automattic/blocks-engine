<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

$html = <<<'HTML'
<!doctype html><html><head><title>Topology coordinates</title></head><body>
<div id="chrome" role="banner"><div id="chrome-content" style="display:flex"><span>Contact</span><a href="/">Home</a></div></div>
<main><h1>Content</h1></main>
<div id="widget"><div id="button" style="width:64px;height:64px;display:flex"><img alt="Chat" src="chat.svg"></div></div>
</body></html>
HTML;

$assert = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};

foreach (array(false, true) as $idless) {
    $input = $idless ? str_replace(' id="chrome-content"', '', $html) : $html;
    $result = (new HtmlTransformer())->transform($input, array())->toArray();
    $findings = array_values(array_filter($result['diagnostics'], static fn (array $row): bool =>
        'author_layout_topology_changed' === ($row['code'] ?? '')
        && 'div:nth-of-type(1) > div:nth-of-type(1)' === ($row['selector'] ?? '')
    ));
    $assert(1 === count($findings), 'The chrome container has one topology diagnostic.');
    $classes = $findings[0]['block_class_names'] ?? array();
    $assert(array() !== $classes, 'The diagnostic names the final output block, including without a source id.');
    $document = new DOMDocument();
    @$document->loadHTML($result['serialized_blocks']);
    $xpath = new DOMXPath($document);
    $container = $idless ? $xpath->query('//header/div')->item(0) : $document->getElementById('chrome-content');
    $button = $document->getElementById('button');
    $assert($container instanceof DOMElement && $button instanceof DOMElement, 'Both output containers exist.');
    $outputClasses = preg_split('/\s+/', $container->getAttribute('class'));
    $buttonClasses = preg_split('/\s+/', $button->getAttribute('class'));
    foreach ($classes as $class) {
        $assert(in_array($class, $outputClasses, true), 'Every identity token belongs to the chrome block: ' . $class);
        $assert(!in_array($class, $buttonClasses, true), 'The chrome identity token is not on the chat button: ' . $class);
    }
    $assert(array() !== array_diff($classes, $buttonClasses), 'The identity does not resolve to the chat button.');
    $geometry = array_values(array_filter($outputClasses, static fn (string $class): bool => str_starts_with($class, 'be-inline-geometry-')));
    $assert(array() !== $geometry && array() === array_diff($geometry, $classes), 'Late geometry classes are included.');
}

$compiled = (new Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler())->compile(array(
    'entrypoint' => 'index.html',
    'files' => array('index.html' => $html),
))->toArray();
$plan = $compiled['source_reports']['wordpress_site_plan'] ?? array();
$assert(!empty($plan['template_parts']), 'The plan extracts the banner into a template part.');
$findings = array_values(array_filter($plan['diagnostics'] ?? array(), static fn (array $row): bool =>
    'author_layout_topology_changed' === ($row['code'] ?? '')
));
$assert(!empty($findings), 'The plan retains the topology finding.');
foreach ($findings as $finding) {
    $assert('header' === ($finding['template_part'] ?? ''), 'The plan names the known header part.');
}

echo "Author layout topology output identity passed.\n";
