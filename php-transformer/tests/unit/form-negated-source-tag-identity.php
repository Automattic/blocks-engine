<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\Css\CssSelectorMatcher;

$assert = static function (bool $ok, string $message): void {
    if ( ! $ok ) throw new RuntimeException($message);
};
$compile = static fn (string $css, string $control, string $attributes = ''): array => (new ArtifactCompiler())->compile(array('files' => array(array(
    'path' => 'index.html', 'kind' => 'html',
    'content' => '<style>' . $css . '</style><main class="theme"><form' . $attributes . '>' . $control . '</form></main>',
))))->toArray();
$stylesheet = static function (array $result): string {
    foreach ( $result['assets'] as $asset ) {
        if ( 'inline-style' === ($asset['source'] ?? '') ) return $asset['content'];
    }
    throw new RuntimeException('Missing projected inline stylesheet');
};
$input = '<input type="submit" value="Send">';
foreach ( array('.theme :not(a):not(input)', '.theme :not(a,input)') as $selector ) {
    $css = $selector . '{color:#fff!important}';
    $minimal = $stylesheet($compile($css, $input));
    $assert(str_contains($minimal, 'blocks-engine-source-input-'), 'The issue fixture excludes source input identity');
    // A submit-only form has no provider fallback. Add a data-entry field
    // to exercise fallback emission with the same selector and submit control.
    $result = $compile($css, '<input name="name">' . $input, ' method="post"');
    $finding = array_values(array_filter($result['fallbacks'], static fn (array $row): bool => 'html_form_fallback' === ($row['diagnostic_code'] ?? '')))[0] ?? array();
    $marker = $finding['controls'][1]['source_tag_marker'] ?? '';
    $projected = $stylesheet($result);
    $assert('' !== $marker && str_contains($projected, ':where(.' . $marker . ')'), 'Fallback control and projected exclusion share a tag marker');
    $actual = substr($projected, 0, strpos($projected, '{'));
    $parsed = CssSelectorMatcher::parse($actual);
    if ( str_contains($selector, ',') ) {
        // The matcher does not support :not() lists, but projection must
        // preserve their authored syntax and marker exclusion for browsers.
        $assert(str_contains($actual, ':not(a,:is(input,:where(.' . $marker . ')))'), 'Negated lists retain native and source identities');
        continue;
    }
    $assert($parsed['supported'], 'Projected selector remains supported');
    $assert(CssSelectorMatcher::specificityCounts(CssSelectorMatcher::parse($selector)) === CssSelectorMatcher::specificityCounts($parsed), 'Negation specificity is unchanged');
    $assert(str_contains($projected, '{color:#fff!important}'), 'Declarations and importance are unchanged');
    $dom = new DOMDocument();
    $dom->loadHTML('<main class="theme"><button class="' . $marker . '">Retagged</button><input><button>Native button</button></main>');
    $assert(! CssSelectorMatcher::matches($dom->getElementsByTagName('button')->item(0), $parsed)['matches'], 'Retagged input stays excluded');
    $assert(! CssSelectorMatcher::matches($dom->getElementsByTagName('input')->item(0), $parsed)['matches'], 'Unmarked native input stays excluded');
    $assert(CssSelectorMatcher::matches($dom->getElementsByTagName('button')->item(1), $parsed)['matches'], 'Source button is not excluded as an input');
}
$positive = $compile('.theme button{color:red}', '<input name="name"><button type="submit">Send</button>', ' method="post"');
$assert(str_contains($stylesheet($positive), '.theme button{color:red}'), 'Positive source button rule is unchanged');
$assert(! str_contains(json_encode($positive['fallbacks']), 'source_tag_marker'), 'Rules without negation allocate no new control marker');
$assert('.theme .label{color:red}' === $stylesheet($compile('.theme .label{color:red}', $input)), 'Selectors without type negation are unchanged');
echo "Form negated source tag identity passed\n";
