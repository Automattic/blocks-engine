<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

// The public compiler must not put a fieldset's legend in its flex/grid row.
foreach ( array('flex', 'grid') as $display ) {
    $html = '<!doctype html><html><head><style>fieldset{border:0;margin:0;padding:0}.fields{display:' . $display . ';gap:12px;grid-template-columns:1fr 1fr}.fields > .field{flex:1}legend{font-size:18px}</style></head><body><main><form><fieldset class="fields name"><legend><div class="title">Name</div></legend><div class="field first-name"><label for="f">First Name</label><input id="f" name="fname" type="text"></div><div class="field last-name"><label for="l">Last Name</label><input id="l" name="lname" type="text"></div></fieldset><button type="submit">Submit</button></form></main></body></html>';
    $result = (new ArtifactCompiler())->compile(array('files' => array(array('path' => 'index.html', 'kind' => 'html', 'content' => $html))))->toArray();
    $document = new DOMDocument();
    @$document->loadHTML('<?xml encoding="utf-8" ?><body>' . preg_replace('/<!--.*?-->/s', '', $result['serialized_blocks']) . '</body>');
    $xpath = new DOMXPath($document);
    $fields = $xpath->query('//*[contains(concat(" ", normalize-space(@class), " "), " fields ")]')->item(0);
    $legend = $xpath->query('//*[normalize-space(text())="Name"]')->item(0);
    if ( ! $fields instanceof DOMElement || ! $legend instanceof DOMElement ) {
        throw new RuntimeException($display . ': missing fieldset identity or legend');
    }
    $caption = $legend;
    while ( $caption->parentNode !== $fields->parentNode && $caption->parentNode instanceof DOMElement ) {
        $caption = $caption->parentNode;
    }
    if ( $caption === $fields || $caption->parentNode !== $fields->parentNode || $caption->nextSibling !== $fields ) {
        throw new RuntimeException($display . ': legend must occupy its own line before the layout group');
    }
    $outer = $fields->parentNode;
    if ( ! $outer instanceof DOMElement || str_contains($outer->getAttribute('class'), 'is-layout-flex') || str_contains($outer->getAttribute('class'), 'is-layout-grid') || $outer->hasAttribute('style') ) {
        throw new RuntimeException($display . ': legend parent must be neutral block flow');
    }
    if ( 2 !== $xpath->query('./*[contains(concat(" ", normalize-space(@class), " "), " field ")]', $fields)->length || ! str_contains($fields->getAttribute('class'), 'name') ) {
        throw new RuntimeException($display . ': authored classes and direct field children must stay on the layout group');
    }
    $css = implode("\n", array_column(array_filter($result['assets'], static fn (array $asset): bool => 'css' === ($asset['kind'] ?? '')), 'content'));
    if ( ! preg_match('/display:\s*' . $display . '/', $css) || ! str_contains($css, '.fields') || ! str_contains($css, '.field') ) {
        throw new RuntimeException($display . ': authored layout and selectors must survive projection');
    }
}
echo "fieldset legend layout passed\n";
