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
echo "PASS: rounded site logo survives image lowering\n";
