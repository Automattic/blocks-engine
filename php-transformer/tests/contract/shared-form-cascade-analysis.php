<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\Css\CssRuleAnalyzer;
use Automattic\BlocksEngine\PhpTransformer\Css\CssSelectorMatchCache;
use Automattic\BlocksEngine\PhpTransformer\Css\CssSelectorMatcher;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\FormLayoutGraphBuilder;

$assert = static function (bool $condition, string $message): void {
    if ( ! $condition ) throw new RuntimeException($message);
};
$normalize = static function (mixed $value) use (&$normalize): mixed {
    if ( is_array($value) ) {
        foreach ( $value as $key => $child ) {
            if ( in_array($key, array('transform_duration_ms', 'step_timings'), true) ) unset($value[$key]);
            else $value[$key] = $normalize($child);
        }
    } elseif ( is_string($value) ) {
        $value = preg_replace('/blocks-engine-(richtext|control)-[a-f0-9]+/', 'blocks-engine-$1-NORMALIZED', $value);
    }
    return $value;
};
$html = file_get_contents(dirname(__DIR__) . '/fixtures/shared-form-cascade.html');
$css = ':root{--space:12px;--ink:#123456}.contact{display:flex;flex-direction:column;gap:var(--space);color:var(--ink);font:16px sans-serif}.fields{display:grid;grid-template-columns:1fr 1fr;gap:12px}.contact label{display:flex;flex-direction:column}.contact input{padding:8px;border:1px solid #ccc}.contact button{padding:8px 16px;background:#123456;color:white}@media(max-width:600px){.fields{grid-template-columns:1fr}}';
for ( $i = 0; $i < 150; ++$i ) $css .= '.unused-' . $i . '{display:block;color:red;padding:2px}';
$files = array('index.html' => $html, 'other.html' => str_replace('Contact', 'Other', $html), 'shared.css' => $css);
$before = CssRuleAnalyzer::indexBuilds();
$result = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => $files))->toArray();
$assert(4 === CssRuleAnalyzer::indexBuilds() - $before, 'Two pages with two forms prepare four distinct CSS indexes.');
$beforeRepeat = CssRuleAnalyzer::indexBuilds();
$repeat = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => $files))->toArray();
$assert($beforeRepeat === CssRuleAnalyzer::indexBuilds(), 'Repeated stylesheet inputs reuse immutable indexes.');
$assert(json_encode($normalize($result)) === json_encode($normalize($repeat)), 'Shared and fresh compilation output must be byte-identical.');

$html = file_get_contents(dirname(__DIR__) . '/fixtures/shared-form-forced-colors.html');
$selector = ':where([data-blocks-engine-capture-device-document="desktop"],[data-blocks-engine-capture-device-document="mobile"]) .sL5d0Ld div:has(>input)';
$css = '.sL5d0Ld{display:flex;flex-direction:column;gap:12px}.sL5d0Ld div{display:block;padding:8px}@media (forced-colors:active){' . $selector . '{border:1px solid CanvasText!important}}';
// A cache primed before lowering must not leak relational matches across builds.
$document = new DOMDocument();
$document->loadHTML($html);
$cache = new CssSelectorMatchCache();
$parsed = CssSelectorMatcher::parse($selector);
foreach ( $document->getElementsByTagName('div') as $container ) $cache->matches($container, $selector, $parsed);
$form = $document->getElementsByTagName('form')->item(0);
$builder = new FormLayoutGraphBuilder($cache);
$inputs = iterator_to_array($form->getElementsByTagName('input'));
foreach ( $inputs as $input ) {
    $wrapper = $document->createElement('span');
    $input->parentNode->replaceChild($wrapper, $input);
    $wrapper->appendChild($input);
}
$assert(
    $builder->build($form, array(), $css) === (new FormLayoutGraphBuilder())->build($form, array(), $css),
    'Device-scoped forced-colors :has() matches must equal a fresh trunk-equivalent build after source lowering.'
);
// Compare complete compiled artifacts with cold CSS caches, not a golden hash.
$artifact = array('entrypoint' => 'index.html', 'files' => array('index.html' => $html, 'shared.css' => $css));
$warm = (new ArtifactCompiler())->compile($artifact)->toArray();
foreach ( array('indexes' => array(), 'indexEntries' => 0, 'streams' => array(), 'streamEntries' => 0) as $property => $empty ) {
    (new ReflectionProperty(CssRuleAnalyzer::class, $property))->setValue(null, $empty);
}
$fresh = (new ArtifactCompiler())->compile($artifact)->toArray();
$assert(json_encode($normalize($warm)) === json_encode($normalize($fresh)), 'Forced-colors compiled artifacts, diagnostics and graphs equal the uncached path.');
