<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\Css\CssSelectorMatcher;
use Automattic\BlocksEngine\PhpTransformer\Css\CssStylesheetTransformer;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

// Core's global-styles button element rule,
// `:root :where(.wp-element-button, .wp-block-button__link)`, weighs (0,1,0).
// The editor prints global styles after every other style, so an authored
// button rule projected onto the link must outrank it on specificity, not on
// load order. The extra weight must not reorder authored button rules.

$failures = 0;
$check = static function (bool $ok, string $label, string $detail = '') use (&$failures): void {
    echo ($ok ? 'PASS' : 'FAIL') . ': ' . $label . ($ok || '' === $detail ? '' : "\n" . $detail) . "\n";
    if ( ! $ok ) {
        ++$failures;
    }
};

/** @return array{ids:int,classes:int,types:int} */
$weight = static fn (string $selector): array => CssSelectorMatcher::specificityCounts(CssSelectorMatcher::parse(trim($selector)));
$compare = static fn (array $a, array $b): int => array( $a['ids'], $a['classes'], $a['types'] ) <=> array( $b['ids'], $b['classes'], $b['types'] );

/**
 * The weakest link arm among the plain (not !important) rules carrying the
 * given declaration: the arm that would tie core before this fix.
 *
 * @return array{selector: string, weight: array{ids:int,classes:int,types:int}}|null
 */
$linkArm = static function (string $css, string $declaration) use ($weight, $compare): ?array {
    $best = null;
    foreach ( preg_split('/(?<=\})/', $css) ?: array() as $rule ) {
        $brace = strpos($rule, '{');
        if ( false === $brace || 1 !== preg_match('/' . preg_quote($declaration, '/') . '(?!\s*!important)(?:;|\})/', substr($rule, $brace)) ) {
            continue;
        }
        foreach ( CssStylesheetTransformer::splitSelectorList(substr($rule, 0, $brace)) ?? array() as $selector ) {
            if ( ! str_contains($selector, '.wp-block-button__link') || str_contains($selector, '@') ) {
                continue;
            }
            $counts = $weight($selector);
            if ( null === $best || $compare($counts, $best['weight']) < 0 ) {
                $best = array( 'selector' => trim($selector), 'weight' => $counts );
            }
        }
    }
    return $best;
};

$project = static function (string $css, string $body): string {
    $result = (new HtmlTransformer())->transform('<style>' . $css . '</style><main>' . $body . '</main>')->toArray();
    return implode("\n", array_column($result['assets'] ?? array(), 'content'));
};

$core = $weight(':root :where(.wp-element-button, .wp-block-button__link)');
$check(array( 'ids' => 0, 'classes' => 1, 'types' => 0 ) === $core, 'core button element rule weighs (0,1,0)', json_encode($core) ?: '');

// One authored single-class button.
$css = $project('.btn{padding:12px 24px;font-size:14px}', '<p>Intro</p><button class="btn" type="button">Go</button>');
$arm = $linkArm($css, 'font-size:14px');
$check(null !== $arm, 'authored .btn rule is projected onto the button link', $css);
if ( null !== $arm ) {
    $check(
        $compare($arm['weight'], $core) > 0,
        'projected .btn link rule outranks core (0,1,0): ' . json_encode($arm['weight']),
        $arm['selector']
    );
}

// Two authored button rules keep their relative order.
$css = $project(
    '.btn{font-size:14px}.cta .btn{font-size:18px}',
    '<p>Intro</p><button class="btn" type="button">A</button><div class="cta"><button class="btn" type="button">B</button></div>'
);
$plain = $linkArm($css, 'font-size:14px');
$scoped = $linkArm($css, 'font-size:18px');
$check(null !== $plain && null !== $scoped, 'both authored button rules are projected onto the link', $css);
if ( null !== $plain && null !== $scoped ) {
    $authored = $compare($weight('.cta .btn'), $weight('.btn'));
    $projected = $compare($scoped['weight'], $plain['weight']);
    $check(
        $authored === $projected,
        'authored order .btn < .cta .btn is preserved: ' . json_encode($plain['weight']) . ' vs ' . json_encode($scoped['weight']),
        $plain['selector'] . "\n" . $scoped['selector']
    );
    $check($compare($plain['weight'], $core) > 0 && $compare($scoped['weight'], $core) > 0, 'both authored button link rules outrank core');
}

exit($failures > 0 ? 1 : 0);
