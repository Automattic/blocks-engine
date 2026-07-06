<?php
declare(strict_types=1);

/**
 * Unit coverage for button/menu visual probe mismatch classification.
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\VisualParity\ButtonMenuVisualProbe;
use Automattic\BlocksEngine\PhpTransformer\VisualParity\ButtonMenuVisualProbeComparator;

$failures = 0;
$passes   = 0;

$assert = static function (bool $condition, string $message, string $detail = '') use (&$failures, &$passes): void {
    if ( $condition ) {
        ++$passes;
        return;
    }

    ++$failures;
    fwrite(STDERR, 'FAIL: ' . $message . ('' !== $detail ? ' - ' . $detail : '') . PHP_EOL);
};

$compare = static function (string $source, string $target): array {
    $probe = new ButtonMenuVisualProbe();
    return ( new ButtonMenuVisualProbeComparator() )->compare(
        $probe->extract($source),
        $probe->extract($target)
    );
};

$causeCodes = static function (array $report): array {
    $causes = $report['matches'][0]['mismatch_causes'] ?? array();
    $codes = array();
    foreach ( is_array($causes) ? $causes : array() as $cause ) {
        if ( is_array($cause) && isset($cause['code']) ) {
            $codes[] = (string) $cause['code'];
        }
    }
    sort($codes);
    return $codes;
};

$source = <<<'HTML'
<style>
.hero .cta { width: 220px; min-width: 180px; padding: 14px 24px; border-radius: 999px; background-color: #135e96; border: 2px solid #0b3d5c; color: #ffffff; }
</style>
<div class="hero" style="padding:20px;background:#eef6ff;border-radius:24px"><a class="cta" href="/demo">Get started</a></div>
HTML;

$target = <<<'HTML'
<style>
.wp-block-button__link { background-color: #eeeeee; border: 1px solid #cccccc; color: #111111; padding: 8px 12px; }
</style>
<div><a class="wp-block-button__link" href="/demo">Get started</a></div>
HTML;

$report = $compare($source, $target);
$codes = $causeCodes($report);

foreach ( array(
    'button_border_mismatch',
    'button_default_style_leakage',
    'button_fill_mismatch',
    'button_padding_mismatch',
    'button_radius_missing',
    'button_text_color_mismatch',
    'button_width_missing',
    'button_wrapper_chrome_mismatch',
) as $expectedCode ) {
    $assert(in_array($expectedCode, $codes, true), "reports {$expectedCode}", json_encode($codes));
}

$counts = $report['summary']['mismatch_cause_counts'] ?? array();
$assert(1 === ($counts['button_padding_mismatch'] ?? 0), 'summary counts classified padding causes', json_encode($counts));
$assert(1 === ($counts['button_wrapper_chrome_mismatch'] ?? 0), 'summary counts wrapper chrome causes', json_encode($counts));

$nestedSource = '<nav><ul><li><a href="/demo">Get started</a></li></ul></nav>';
$nestedTarget = '<nav><ul><li><ul><li><a href="/demo">Get started</a></li></ul></li></ul></nav>';
$nestedReport = $compare($nestedSource, $nestedTarget);
$assert(in_array('button_nesting_mismatch', $causeCodes($nestedReport), true), 'reports nesting depth mismatch', json_encode($nestedReport['matches'][0] ?? array()));

$borderlessReport = $compare(
    '<style>.cta{border:none;padding:12px 20px;background:#111;color:#fff}</style><a class="cta" href="/demo">Start</a>',
    '<style>.wp-block-button__link{padding:12px 20px;background:#111;color:#fff}</style><a class="wp-block-button__link" href="/demo">Start</a>'
);
$assert(! in_array('button_border_missing', $causeCodes($borderlessReport), true), 'ignores non-visual border:none declarations', json_encode($borderlessReport['matches'][0] ?? array()));

$zeroBorderReport = $compare(
    '<style>.cta{border:0;padding:12px 20px;background:#111;color:#fff}</style><a class="cta" href="/demo">Start</a>',
    '<style>.wp-block-button__link{padding:12px 20px;background:#111;color:#fff}</style><a class="wp-block-button__link" href="/demo">Start</a>'
);
$assert(! in_array('button_border_missing', $causeCodes($zeroBorderReport), true), 'ignores non-visual border:0 declarations', json_encode($zeroBorderReport['matches'][0] ?? array()));

$hoverReport = $compare(
    '<style>.cta{background:#e8a020;color:#050d1a;padding:9px 20px}.cta:hover{background:#f0ac22}</style><a class="cta" href="/demo">Start</a>',
    '<style>.wp-block-button__link{background:#e8a020;color:#050d1a;padding:9px 20px}</style><a class="wp-block-button__link" href="/demo">Start</a>'
);
$assert(! in_array('button_fill_mismatch', $causeCodes($hoverReport), true), 'ignores hover fill when comparing resting button state', json_encode($hoverReport['matches'][0] ?? array()));

$widthReport = $compare(
    '<style>.cta{width:100%;padding:9px 20px;background:#111;color:#fff}</style><a class="cta" href="/demo">Start</a>',
    '<style>.wp-block-button__link{padding:9px 20px;background:#111;color:#fff}</style><div class="wp-block-button has-custom-width wp-block-button__width-100"><a class="wp-block-button__link" href="/demo">Start</a></div>'
);
$assert(! in_array('button_width_missing', $causeCodes($widthReport), true), 'recognizes core/button custom width wrapper classes', json_encode($widthReport['matches'][0] ?? array()));

$shadowReport = $compare(
    '<style>.cta{padding:9px 20px;background:#e8a020;color:#050d1a;box-shadow:0 0 24px rgba(232,160,32,0.3)}</style><a class="cta" href="/demo">Start</a>',
    '<style>.wp-block-button__link{padding:9px 20px;background:#e8a020;color:#050d1a}</style><a class="wp-block-button__link" href="/demo">Start</a>'
);
$assert(in_array('button_shadow_missing', $causeCodes($shadowReport), true), 'reports missing button shadow/glow', json_encode($shadowReport['matches'][0] ?? array()));

if ( $failures > 0 ) {
    fwrite(STDERR, "Button visual probe diagnostic tests: {$failures} failed, {$passes} passed\n");
    exit(1);
}

fwrite(STDOUT, "Button visual probe diagnostic tests: {$passes} passed\n");
