<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

// A parent colour edit must preserve an explicitly coloured child, even
// when its initial colour equals the parent's. Truly inherited text stays
// unbound, so the parent's inspector control can still recolour it.
$html = '<!doctype html><html><head><style>'
    . '.panel{color:#1d1d1b}.ink{--tw-text-opacity:1;color:rgb(29 29 27 / var(--tw-text-opacity))}'
    . '</style></head><body><main><div class="panel">'
    . '<p class="ink">Explicit paragraph</p><h2 class="ink">Explicit heading</h2>'
    . '<p>Inherited paragraph</p></div></main></body></html>';
$result = (new ArtifactCompiler())->compile(array(
    'entrypoint' => 'index.html',
    'files' => array('index.html' => $html),
))->toArray();
$markup = (string) $result['serialized_blocks'];
$document = new DOMDocument();
@$document->loadHTML('<html><body>' . $markup . '</body></html>');
$xpath = new DOMXPath($document);
$explicit = $xpath->query('//*[contains(concat(" ",normalize-space(@class)," ")," ink ")]');
if (2 !== $explicit->length) throw new RuntimeException('Both native text blocks must survive.');
foreach ($explicit as $element) {
    if (!str_contains($element->getAttribute('class'), 'has-text-color')
        || !str_contains($element->getAttribute('style'), 'color:')) {
        throw new RuntimeException('Authored text color must be a native block editing boundary: ' . $markup);
    }
}
preg_match_all('/<!-- wp:(?:paragraph|heading) (\{.*?\}) -->/', $markup, $comments);
if (2 !== count($comments[1])) throw new RuntimeException('Only explicitly colored text carries attributes.');
foreach ($comments[1] as $json) {
    $attrs = json_decode($json, true);
    if (empty($attrs['style']['color']['text']) && empty($attrs['textColor'])) {
        throw new RuntimeException('Color must be stored in editable block attributes: ' . $json);
    }
    if (str_contains((string) ($attrs['style']['color']['text'] ?? ''), 'var(')) {
        throw new RuntimeException('Static variables inside functional colors must resolve before native color serialization: ' . $json);
    }
}
$inherited = $xpath->query('//p[text()="Inherited paragraph"]')->item(0);
if (!$inherited || $inherited->hasAttribute('style') || str_contains($inherited->getAttribute('class'), 'has-text-color')) {
    throw new RuntimeException('Inherited text must remain editable through the parent.');
}
echo "Authored text color editing boundary passed\n";
