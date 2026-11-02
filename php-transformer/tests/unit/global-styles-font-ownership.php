<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

$assert = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};
$source = file_get_contents(dirname(__DIR__) . '/fixtures/global-styles-font-ownership.html');
$compile = static fn(string $html): array => (new ArtifactCompiler())->compile(array(
    'entrypoint' => 'index.html', 'files' => array('index.html' => $html),
))->toArray()['source_reports']['wordpress_site_plan'];
$plan = $compile($source);
$markup = $plan['pages'][0]['canonical_block_markup'];
$css = implode("\n", array_column($plan['assets'], 'content'));
$theme = null;
foreach ($plan['writes'] as $write) {
    if ('theme.json' === ($write['target_path'] ?? '')) $theme = json_decode($write['payload']['data'], true);
}
$assert(is_array($theme), 'Compiled theme.json is present');
$assert('var:preset|font-family|body' === $theme['styles']['typography']['fontFamily'], 'Body font is a root Global Styles default');
$assert('var:preset|font-family|heading' === $theme['styles']['elements']['heading']['typography']['fontFamily'], 'Heading font is an element Global Styles default');
$assert(!str_contains($markup, '"fontFamily"') && !str_contains($markup, 'has-heading-font-family'), 'Inherited fonts are not pinned by block attributes or preset classes');

// Emulate core's Global Styles output from the public theme.json contract.
// Source CSS and compiled markup are otherwise used verbatim in the browser.
$globalCss = static function (array $theme): string {
    $css = ':root{';
    foreach ($theme['settings']['typography']['fontFamilies'] as $font) $css .= '--wp--preset--font-family--' . $font['slug'] . ':' . $font['fontFamily'] . ';';
    $css .= '}';
    foreach ($theme['settings']['typography']['fontFamilies'] as $font) $css .= '.has-' . $font['slug'] . '-font-family{font-family:var(--wp--preset--font-family--' . $font['slug'] . ')!important}';
    $value = static fn(string $v): string => str_starts_with($v, 'var:preset|font-family|') ? 'var(--wp--preset--font-family--' . substr($v, strlen('var:preset|font-family|')) . ')' : $v;
    $css .= ':root :where(body){font-family:' . $value($theme['styles']['typography']['fontFamily']) . '}';
    foreach ($theme['styles']['elements'] as $element => $style) {
        if (!isset($style['typography']['fontFamily'])) continue;
        $selector = 'heading' === $element ? 'h1,h2,h3,h4,h5,h6' : $element;
        $css .= ':root :where(' . $selector . '){font-family:' . $value($style['typography']['fontFamily']) . '}';
    }
    return $css;
};
// A utility used on body copy must keep that explicit exception, and
// unrelated heading declarations must still apply after the font yields.
$shared = $compile(str_replace('.font-heading{font-family:var(--font-heading)}', '.font-heading{font-family:var(--font-heading);letter-spacing:2px}', str_replace('<p>', '<p class="font-heading">', $source)));
$sharedMarkup = $shared['pages'][0]['canonical_block_markup'];
$sharedCss = implode("\n", array_column($shared['assets'], 'content'));
foreach ($shared['writes'] as $write) {
    if ('theme.json' === ($write['target_path'] ?? '')) $sharedTheme = json_decode($write['payload']['data'], true);
}
$sharedTheme['styles']['typography']['fontFamily'] = 'Georgia, serif';
$sharedTheme['styles']['elements']['heading']['typography']['fontFamily'] = 'Georgia, serif';
$changed = $theme;
$changed['styles']['typography']['fontFamily'] = 'Georgia, serif';
$changed['styles']['elements']['heading']['typography']['fontFamily'] = 'Georgia, serif';
$chrome = getenv('CHROME_PATH') ?: trim((string) shell_exec('command -v chromium || command -v google-chrome || true'));
if ('' !== $chrome) {
    $temporary = tempnam(sys_get_temp_dir(), 'font-ownership-');
    $file = $temporary . '.html';
    try {
        foreach (array(390, 768, 1440) as $width) {
            $fonts = array();
            foreach (array($source, '<style>' . $css . $globalCss($theme) . '</style>' . $markup, '<style>' . $css . $globalCss($changed) . '</style>' . $markup, '<style>' . $sharedCss . $globalCss($sharedTheme) . '</style>' . $sharedMarkup) as $page) {
                $probe = '<script>parent.document.body.dataset.fonts=JSON.stringify(["p","h2"].map(s=>getComputedStyle(document.querySelector(s)).fontFamily))</script>';
                file_put_contents($file, '<!doctype html><body><iframe style="width:' . $width . 'px" srcdoc="' . htmlspecialchars($page . $probe, ENT_QUOTES) . '"></iframe>');
                $output = shell_exec(escapeshellarg($chrome) . ' --headless --no-sandbox --disable-gpu --dump-dom --virtual-time-budget=1000 ' . escapeshellarg('file://' . $file) . ' 2>/dev/null');
                preg_match('/data-fonts="([^"]+)"/', (string) $output, $match);
                $fonts[] = json_decode(html_entity_decode($match[1] ?? '', ENT_QUOTES), true);
            }
            $assert(is_array($fonts[0]) && $fonts[0] === $fonts[1], 'Default computed families match source at ' . $width . ': ' . json_encode($fonts));
            $assert(str_contains($fonts[3][0] ?? '', 'Inter Tight') && 'Georgia, serif' === ($fonts[3][1] ?? ''), 'Shared utility preserves non-heading exceptions at ' . $width . ': ' . json_encode($fonts));
            $assert(array('Georgia, serif', 'Georgia, serif') === $fonts[2], 'User Global Styles reach body and heading at ' . $width . ': ' . json_encode($fonts));
        }
    } finally {
        unlink($file);
        unlink($temporary);
    }
}

$assert(str_contains($sharedCss, 'letter-spacing:2px'), 'Unrelated utility declarations survive');
echo "Global Styles font ownership passed\n";
