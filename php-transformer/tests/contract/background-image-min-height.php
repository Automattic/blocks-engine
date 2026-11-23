<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

$source = '<style>.hero{min-height:417px;max-height:600px;background-size:cover;background-position:center}</style><main><div class="hero" style="background-image:url(hero.jpg)"></div><p>Below the hero.</p></main>';
$result = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array('index.html' => $source)))->toArray();
$markup = (string) ($result['source_reports']['wordpress_site_plan']['pages'][0]['canonical_block_markup'] ?? '');
$css = implode("\n", array_column(array_filter($result['assets'], static fn (array $asset): bool => 'css' === ($asset['kind'] ?? '')), 'content'));
if (!preg_match('/<div\b[^>]*class="([^"]*\bhero\b[^"]*)"[^>]*>(?:\s|<!--.*?-->)*<figure\b[^>]*class="([^"]*)"/', $markup, $owner)
    || !str_contains($owner[1], 'blocks-engine-background-image-host')
    || !str_contains($owner[2], 'blocks-engine-background-image-cover')) {
    throw new RuntimeException('The source host must own its synthetic background paint in the WordPress site plan.');
}
foreach (array(
    ':where(.blocks-engine-background-image-host){position:relative}',
    ':root .blocks-engine-background-image-host>.blocks-engine-background-image{position:absolute;inset:0;margin:0}',
    'min-height:417px',
    'max-height:600px',
    'object-fit:cover',
) as $rule) {
    if (!str_contains($css . $markup, $rule)) {
        throw new RuntimeException('Missing background sizing contract: ' . $rule);
    }
}
if (in_array('--browser', $argv, true)) {
    echo json_encode(array('source' => $source, 'native' => '<style>' . $css . '</style>' . $markup));
} else {
    echo "Min-height background image contract passed.\n";
}
