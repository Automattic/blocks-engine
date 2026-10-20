<?php
declare(strict_types=1);

/**
 * Editor projection stays a constant factor of the front-end rule.
 *
 * A layout shell renders its inner blocks inside one editor-only layer, so
 * every authored child combinator may or may not cross that layer in the
 * editor canvas. Restating each placement as its own selector multiplied a
 * rule by 2^k for k boundaries. The editor rule now states each boundary once
 * as a tolerant step, so it grows by the same amount per boundary, emits at
 * most one editor rule per front arm, still reaches the element through any
 * mix of layers, and weighs exactly what the authored selector weighs.
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\WordPressCompatCss;
use Automattic\BlocksEngine\PhpTransformer\Css\CssSelectorMatcher;
use Automattic\BlocksEngine\PhpTransformer\Css\CssStylesheetTransformer;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\EditorShellBoundary;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\EngineSupportCss;

$failures = 0;
$passes = 0;
$assert = static function (bool $condition, string $message, string $detail = '') use (&$failures, &$passes): void {
    if ( $condition ) {
        ++$passes;
        return;
    }
    ++$failures;
    fwrite(STDERR, 'FAIL: ' . $message . ('' !== $detail ? ' - ' . $detail : '') . PHP_EOL);
};

$layer = EngineSupportCss::LAYOUT_SHELL_EDITOR_INNER_BLOCKS_CLASS;
$body = 'color:rgb(1,2,3)';

$cssFor = static function (string $html): string {
    $css = '';
    foreach ( ( new HtmlTransformer() )->transform($html)->toArray()['assets'] ?? array() as $asset ) {
        if ( 'css' === ( $asset['kind'] ?? '' ) ) {
            $css .= "\n" . (string) ( $asset['content'] ?? '' );
        }
    }
    return $css;
};

/**
 * Top-level rules carrying `$body`, split into front-end and editor rules.
 *
 * @return array{front: list<string>, editor: list<array{prelude: string, body: string}>}
 */
$rulesCarrying = static function (string $css, string $body): array {
    $rules = array( 'front' => array(), 'editor' => array() );
    ( new CssStylesheetTransformer() )->visitStyleRules($css, static function (string $prelude, string $ruleBody) use (&$rules, $body): void {
        if ( ! str_contains($ruleBody, $body) ) {
            return;
        }
        if ( str_contains($prelude, '.editor-styles-wrapper') ) {
            $rules['editor'][] = array( 'prelude' => trim($prelude), 'body' => $ruleBody );
            return;
        }
        foreach ( CssStylesheetTransformer::splitSelectorList($prelude) ?? array() as $selector ) {
            $rules['front'][] = trim($selector);
        }
    });
    return $rules;
};

/**
 * Every flat selector a nested rule stands for, `&` read as each parent arm.
 *
 * @return list<string>
 */
$expand = static function (string $prelude, string $body) use (&$expand): array {
    $selectors = array_map('trim', CssStylesheetTransformer::splitSelectorList($prelude) ?? array());
    $nested = array_values(array_filter(( new CssStylesheetTransformer() )->splitStyleRuleBody($body), static fn (array $part): bool => isset($part['prelude'])));
    if ( array() === $nested ) {
        return $selectors;
    }
    $flat = array();
    foreach ( $nested as $part ) {
        foreach ( $expand($part['prelude'], $part['body']) as $child ) {
            foreach ( $selectors as $parent ) {
                $flat[] = str_replace('&', $parent, $child);
            }
        }
    }
    return $flat;
};

$specificity = static fn (string $selector): array => CssSelectorMatcher::specificityCounts(CssSelectorMatcher::parse($selector));

/** Editor canvas DOM for `.b0 > … > .bk`, a layer after each boundary in `$layered`, and `$intruder` (a plain div) after `$intruded`. */
$editorDocument = static function (int $k, array $layered, ?int $intruded = null) use ($layer): DOMElement {
    $html = '<div class="editor-styles-wrapper">';
    for ( $index = 0; $index <= $k; ++$index ) {
        if ( $index > 0 && in_array($index, $layered, true) ) {
            $html .= '<div class="' . $layer . '">';
        }
        if ( $index > 0 && $index === $intruded ) {
            $html .= '<div class="intruder">';
        }
        $html .= '<div class="b' . $index . '"' . ( $index === $k ? ' id="subject"' : '' ) . '>';
    }
    $document = new DOMDocument();
    $document->loadHTML('<html><body>' . $html . '</body></html>', LIBXML_NOERROR);
    return $document->getElementById('subject');
};

$matchesAny = static function (DOMElement $element, array $selectors): bool {
    foreach ( $selectors as $selector ) {
        if ( CssSelectorMatcher::matches($element, CssSelectorMatcher::parse($selector))['matches'] ) {
            return true;
        }
    }
    return false;
};

// One rule whose selector crosses k possible shell boundaries, k = 1..6.
$editorBytes = array();
$frontBytes = array();
foreach ( range(1, 6) as $k ) {
    $selector = implode(' > ', array_map(static fn (int $index): string => '.b' . $index, range(0, $k)));
    $markup = '';
    foreach ( range(0, $k) as $index ) {
        $markup .= '<div class="b' . $index . '"><span>Copy ' . $index . '</span>';
    }
    $markup .= str_repeat('</div>', $k + 1);
    $rules = $rulesCarrying($cssFor('<style>' . $selector . '{' . $body . '}</style>' . $markup), $body);
    $editorCss = implode('', array_map(static fn (array $rule): string => $rule['prelude'] . '{' . $rule['body'] . '}', $rules['editor']));
    $editorArms = array_sum(array_map(static fn (array $rule): int => count(CssStylesheetTransformer::splitSelectorList($rule['prelude']) ?? array()), $rules['editor']));

    $assert($rules['front'] === array( $selector ), "k={$k}: the front-end rule keeps its authored selector", implode(' | ', $rules['front']));
    $assert($editorArms <= 2 * count($rules['front']), "k={$k}: editor arms stay within twice the front arms", $editorArms . ' editor arms: ' . $editorCss);
    $assert(substr_count($editorCss, $layer) === $k, "k={$k}: each boundary names the shell layer once", $editorCss);

    $flat = array();
    foreach ( $rules['editor'] as $rule ) {
        $flat = array_merge($flat, $expand($rule['prelude'], $rule['body']));
    }
    $assert(count($flat) === 2 ** $k, "k={$k}: the editor rule stands for every layer placement", (string) count($flat));
    $weights = array_values(array_unique(array_map(static fn (string $flatSelector): string => json_encode($specificity($flatSelector)), $flat)));
    $assert($weights === array( json_encode($specificity($selector)) ), "k={$k}: every placement weighs what the authored selector weighs", implode(' ', $weights));

    if ( $k <= 4 ) {
        $reached = true;
        for ( $mask = 0; $mask < ( 1 << $k ); ++$mask ) {
            $layered = array_values(array_filter(range(1, $k), static fn (int $index): bool => 0 !== ( $mask & ( 1 << ( $index - 1 ) ) )));
            $reached = $reached && $matchesAny($editorDocument($k, $layered), $flat);
        }
        $assert($reached, "k={$k}: the style reaches its element through any mix of shell layers");
        $assert(! $matchesAny($editorDocument($k, array(), $k), $flat), "k={$k}: a boundary stays a child step, so an unrelated wrapper is not crossed");
    }
    $editorBytes[$k] = strlen($editorCss);
    $frontBytes[$k] = strlen($selector . '{' . $body . '}');
}
$growth = array();
foreach ( range(2, 6) as $k ) {
    $growth[] = $editorBytes[$k] - $editorBytes[$k - 1];
}
$assert(1 === count(array_unique($growth)), 'each further boundary adds the same editor bytes', json_encode($editorBytes));
// Before, k boundaries emitted 2^k - 1 editor arms (k <= 3), each restating the whole chain.
$assert($editorBytes[3] < 3 * $frontBytes[3] + 3 * strlen(EditorShellBoundary::layer()) + 64, 'three boundaries cost one tolerant step each, not seven restated chains', json_encode(array( $editorBytes, $frontBytes )));

// The editor rule does not depend on how many runs of the structure a page has.
$runCss = array();
foreach ( array( 1, 4, 16 ) as $runs ) {
    $markup = str_repeat('<div class="b0"><div class="b1"><div class="b2"><p>Copy</p></div></div></div>', $runs);
    $rules = $rulesCarrying($cssFor('<style>.b0 > .b1 > .b2{' . $body . '}</style>' . $markup), $body);
    $runCss[$runs] = json_encode($rules);
}
$assert(1 === count(array_unique($runCss)), 'repeated runs of a structure leave the projected rules unchanged', implode("\n", $runCss));

// Navigation replays follow the same form: one editor rule per replay, and
// no arm that fuses the navigation root onto the shell layer or another element.
$compatInput = $cssFor('<style>.b0 > .b1 > .b2 > .menu a{' . $body . '}figure.card > a{' . $body . '}</style>'
    . '<div class="b0"><div class="b1"><div class="b2"><nav class="menu"><a href="/a">A</a></nav></div></div></div><figure class="card"><a href="/b">B</a></figure>');
$compat = ( new WordPressCompatCss() )->css($compatInput, array(), array());
$compatRules = $rulesCarrying($compat, $body);
$compatEditorArms = array_sum(array_map(static fn (array $rule): int => count(CssStylesheetTransformer::splitSelectorList($rule['prelude']) ?? array()), $compatRules['editor']));
$assert(array() !== $compatRules['front'] && array() !== $compatRules['editor'], 'navigation replays keep their editor rule', $compat);
$assert($compatEditorArms <= 2 * count($compatRules['front']), 'navigation editor arms stay within twice the front arms', $compat);
$assert(substr_count($compat, $layer) === 3 * count($compatRules['editor']), 'each navigation editor rule names each boundary once', $compat);
$assert(1 !== preg_match('/\.wp-block-navigation:where\((?:\.' . preg_quote($layer, '/') . '|figure)|figure\.card\.wp-block-navigation/', $compat), 'no replay fuses the navigation root onto the shell layer or another element', $compat);

if ( $failures > 0 ) {
    fwrite(STDERR, "Editor shell boundary projection size: {$failures} failed, {$passes} passed\n");
    exit(1);
}
fwrite(STDOUT, "Editor shell boundary projection size passed: {$passes} assertions\n");
