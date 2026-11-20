<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

// A parent colour edit must preserve an explicitly coloured child, even
// when its initial colour equals the parent's. Truly inherited text stays
// unbound, so the parent's inspector control can still recolour it.
$html = '<!doctype html><html><head><style>'
    . '.panel{color:#1d1d1b}.ink{--tw-text-opacity:1;color:rgb(29 29 27 / var(--tw-text-opacity, 1))}'
    . '</style></head><body><main><div class="panel">'
    . '<p class="ink">Explicit paragraph</p>'
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
if (1 !== $explicit->length) throw new RuntimeException('The native paragraph must survive.');
foreach ($explicit as $element) {
    if (!str_contains($element->getAttribute('class'), 'has-text-color')
        || !str_contains($element->getAttribute('style'), 'color:')) {
        throw new RuntimeException('Authored text color must be a native block editing boundary: ' . $markup);
    }
}
preg_match_all('/<!-- wp:(?:paragraph|heading) (\{.*?\}) -->/', $markup, $comments);
if (1 !== count($comments[1])) throw new RuntimeException('Only explicitly colored text carries attributes.');
foreach ($comments[1] as $json) {
    $attrs = json_decode($json, true);
    if (empty($attrs['style']['color']['text']) && empty($attrs['textColor'])) {
        throw new RuntimeException('Color must be stored in editable block attributes: ' . $json);
    }
    if ('#1d1d1b' !== ($attrs['style']['color']['text'] ?? '')) {
        throw new RuntimeException('Nested functional colors must use save-safe hex serialization: ' . $json);
    }
}
$inherited = $xpath->query('//p[text()="Inherited paragraph"]')->item(0);
if (!$inherited || $inherited->hasAttribute('style') || str_contains($inherited->getAttribute('class'), 'has-text-color')) {
    throw new RuntimeException('Inherited text must remain editable through the parent.');
}
// Preserve the public site-plan palette contract: tag-level heading colour
// remains stylesheet-owned. Promoting every heading changes binding order and
// needlessly adds native colour supports outside the class editing boundary.
$palette = (new ArtifactCompiler())->compile(require dirname(__DIR__) . '/fixtures/design-system/plain-css.php')->toArray();
$plan = $palette['source_reports']['wordpress_site_plan'];
$canonical = $plan['pages'][0]['canonical_block_markup'];
if (preg_match('/<!-- wp:heading (\{[^\n]*?\}) -->/', $canonical, $heading)
    && isset(json_decode($heading[1], true)['textColor'])) {
    throw new RuntimeException('Tag-level heading colors must not acquire new palette bindings.');
}
if (!preg_match('/<!-- wp:paragraph (\{[^\n]*?"textColor":"primary"[^\n]*?\}) -->/', $canonical)) {
    throw new RuntimeException('The inline paragraph must retain its palette binding.');
}
// Ordinary stylesheet colors must not acquire new native supports: that
// changes palette derivation and can freeze responsive or contextual paint.
$ordinary = (new ArtifactCompiler())->compile(array(
    'entrypoint' => 'index.html',
    'files' => array('index.html' => '<style>.plain{color:#123456}</style><main><p class="plain">Ordinary stylesheet color</p></main>'),
))->toArray();
if (str_contains($ordinary['serialized_blocks'], 'has-text-color')) {
    throw new RuntimeException('Ordinary stylesheet colors must remain stylesheet-owned.');
}
echo "Authored text color editing boundary passed\n";
