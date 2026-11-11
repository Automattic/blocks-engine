<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

$url = 'https://fonts.googleapis.com/css2?family=Instrument+Serif:ital@0;1&family=Inter:wght@400;500;600&display=swap';
$result = (new ArtifactCompiler())->compile(array(
    'entrypoint' => 'index.html',
    'files' => array(
        'index.html' => '<!doctype html><html lang="en"><head><title>Salon</title><link rel="stylesheet" href="' . htmlspecialchars($url, ENT_QUOTES) . '"><style>h1{font-family:"Instrument Serif",serif}body{font-family:Inter,sans-serif}</style></head><body><main><h1>Salon</h1><p>Beauty treatments.</p></main></body></html>',
    ),
))->toArray();

if ('success' !== ($result['status'] ?? null)) {
    throw new RuntimeException('A page linking italic-only Google Fonts must compile successfully.');
}
$plan = $result['source_reports']['wordpress_site_plan']['theme']['font_materialization'];
$fonts = array_column($plan['fonts'], 'weights', 'family');
if (array(400) !== ($fonts['Instrument Serif'] ?? null) || array(400, 500, 600) !== ($fonts['Inter'] ?? null)) {
    throw new RuntimeException('Missing wght must default to 400 without changing explicit weight lists: ' . json_encode($fonts));
}
$faces = array();
$interWeights = array();
foreach ($plan['webfont_contract']['faces'] as $face) {
    if ('Instrument Serif' === $face['family']) {
        $faces[$face['style']] = $face['weight'];
    } elseif ('Inter' === $face['family']) {
        $interWeights[] = $face['weight'];
    }
}
ksort($faces);
if (array(
    'italic' => array('kind' => 'static', 'value' => 400),
    'normal' => array('kind' => 'static', 'value' => 400),
) !== $faces) {
    throw new RuntimeException('Instrument Serif must have normal and italic faces at weight 400: ' . json_encode($faces));
}
usort($interWeights, static fn (array $left, array $right): int => $left['value'] <=> $right['value']);
if (array_map(static fn (int $value): array => array('kind' => 'static', 'value' => $value), array(400, 500, 600)) !== $interWeights) {
    throw new RuntimeException('Explicit Inter face weights must be preserved.');
}
echo "Italic-only Google Fonts page compilation passed.\n";
