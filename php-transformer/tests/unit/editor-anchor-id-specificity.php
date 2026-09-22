<?php
declare(strict_types=1);

/**
 * The editor drops a block wrapper's source id, so an authored `#id` rule is
 * restated on the deterministic anchor class. A bare class weighs 0,1,0 where
 * the authored selector weighed 1,0,0, so the restated rule loses to any
 * author rule that names more classes than it.
 *
 * gameover.ai: `#bganim{background:linear-gradient(-45deg,…)}` against Bulma's
 * `.hero.is-info.is-bold`. The correct gradient reaches the editor stylesheet
 * and is then outranked, so the canvas paints Bulma blue. Related: #1483.
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\Css\CssSelectorMatcher;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

$failures = 0;
$passes   = 0;
$assert = static function (bool $condition, string $message, string $detail = '') use (&$failures, &$passes): void {
    if ( $condition ) {
        ++$passes;
        return;
    }
    ++$failures;
    fwrite(STDERR, 'FAIL: ' . $message . ( '' !== $detail ? ' - ' . $detail : '' ) . PHP_EOL);
};

$editorCss = static function (string $html): string {
    $css = '';
    foreach ( ( new HtmlTransformer() )->transform($html)->toArray()['assets'] ?? array() as $asset ) {
        if ( is_array($asset) && 'editor-static-state' === ( $asset['source'] ?? '' ) ) {
            $css .= (string) ( $asset['content'] ?? '' );
        }
    }

    return $css;
};

/** Selector of the first rule whose declarations contain $needle. */
$selectorFor = static function (string $css, string $needle): string {
    foreach ( explode('}', str_replace('@media', "\n@media", $css)) as $rule ) {
        $brace = strpos($rule, '{');
        if ( false === $brace || ! str_contains(substr($rule, $brace), $needle) ) {
            continue;
        }
        $prelude = substr($rule, 0, $brace);
        return trim(substr($prelude, (int) strrpos("\n" . $prelude, '{')));
    }

    return '';
};

$specificity = static fn (string $selector): int => CssSelectorMatcher::specificity(CssSelectorMatcher::parse($selector));

$bulma = $specificity('.hero.is-info.is-bold');
$assert(30 === $bulma, '1: the competing author selector weighs three classes', (string) $bulma);

$css = $editorCss(
    '<html><head><style>'
    . '.hero.is-info.is-bold{background-image:linear-gradient(141deg, rgb(4,166,215) 0px, rgb(32,156,238) 71%, rgb(50,135,245) 100%)}'
    . '#bganim{background:linear-gradient(-45deg, rgb(238,119,82), rgb(231,60,126)) 0% 0% / 400% 400%}'
    . '</style></head><body><section class="hero is-fullheight is-info is-bold" id="bganim">'
    . '<div class="hero-body"><p>Hello</p></div></section></body></html>'
);
$projected = $selectorFor($css, '-45deg');

$assert('' !== $projected, '2: the authored id rule is restated for the editor', $css);
$assert(str_contains($projected, '.blocks-engine-editor-anchor-bganim'), '3: it is restated on the deterministic anchor class', $projected);
$assert($specificity($projected) > $bulma, '4: the restated rule outranks the author class rule it competes with', $projected . ' => ' . $specificity($projected) . ' vs ' . $bulma);
$assert(100 === $specificity($projected), '5: it weighs exactly the one id the author wrote, not more', $projected . ' => ' . $specificity($projected));

// The attribute spelling already weighs one attribute, the same as the class it
// becomes, so projecting it must not inflate it to id weight.
$attributeCss = $editorCss(
    '<html><head><style>[id="panel"]{background:#0f0}</style></head>'
    . '<body><main><div id="panel" class="panel"><p>Body</p></div></main></body></html>'
);
$attributeProjected = $selectorFor($attributeCss, '#0f0');

$assert('' !== $attributeProjected && str_contains($attributeProjected, 'blocks-engine-editor-anchor-panel'), '6: the attribute spelling is restated too', $attributeCss);
$assert(10 === $specificity($attributeProjected), '7: the attribute spelling keeps its authored one-class weight', $attributeProjected . ' => ' . $specificity($attributeProjected));

if ( $failures > 0 ) {
    fwrite(STDERR, PHP_EOL . "editor anchor id specificity: {$passes} passed, {$failures} FAILED" . PHP_EOL);
    exit(1);
}
fwrite(STDOUT, "editor anchor id specificity: {$passes} passed" . PHP_EOL);
