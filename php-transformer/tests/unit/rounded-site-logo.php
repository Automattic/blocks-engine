<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

// Reduced from the mebenedetto.com capture's header and block-library CSS.
$logo = '<a href="/index.html" class="custom-logo-link" rel="home"><img width="50" height="49" src="/media/matias-benedetto-avatar.jpg" class="custom-logo" alt="Benedetto" decoding="async" srcset="/media/matias-benedetto-avatar.jpg 500w, /media/matias-benedetto-avatar.jpg 300w, /media/matias-benedetto-avatar.jpg 150w" sizes="(max-width: 50px) 100vw, 50px" style="aspect-ratio: 50 / 50"></a>';
$css = '.wp-block-site-logo { box-sizing: border-box; line-height: 0; }
.wp-block-site-logo a { display: inline-block; line-height: 0; }
.wp-block-site-logo img { height: auto; max-width: 100%; }
.wp-block-site-logo a, .wp-block-site-logo img { border-radius: inherit; }
:root :where(.wp-block-site-logo.is-style-rounded) { border-radius: 9999px; }';

foreach (array(
    'is-style-rounded is-style-rounded--1 wp-block-site-logo' => true,
    'wp-block-site-logo' => false,
) as $classes => $rounded) {
    $html = '<!doctype html><html><head><title>Logo</title><style>' . $css . '</style></head><body><header><div class="' . $classes . '">' . $logo . '</div></header></body></html>';
    $result = (new HtmlTransformer())->transform($html)->toArray();
    $markup = (string) ($result['serialized_blocks'] ?? '');
    preg_match('/<!-- wp:image (\{.*?\}) -->/', $markup, $match);
    $attrs = json_decode($match[1] ?? '{}', true);
    $hasRounded = in_array('is-style-rounded', explode(' ', $attrs['className'] ?? ''), true);
    if ('success' !== ($result['status'] ?? '') || $rounded !== $hasRounded || '50px' !== (string) ($attrs['width'] ?? '')
        || ($rounded && !preg_match('/<figure[^>]*class="[^"]*\bis-style-rounded\b/', $markup))) {
        fwrite(STDERR, "FAIL: site logo style or width lost for $classes\n$markup\n");
        exit(1);
    }
}
// Verify the rendered contract, not merely core/image's style class. Core's
// :where(.wp-block-image.is-style-rounded img) loses to the projected logo
// selector, so the replacement figure must supply the inherited radius.
foreach (array($logo, '<img src="/avatar.jpg" width="50" height="50" alt="Logo">') as $image) {
    foreach (array('9999px', '24px') as $radius) {
        $override = '9999px' === $radius ? '' : '<style>.wp-block-site-logo.is-style-rounded { border-radius:24px; }</style>';
        $html = '<html><head><style>' . $css . '</style>' . $override . '</head><body><header><div class="wp-block-site-logo is-style-rounded">' . $image . '</div></header></body></html>';
        $result = (new HtmlTransformer())->transform($html)->toArray();
        $markup = (string) ($result['serialized_blocks'] ?? '');
        $emittedCss = implode("\n", array_column(array_filter($result['assets'] ?? array(), static fn(array $asset): bool => 'css' === ($asset['kind'] ?? '')), 'content'));
        preg_match('/<figure[^>]*class="([^"]*)"/', $markup, $figure);
        preg_match_all('/\bbe-inline-geometry-[a-z0-9]+\b/', $figure[1] ?? '', $carriers);
        $hasRadiusCarrier = false;
        foreach ($carriers[0] as $carrier) {
            $hasRadiusCarrier = $hasRadiusCarrier || str_contains($emittedCss, '.' . $carrier . '{border-radius:' . $radius . ' !important}');
        }
        if ('success' !== ($result['status'] ?? '') || !$hasRadiusCarrier
            || !preg_match('/<figure[^>]*>\s*(?:<a\b[^>]*>)?<img\b/', $markup)
            || !preg_match('/:is\(a,\s*img\)\s*\{\s*border-radius:\s*inherit/', $emittedCss)) {
            fwrite(STDERR, "FAIL: replacement figure does not supply the inherited $radius radius\n$markup\n$emittedCss\n");
            exit(1);
        }
        // In this reduced capture every radius declaration must either provide
        // the source radius or inherit it. In particular, no emitted rule may
        // reset any corner on figure, a, or img to zero. The important carrier
        // wins on the figure; a and img inherit from it regardless of core's
        // zero-specificity image rule (or stylesheet order).
        preg_match_all('/border-(?:radius|[a-z-]+-radius)\s*:\s*([^;}]+)/', $emittedCss, $radii);
        foreach ($radii[1] as $value) {
            $value = trim(str_replace('!important', '', $value));
            if (!in_array($value, array('inherit', '9999px', $radius), true)) {
                fwrite(STDERR, "FAIL: emitted CSS re-zeroes or changes the radius: $value\n$emittedCss\n");
                exit(1);
            }
        }
        if (preg_match('/style="[^"]*border-(?:radius|[a-z-]+-radius):(?:0|initial|unset)\b/', $markup)) {
            fwrite(STDERR, "FAIL: emitted markup resets the radius\n$markup\n");
            exit(1);
        }
    }
}
echo "PASS: rounded site logo renders with its inherited wrapper radius\n";
