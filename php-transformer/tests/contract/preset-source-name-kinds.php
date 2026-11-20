<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

// Token values deliberately overlap across typography and spacing. Equality
// alone is not evidence that a token is a suitable name for both kinds.
$html = <<<'HTML'
<!doctype html><html><head><style>
:root {
    --backgroundOverlayColor: #d9e7f2;
    --black-hsl: 30, 12.5%, 18.823529%;
    --base-font-size: 16px;
    --heading-2-size: 32px;
    --textSize: 20px;
    --line-height: 28px;
    --sectionGap: 24px;
    --brand-rgba: #a53256;
    --surface-hex-color: #ffffff;
    --true-black-rgb: #000000;
}
body { background: var(--surface-hex-color); color: hsl(var(--black-hsl)); font-size: var(--base-font-size) }
main { background: var(--backgroundOverlayColor); padding: 16px }
h2 { font-size: var(--heading-2-size); margin: 32px }
p { padding: 20px; margin: 28px }
h3 { font-size: 24px; padding: var(--sectionGap); color: var(--brand-rgba) }
small { color: var(--true-black-rgb) }
</style></head><body><main>
<h2>A heading with generous spacing</h2>
<p>Readable body copy with a dark brown source colour and a pale overlay surface.</p>
<h3>A smaller heading sharing its size with a section gap</h3>
<small>Fine print in actual black.</small>
</main></body></html>
HTML;
$compile = static function (string $html): array {
    $plan = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array('index.html' => $html)))->toArray()['source_reports']['wordpress_site_plan'];
    foreach ($plan['writes'] as $write) {
        if ('theme.json' === $write['target_path']) return json_decode($write['payload']['data'], true, 512, JSON_THROW_ON_ERROR)['settings'];
    }
    throw new RuntimeException('Missing theme settings.');
};
$assert = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};
$settings = $compile($html);
$palette = $settings['color']['palette'];
$names = array_column($palette, 'name', 'color');
$assert(in_array('Background Overlay', $names, true), 'Split camelCase and remove trailing Color.');
$assert(in_array('Brand', $names, true), 'Remove rgba encoding from source names.');
$assert(in_array('Surface', $names, true), 'Remove hex encoding and trailing Color.');
$assert('True Black' === ($names['#000000'] ?? null), 'Keep a hue word when the value agrees and strip rgb.');
$assert(isset($names['#36302a']), 'Keep the brown source paint in the palette.');
$assert(1 === preg_match('/(?:Brown|Gray)/', $names['#36302a']), 'Contradicting Black must fall back to its derived family.');
foreach ($palette as $color) {
    $assert(1 !== preg_match('/\b(?:hsl|rgb|rgba|hex)\b|Color$/i', $color['name']), 'No encoding words or trailing Color in colour names.');
}
$fonts = array_column($settings['typography']['fontSizes'], 'name');
$spacing = array_column($settings['spacing']['spacingSizes'], 'name');
$spacingValues = array_column($settings['spacing']['spacingSizes'], 'size');
foreach (array('16px' => '1rem', '32px' => '2rem') as $px => $rem) {
    $assert(in_array($px, $spacingValues, true) || in_array($rem, $spacingValues, true), "Fixture must retain spacing sharing the {$px} typography value.");
}
$assert(in_array('Base Font Size', $fonts, true), 'Retain base font-size names for typography.');
$assert(in_array('Heading 2 Size', $fonts, true), 'Retain heading-size names for typography.');
$assert(in_array('Section Gap', $spacing, true), 'Retain camelCase spacing names for spacing.');
$assert(!in_array('Section Gap', $fonts, true), 'Do not borrow spacing names for font sizes.');
foreach (array('Base Font Size', 'Heading 2 Size', 'Text Size', 'Line Height') as $name) {
    $assert(!in_array($name, $spacing, true), "Do not borrow {$name} for spacing.");
}
// Prove the typography names actually encounter spacing presets of the same
// values, and that removing them yields the same derived scale names.
$derived = $compile(str_replace(array('--base-font-size', '--heading-2-size', '--textSize', '--line-height'), array('--font-size-1', '--font-size-2', '--font-size-3', '--font-size-4'), $html));
$assert($settings['spacing']['spacingSizes'] === $derived['spacing']['spacingSizes'], 'Typography token spelling must not affect spacing presets.');
echo "Source preset name kinds contract passed.\n";
