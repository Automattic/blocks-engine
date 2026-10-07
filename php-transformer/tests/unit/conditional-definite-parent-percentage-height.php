<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

/*
 * A responsive capture scopes its desktop stylesheet under
 * `@media (min-width:768px)`. A Wix button wrapper states its definite size
 * there (`#comp{height:42px}`), and the anchor fills it with `height:100%`.
 * The auto-sized percentage-height projection must read the wrapper's height
 * under the same condition stack as the rule it rewrites, or it collapses the
 * button to its label height.
 */

$failures = 0;
$assert = static function (bool $condition, string $message) use (&$failures): void {
    if ( $condition ) {
        return;
    }
    ++$failures;
    fwrite(STDERR, "FAIL: {$message}\n");
};

$cssOf = static function (array $result): string {
    $css = '';
    foreach ( $result['assets'] ?? array() as $asset ) {
        if ( is_array($asset) && 'css' === ($asset['kind'] ?? '') ) {
            $css .= (string) ($asset['content'] ?? '');
        }
    }

    return $css;
};

$rulesFor = static function (string $css, string $selector): array {
    preg_match_all('/(?<![\w-])' . preg_quote($selector, '/') . '\{[^}]*\}/', $css, $matches);

    return $matches[0];
};

$transform = static function (string $css, string $body) use ($cssOf): string {
    $result = ( new HtmlTransformer() )->transform('<html><head><style>' . $css . '</style></head><body>' . $body . '</body></html>')->toArray();

    return $cssOf($result);
};

$button = '.btn__root{width:100%;min-width:10px;height:100%;min-height:10px;padding:0;display:block}';
$desktop = static fn (string $rules): string => '@media (min-width:768px){' . $rules . '}';
$markup = '<section><div><div id="wrap" class="wrap"><a class="btn__root" href="/x"><span>Visit Website</span></a></div></div></section>';

// The capture repeats the same stylesheet, so every copy must keep the fill.
$css = $transform(
    $desktop($button . '#wrap{width:150px;height:42px}') . $desktop($button) . $desktop($button),
    $markup
);
$rules = $rulesFor($css, '.btn__root');
$assert(3 === count($rules), 'all three repeated desktop button rules are emitted, got ' . count($rules));
foreach ( $rules as $index => $rule ) {
    $assert(
        str_contains($rule, 'height:100%') && ! str_contains($rule, 'height:auto'),
        "desktop button copy {$index} keeps height:100% against its definite desktop wrapper, got {$rule}"
    );
}

// Without any definite wrapper height the percentage is still indefinite in
// the source and keeps collapsing under the condition.
$css = $transform($desktop($button . '#wrap{width:150px}'), $markup);
$rules = $rulesFor($css, '.btn__root');
$assert(
    1 === count($rules) && str_contains($rules[0], 'height:auto') && ! str_contains($rules[0], 'height:100%'),
    'a desktop percentage height inside an auto-height section still collapses, got ' . implode(' ', $rules)
);

// A wrapper height stated only under a different condition does not hold
// where the desktop rule applies, so the desktop percentage stays indefinite.
$css = $transform(
    '@media (max-width:767px){#wrap{height:42px}}' . $desktop($button),
    $markup
);
$rules = $rulesFor($css, '.btn__root');
$assert(
    1 === count($rules) && str_contains($rules[0], 'height:auto'),
    'a wrapper height scoped to another media query does not make the desktop fill definite, got ' . implode(' ', $rules)
);

// A data-liberation capture links each desktop stylesheet with
// `media="(min-width:768px)"` instead of an @media block, and Wix repeats the
// button stylesheet. The link's media scopes every rule in the asset.
$linked = ( new ArtifactCompiler() )->compile(array(
    'files' => array(
        array( 'path' => 'index.html', 'kind' => 'html', 'content' => '<!doctype html><html><head>'
            . '<link rel="stylesheet" href="layout.css" media="(min-width:768px)">'
            . '<link rel="stylesheet" href="button-a.css" media="(min-width:768px)">'
            . '<link rel="stylesheet" href="button-b.css" media="(min-width:768px)">'
            . '<link rel="stylesheet" href="layout-mobile.css" media="(max-width:767px)">'
            . '</head><body>' . $markup . '</body></html>' ),
        array( 'path' => 'layout.css', 'kind' => 'css', 'content' => '#wrap{width:150px;height:42px}' ),
        array( 'path' => 'button-a.css', 'kind' => 'css', 'content' => $button ),
        array( 'path' => 'button-b.css', 'kind' => 'css', 'content' => $button ),
        array( 'path' => 'layout-mobile.css', 'kind' => 'css', 'content' => '#wrap{width:170px}' ),
    ),
) )->toArray();
$linkedRules = array();
foreach ( $linked['assets'] ?? array() as $asset ) {
    if ( is_array($asset) && str_starts_with((string) ($asset['path'] ?? ''), 'button-') ) {
        $linkedRules = array_merge($linkedRules, $rulesFor((string) ($asset['content'] ?? ''), '.btn__root'));
    }
}
$assert(2 === count($linkedRules), 'both media-linked button stylesheets are emitted, got ' . count($linkedRules));
foreach ( $linkedRules as $index => $rule ) {
    $assert(
        str_contains($rule, 'height:100%') && ! str_contains($rule, 'height:auto'),
        "media-linked button copy {$index} keeps height:100% against its definite desktop wrapper, got {$rule}"
    );
}

if ( $failures > 0 ) {
    fwrite(STDERR, "Conditional definite parent percentage height: {$failures} failed\n");
    exit(1);
}

echo "Conditional definite parent percentage height passed\n";
