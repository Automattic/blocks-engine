<?php
declare(strict_types=1);

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
    fwrite(STDERR, 'FAIL: ' . $message . ( '' !== $detail ? "\n" . $detail : '' ) . "\n");
};
$css = static function (array $result): string {
    $out = '';
    foreach ( $result['assets'] ?? array() as $asset ) {
        if ( is_array($asset) ) {
            $out .= (string) ($asset['content'] ?? '');
        }
    }
    return $out;
};

$result = (new HtmlTransformer())->transform(
    '<style>'
    . '.site-footer{display:grid;grid-template-rows:min-content 1fr min-content;height:auto;min-height:0}'
    . '.site-footer .nav{grid-row:1}'
    . '.site-footer .contact{grid-row:3}'
    . '.footer-mobile{display:none;min-height:428px;grid-template-rows:min-content 1fr min-content}'
    . '@media (max-width:1023px){.site-footer{display:none}.footer-mobile{display:grid;min-height:0}}'
    . '</style>'
    . '<footer>'
    . '<div class="site-footer"><nav class="nav"><a href="/privacy">Privacy Policy</a><a href="/a11y">Accessibility Statement</a></nav><p class="contact">© 2035 by Nimbus Commute</p></div>'
    . '<div class="footer-mobile"><nav><a href="/privacy">Privacy Policy</a><a href="/a11y">Accessibility Statement</a></nav><p>© 2035 by Nimbus Commute</p></div>'
    . '</footer>'
)->toArray();
$authorCss = $css($result);
$blocks = (string) ($result['serialized_blocks'] ?? '');

$assert(str_contains($blocks, 'site-footer') && str_contains($blocks, 'footer-mobile'), 'both complementary responsive footer variants remain editable');
$assert(str_contains($blocks, 'Privacy Policy') && str_contains($blocks, '© 2035 by Nimbus Commute'), 'footer row content survives in both variants');
$assert(
    (bool) preg_match('/\.site-footer\{[^}]*grid-template-rows:min-content min-content min-content/', $authorCss)
        && ! preg_match('/\.site-footer\{[^}]*grid-template-rows:[^;}]*1fr/', $authorCss),
    'auto-height footer grids collapse leftover fractional row tracks',
    $authorCss
);
$assert(
    (bool) preg_match('/\.footer-mobile\{[^}]*display:none/', $authorCss)
        && (bool) preg_match('/\.footer-mobile\{[^}]*grid-template-rows:min-content min-content min-content/', $authorCss)
        && ! preg_match('/\.footer-mobile\{[^}]*grid-template-rows:[^;}]*1fr/', $authorCss),
    'hidden complementary footer structures keep display:none and collapse leftover fractional tracks',
    $authorCss
);
$assert(
    str_contains($authorCss, '@media (max-width:1023px){.site-footer{display:none}.footer-mobile{display:grid;min-height:0}}'),
    'responsive footer visibility remains media-owned',
    $authorCss
);
$assert('pass' === ($result['source_reports']['wp_block_validity']['status'] ?? ''), 'generated footer variants remain Gutenberg-valid');

$hero = (new HtmlTransformer())->transform(
    '<style>.hero{display:grid;grid-template-rows:1fr auto;min-height:100vh}</style><section class="hero"><p>Build</p><p>Footer</p></section>'
)->toArray();
$assert(
    str_contains($css($hero), '.hero{display:grid;grid-template-rows:1fr auto;min-height:100vh}'),
    'definite-height grids retain fractional row tracks',
    $css($hero)
);

$revealed = (new HtmlTransformer())->transform(
    '<style>.drawer{display:none;grid-template-rows:min-content 1fr}@media (max-width:1023px){.drawer{display:grid;min-height:100vh;grid-template-rows:1fr auto}}</style><div class="drawer"><p>Hidden</p><p>Shown</p></div>'
)->toArray();
$revealedCss = $css($revealed);
$assert(
    (bool) preg_match('/\.drawer\{[^}]*grid-template-rows:min-content min-content/', $revealedCss)
        && str_contains($revealedCss, '@media (max-width:1023px){.drawer{display:grid;min-height:100vh;grid-template-rows:1fr auto}}'),
    'a hidden complementary grid keeps collapsed leftover tracks until its visible breakpoint owns a definite height',
    $revealedCss
);

$columns = (new HtmlTransformer())->transform(
    '<style>.row{display:grid;grid-template-columns:1fr 1fr;height:auto}</style><div class="row"><p>One</p><p>Two</p></div>'
)->toArray();
$assert(
    str_contains($css($columns), '.row{display:grid;grid-template-columns:1fr 1fr;height:auto}'),
    'fractional column tracks remain unchanged',
    $css($columns)
);

$collapsible = (new HtmlTransformer())->transform(
    '<style>'
    . '.faq-a{display:grid;grid-template-rows:0fr;transition:grid-template-rows 0.25s}'
    . '.faq-q[aria-expanded="true"] + .faq-a{grid-template-rows:1fr}'
    . '.faq-a > div{overflow:hidden}'
    . '.faq-b{display:grid;grid-template-rows:0.0fr}'
    . '.faq-c{display:grid;grid-template-rows:0fr 1fr}'
    . '</style>'
    . '<div class="faq-item"><button class="faq-q" type="button" aria-expanded="false">What is the first question?</button>'
    . '<div class="faq-a"><div><p>This is the first answer, hidden until opened.</p></div></div></div>'
    . '<div class="faq-b"><div><p>Second answer.</p></div></div>'
    . '<div class="faq-c"><p>Hidden</p><p>Shown</p></div>'
)->toArray();
$collapsibleCss = $css($collapsible);
$assert(
    (bool) preg_match('/\.faq-a\{[^}]*grid-template-rows:0fr/', $collapsibleCss)
        && (bool) preg_match('/\.faq-b\{[^}]*grid-template-rows:0\.0fr/', $collapsibleCss),
    'zero-valued fractional row tracks keep collapse patterns collapsed',
    $collapsibleCss
);
$assert(
    (bool) preg_match('/\.faq-c\{[^}]*grid-template-rows:0fr min-content/', $collapsibleCss),
    'zero-valued fractional row tracks survive alongside collapsed non-zero tracks',
    $collapsibleCss
);

if ( $failures > 0 ) {
    fwrite(STDERR, "Responsive footer geometry unit tests: {$failures} failed, {$passes} passed\n");
    exit(1);
}
fwrite(STDOUT, "Responsive footer geometry unit tests: {$passes} passed\n");
