<?php
declare(strict_types=1);

/**
 * Contract: a source inline `!important` declaration outranks author
 * `!important` rules on the same element whatever their specificity, so its
 * generated class carrier must out-specify each of them, including an
 * ID-scoped rule inside a media query.
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

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

/** @return array{int, int, int} */
$specificity = static function (string $selector): array {
    $ids = preg_match_all('/#[A-Za-z0-9_-]+/', $selector);
    $classes = preg_match_all('/\.[A-Za-z0-9_-]+|\[[^\]]+\]/', $selector);
    return array( (int) $ids, (int) $classes, 0 );
};

$engineCss = static function (array $result): string {
    $css = '';
    foreach ( is_array($result['assets'] ?? null) ? $result['assets'] : array() as $asset ) {
        if ( 'engine-support' === ($asset['source'] ?? '') ) $css .= (string) ($asset['content'] ?? '') . "\n";
    }
    return $css;
};

/** Selector of the carrier rule declaring the property, outside editor-only scope. */
$carrierSelector = static function (string $css, string $carrier, string $property): ?string {
    if ( ! preg_match_all('/([^{}]+)\{([^{}]*)\}/', $css, $matches, PREG_SET_ORDER) ) return null;
    foreach ( $matches as $match ) {
        $selector = trim($match[1]);
        if ( str_contains($selector, 'editor-styles-wrapper') || ! str_contains($selector, '.' . $carrier) ) continue;
        if ( preg_match('/(?:^|;)\s*' . preg_quote($property, '/') . '\s*:[^;]*!important/', $match[2]) ) return $selector;
    }
    return null;
};

$cases = array(
    'media-id' => array(
        'css' => '@media (max-width:767px){#panel{visibility:visible!important;transform:translateX(-249vw)!important}}.panel{position:fixed;top:0;left:0;width:100%}',
        'author' => array( 1, 0, 0 ),
    ),
    'static-id' => array(
        'css' => '#panel{transform:translateX(-249vw)!important}.panel{position:fixed;top:0;left:0}',
        'author' => array( 1, 0, 0 ),
    ),
    'class-chain' => array(
        'css' => '.shell .panel.panel{transform:translateX(-249vw)!important}.panel{position:fixed;top:0;left:0}',
        'author' => array( 0, 3, 0 ),
    ),
);

foreach ( $cases as $name => $case ) {
    $html = '<style>' . $case['css'] . '</style><div class="shell"><div id="panel" class="panel" style="visibility:visible!important;transform:translateX(0px)!important;width:320px"><p>One</p><p>Two</p></div></div>';
    $result = ( new HtmlTransformer() )->transform($html, array())->toArray();
    $serialized = (string) ($result['serialized_blocks'] ?? '');
    preg_match('/\b(be-inline-geometry-[a-f0-9]+)\b/', $serialized, $carrier);
    $assert(isset($carrier[1]), $name . '-carrier-allocated', $serialized);
    if ( ! isset($carrier[1]) ) continue;
    $css = $engineCss($result);
    $selector = $carrierSelector($css, $carrier[1], 'transform');
    $assert(null !== $selector, $name . '-transform-carrier-rule', $css);
    if ( null === $selector ) continue;
    $assert($specificity($selector) > $case['author'], $name . '-carrier-outranks-author-important', $selector);
    $assert(str_contains($css, 'translateX(0px) !important'), $name . '-open-transform-kept', $css);
    // Declarations promoted from normal inline priority keep the plain carrier.
    $widthSelector = $carrierSelector($css, $carrier[1], 'width');
    $assert('.' . $carrier[1] === $widthSelector, $name . '-normal-inline-plain-carrier', (string) $widthSelector);
}

// Without competing author !important rules the carrier stays a single class.
$plain = ( new HtmlTransformer() )->transform('<style>.panel{position:fixed;top:0}</style><div class="panel" style="transform:translateX(0px)!important"><p>One</p></div>', array())->toArray();
preg_match('/\b(be-inline-geometry-[a-f0-9]+)\b/', (string) ($plain['serialized_blocks'] ?? ''), $plainCarrier);
if ( isset($plainCarrier[1]) ) {
    $assert('.' . $plainCarrier[1] === $carrierSelector($engineCss($plain), $plainCarrier[1], 'transform'), 'uncontested-important-plain-carrier', $engineCss($plain));
}

if ( 0 < $failures ) {
    fwrite(STDERR, sprintf("%d failure(s), %d pass(es)\n", $failures, $passes));
    exit(1);
}
echo sprintf("inline-important-carrier-specificity: %d passes\n", $passes);
