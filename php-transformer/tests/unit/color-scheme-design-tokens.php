<?php
declare(strict_types=1);

/**
 * A `prefers-color-scheme` override of `:root` tokens (#3793) leaves the
 * default scheme as the design system: body and heading families, `base`
 * and `contrast` come from the tokens' unconditional values, and a token the
 * override never touches still resolves.
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

$html = <<<'HTML'
<!doctype html><html lang="en"><head><meta charset="utf-8"><title>Scheme</title><style>
:root{--bg:#f3f5f8;--ink:#16202f;--f-body:"Barlow",sans-serif;--f-display:"Oswald",sans-serif}
@media (prefers-color-scheme: dark){:root:not([data-theme="light"]){--bg:#0a111d;--ink:#e8edf5}}
body{margin:0;background:var(--bg);color:var(--ink);font-family:var(--f-body)}
h1,h2{font-family:var(--f-display)}
</style></head><body><main>
<h1>Steel buildings</h1><p>Pre-engineered buildings for warehouses, shops and offices across the state.</p>
<h2>Why us</h2><p>Family owned, with in-house engineering and erection crews for every project we take on.</p>
</main></body></html>
HTML;

$plan = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array('index.html' => $html)))->toArray()['source_reports']['wordpress_site_plan'];
$theme = null;
foreach ($plan['writes'] as $write) if ('theme.json' === $write['target_path']) $theme = json_decode((string) $write['payload']['data'], true);

$families = array_column($theme['settings']['typography']['fontFamilies'] ?? array(), 'name', 'slug');
$palette = array_column($theme['settings']['color']['palette'] ?? array(), 'color', 'slug');
$checks = array(
    'body family is the body token' => 'Barlow' === ($families['body'] ?? null),
    'heading family is the display token' => 'Oswald' === ($families['heading'] ?? null),
    'root typography uses the body preset' => 'var:preset|font-family|body' === ($theme['styles']['typography']['fontFamily'] ?? null),
    'base is the default-scheme background token' => '#f3f5f8' === ($palette['base'] ?? null),
    'contrast is the default-scheme text token' => '#16202f' === ($palette['contrast'] ?? null),
    'the scheme-dependent root background stays out of fixed styles' => !isset($theme['styles']['color']['background']) || str_contains((string) $theme['styles']['color']['background'], 'var(--bg)'),
);
$failed = array_keys(array_filter($checks, static fn(bool $ok): bool => !$ok));
if (array() !== $failed) {
    fwrite(STDERR, 'FAIL: ' . implode('; ', $failed) . PHP_EOL . json_encode(array('families' => $families, 'palette' => $palette, 'styles' => $theme['styles']['color'] ?? null)) . PHP_EOL);
    exit(1);
}
echo "Color scheme design tokens passed.\n";
