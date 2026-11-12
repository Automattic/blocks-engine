<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

$assert = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};
$compile = static function (string $html): array {
    $plan = (new ArtifactCompiler())->compile(array(
        'entrypoint' => 'index.html', 'files' => array('index.html' => $html),
    ))->toArray()['source_reports']['wordpress_site_plan'];
    foreach ($plan['writes'] as $write) {
        if ('theme.json' === ($write['target_path'] ?? '')) $theme = json_decode($write['payload']['data'], true);
    }
    return array($theme, $plan['pages'][0]['canonical_block_markup']);
};
$source = file_get_contents(dirname(__DIR__) . '/fixtures/font-stack-fallbacks.html');
// Neutral family names exercise the reduced page without depending on a brand or installed font.
$source = str_replace(array('Cormorant Garamond', 'Montserrat'), array('Example Display', 'Example Body'), $source);
[$theme, $markup] = $compile($source);
$families = array_column($theme['settings']['typography']['fontFamilies'], 'fontFamily', 'slug');
$assert('"Example Display", Georgia, serif' === ($families['heading'] ?? null), 'The representative preset keeps its fallback stack');
$assert('var:preset|font-family|heading' === ($theme['styles']['elements']['heading']['typography']['fontFamily'] ?? $theme['styles']['elements']['h2']['typography']['fontFamily'] ?? null), 'Heading element styles bind the shared primary family despite different fallbacks');
preg_match_all('/<!-- wp:heading\b(.*?)-->/s', $markup, $headings);
$assert(3 === count($headings[1]), 'All reduced-page headings survive conversion');
foreach ($headings[1] as $attributes) {
    $attributes = json_decode(trim($attributes), true);
    $assert(!isset($attributes['fontFamily']) && !isset($attributes['style']['typography']['fontFamily']), 'Headings inherit the preset element style without a literal override');
}

// Generic primaries are distinct families, not interchangeable fallbacks.
[$genericTheme, $genericMarkup] = $compile('<style>body{font-family:serif}h2{font-family:sans-serif}h3{font-family:monospace}</style><main><p>Body</p><h2>Title</h2><h3>Subtitle</h3></main>');
$assert('inherit' === ($genericTheme['styles']['elements']['heading']['typography']['fontFamily'] ?? null), 'Different generic primaries leave the shared heading default inheriting');
$genericFamilies = array_column($genericTheme['settings']['typography']['fontFamilies'], 'fontFamily', 'slug');
preg_match_all('/<!-- wp:heading\b(.*?)-->/s', $genericMarkup, $genericHeadings);
foreach ($genericHeadings[1] as $index => $attributes) {
    $attributes = json_decode(trim($attributes), true);
    $reference = $genericTheme['styles']['elements']['h' . ($index + 2)]['typography']['fontFamily'] ?? '';
    $slug = $attributes['fontFamily'] ?? str_replace('var:preset|font-family|', '', $reference);
    $assert(array('sans-serif', 'monospace')[$index] === ($genericFamilies[$slug] ?? null), 'Generic heading families bind distinct presets');
}
echo "Font stack fallbacks passed\n";
