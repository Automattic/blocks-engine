<?php
declare(strict_types=1);

/**
 * A page painted with `light-dark()` keeps the light scheme as its design
 * system, the scheme a WordPress theme renders by default. The canvas becomes
 * `base` and the root background preset, as `html{background:#eee}` does,
 * while the dark argument keeps rendering from the bound author rule.
 * Text and element colours stay out of fixed styles, like a
 * `prefers-color-scheme` variant: an element style would override the dark
 * argument. A finding names the values whose dark argument no token carries.
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\Css\CssColor;

$compile = static function (string $html): array {
    $result = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array('index.html' => $html)))->toArray();
    $theme = null;
    $css = '';
    foreach ($result['source_reports']['wordpress_site_plan']['writes'] as $write) {
        $data = $write['payload']['data'] ?? null;
        if ('theme.json' === $write['target_path']) $theme = json_decode((string) $data, true);
        elseif (is_string($data) && str_ends_with((string) $write['target_path'], '.css')) $css .= $data . "\n";
    }
    $values = array();
    foreach ($result['diagnostics'] ?? array() as $diagnostic) {
        if ('design_usage_dark_scheme_color_untokenized' === ($diagnostic['code'] ?? null)) array_push($values, ...($diagnostic['values'] ?? array()));
    }
    return array($theme, $css, $result, $values);
};

$page = static fn(string $css, string $body = '<p>Page background uses light-dark().</p>'): string => '<!doctype html><html lang="en"><head><meta charset="utf-8"><title>Light dark</title><style>' . $css . '</style></head><body>' . $body . '</body></html>';

// The reduced example.com pattern, and the same page with a plain background.
[$theme, $css, $result, $findings] = $compile($page('html{color-scheme:light dark;background:light-dark(#eee,#222)}body{margin:0}'));
[$plainTheme] = $compile($page('html{color-scheme:light dark;background:#eee}body{margin:0}'));
$palette = array_column($theme['settings']['color']['palette'] ?? array(), 'color', 'slug');

[$textTheme, $textCss] = $compile($page(
    'html{color-scheme:light dark;background:light-dark(#eee,#222);color:light-dark(#111,#f5f5f5)}h2{color:light-dark(#7c2d12,#fed7aa)}',
    '<h2>Heading</h2><p>Body copy that inherits the root text colour across the page.</p>'
));
$textPalette = array_column($textTheme['settings']['color']['palette'] ?? array(), 'color', 'slug');

$checks = array(
    'light-dark() parses as its light argument' => '#eeeeee' === CssColor::hex('light-dark(#eee, #222)') && '#010203' === CssColor::hex('LIGHT-DARK(rgb(1 2 3), rgb(4 5 6))'),
    'light-dark() needs exactly two arguments' => null === CssColor::hex('light-dark(#eee)') && null === CssColor::hex('light-dark(#eee), light-dark(#222)'),
    'base is the light canvas' => '#eeeeee' === ($palette['base'] ?? null),
    'the root background is the base preset' => 'var:preset|color|base' === ($theme['styles']['color']['background'] ?? null),
    'theme.json matches a plain #eee canvas' => $plainTheme === $theme,
    'the author canvas keeps its dark argument' => str_contains($css, 'background:light-dark(var(--wp--preset--color--base,#eee),#222)'),
    'a finding names the untokenized dark argument' => array('light-dark(#eee,#222)') === $findings,
    'contrast is the light text colour' => '#111111' === ($textPalette['contrast'] ?? null),
    'scheme-dependent text stays out of fixed root styles' => !isset($textTheme['styles']['color']['text']),
    'scheme-dependent headings get no element colour' => !isset($textTheme['styles']['elements']['heading']['color']),
    'author text keeps both arguments' => str_contains($textCss, 'color:light-dark(var(--wp--preset--color--contrast,#111),#f5f5f5)'),
);
$failed = array_keys(array_filter($checks, static fn(bool $ok): bool => !$ok));
if (array() !== $failed) {
    fwrite(STDERR, 'FAIL: ' . implode('; ', $failed) . PHP_EOL . json_encode(array('palette' => $palette, 'styles' => $theme['styles'] ?? null, 'text_styles' => $textTheme['styles'] ?? null)) . PHP_EOL);
    exit(1);
}
echo "light-dark design tokens passed.\n";
