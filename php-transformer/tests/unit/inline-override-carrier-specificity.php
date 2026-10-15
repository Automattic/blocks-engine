<?php
declare(strict_types=1);

/**
 * A source inline declaration outranks every normal author rule, whatever its
 * specificity. When the carrier restates it in the generated stylesheet as
 * `:root .carrier` (0,2,0), a later author rule with two classes ties on
 * specificity and wins on rule order, so an inline `height:auto` banner
 * renders at the author's fixed height instead. The carrier selector must be
 * more specific than each class-level author rule it competes with, while a
 * one-class rule keeps the existing `:root .carrier` selector.
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

$failures = array();
$assert = static function (bool $condition, string $message) use (&$failures): void {
    if ( ! $condition ) {
        $failures[] = $message;
    }
};

$transform = static function (string $authorCss): string {
    $html = '<body class="landing"><div class="banner-wrap"><div class="hero" style="height:auto;background-image:url(https://example.com/hero.jpg);background-size:cover">'
        . '<div class="hero-inner"><h2>Studio title</h2><p>Studio tagline</p></div></div></div>'
        . '<div class="content"><p>Body copy.</p></div></body>';
    $result = ( new HtmlTransformer() )->transform($html, array( 'static_css' => $authorCss ))->toArray();
    $css = '';
    foreach ( $result['assets'] ?? array() as $asset ) {
        if ( 'css' === ($asset['kind'] ?? '') ) {
            $css .= (string) ($asset['content'] ?? '') . "\n";
        }
    }

    return $css;
};

$heightRuleSelector = static function (string $css): ?string {
    if ( 1 !== preg_match('/(:root (?:\.be-inline-geometry-[a-f0-9-]+)+)\{[^}]*\bheight:auto\b[^}]*\}/', $css, $match) ) {
        return null;
    }

    return $match[1];
};

// Two-class author rule: `.landing .hero` is (0,2,0), the same as `:root .x`.
$css = $transform('.hero{display:block;height:230px}.landing .hero{height:550px}');
$selector = $heightRuleSelector($css);
$assert(null !== $selector, 'The inline height:auto is carried in the non-important tier: ' . $css);
if ( null !== $selector ) {
    preg_match_all('/\.be-inline-geometry-[a-f0-9-]+/', $selector, $classes);
    $assert(2 <= count($classes[0]), 'The carrier outranks the two-class author height rule: ' . $selector);
}
$assert(! preg_match('/height:auto\s*!important/', $css), 'The carrier does not need !important to outrank normal author rules.');

// One-class author rule: `:root .x` already outranks `.hero`; keep its selector.
$css = $transform('.hero{display:block;height:550px}');
$selector = $heightRuleSelector($css);
$assert(null !== $selector, 'The inline height:auto is carried against a one-class rule: ' . $css);
if ( null !== $selector ) {
    preg_match_all('/\.be-inline-geometry-[a-f0-9-]+/', $selector, $classes);
    $assert(1 === count($classes[0]), 'A carrier facing only one-class rules keeps the :root .carrier selector: ' . $selector);
}

// An !important author rule keeps its priority over the inline declaration, as in the source.
$css = $transform('.hero{display:block}.landing .hero{height:550px !important}');
$selector = $heightRuleSelector($css);
if ( null !== $selector ) {
    preg_match_all('/\.be-inline-geometry-[a-f0-9-]+/', $selector, $classes);
    $assert(1 === count($classes[0]), 'An !important author rule is not a competitor the carrier must outrank: ' . $selector);
}

if ( array() !== $failures ) {
    fwrite(STDERR, implode(PHP_EOL, $failures) . PHP_EOL);
    exit(1);
}

fwrite(STDOUT, "Inline override carrier specificity contract passed\n");
