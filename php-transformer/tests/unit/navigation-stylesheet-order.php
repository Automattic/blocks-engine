<?php
declare(strict_types=1);

require getenv('BLOCKS_ENGINE_AUTOLOAD') ?: dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\WordPressSitePlan;

$base = 'body{margin:0;font:14px/22px Arial}nav{display:block}.masthead{height:60px}.navbar{min-height:40px}.landmark{text-align:right}.navbar .nav{display:inline-block;margin:0;padding:0;list-style:none}.navbar .nav>li{float:left;line-height:20px}.navbar .nav>li>a{display:block;padding:10px 15px 11px;font-size:88%}@media(min-width:1200px){.navbar .nav>li{line-height:24px}.navbar .nav>li>a{font-size:100%}}@media(max-width:700px){.navbar{min-height:0}.landmark{display:none}}.following{height:30px}';
// An authored zero-width inline run exposes the enclosing baseline without
// depending on whitespace that native block serialization does not retain.
$theme = '.navbar .nav>li{display:inline-block;float:none}.landmark .nav a{font-size:14px;color:#246}.landmark::after{content:"\\200b"}.band-tail{display:none}';
$bands = '<header class="masthead"></header>';
foreach (array('Primary', 'Secondary') as $label) {
    $bands .= '<div class="navbar"><nav class="landmark" aria-label="' . $label . '"><ul class="nav"><li><a href="/alpha">Alpha</a></li><li><a href="/beta">Beta</a></li></ul></nav><hr class="band-tail"></div>';
}
$bands .= '<section class="following">Following content</section>';
$head = '<link rel="stylesheet" href="z-base.css"><link rel="stylesheet" href="a-theme.css">';
$inlineChain = '';
for ($index = 1; $index <= 12; ++$index) $inlineChain .= '<style>.navbar .nav>li>a{color:rgb(' . $index . ',0,0)!important}</style>';
$documents = array(
    'index.html' => '<html><head>' . $head . '</head><body>' . $bands . '</body></html>',
    'reverse.html' => '<html><head><link rel="stylesheet" href="a-theme.css"><link rel="stylesheet" href="z-base.css"></head><body>' . $bands . '</body></html>',
    'conditional.html' => '<html><head>' . $head . '<style media="(min-width:1200px)">.navbar .nav>li>a{font-size:16px}</style><link rel="stylesheet" href="a-theme.css" media="(max-width:700px)"></head><body>' . $bands . '</body></html>',
    'imports.html' => '<html><head>' . $head . '<link rel="stylesheet" href="imports.css"></head><body>' . $bands . '</body></html>',
    'repeated.html' => '<html><head>' . $head . '<link rel="stylesheet" href="repeat-a.css"><link rel="stylesheet" href="repeat-b.css"><link rel="stylesheet" href="repeat-a.css"></head><body>' . $bands . '</body></html>',
    'named-repeated.html' => '<html><head>' . $head . '<link rel="stylesheet" href="named-a.css"><link rel="stylesheet" href="named-b.css"><link rel="stylesheet" href="named-a.css"></head><body>' . $bands . '</body></html>',
    'instance-media.html' => '<html><head>' . $head . '<link rel="stylesheet" href="instance-a.css" media="(max-width:700px)"><style>.nav>li>a{color:green!important}</style><link rel="stylesheet" href="instance-b.css"><link rel="stylesheet" href="instance-a.css" media="(min-width:1200px)"></head><body>' . $bands . '</body></html>',
    'inline-chain.html' => '<html><head>' . $head . $inlineChain . '</head><body>' . $bands . '</body></html>',
    'anonymous.html' => '<html><head>' . $head . '<link rel="stylesheet" href="anonymous.css"></head><body>' . $bands . '</body></html>',
    'anonymous-nested.html' => '<html><head>' . $head . '<link rel="stylesheet" href="anonymous-parent.css"></head><body>' . $bands . '</body></html>',
);
$imports = array(
    'imports.css' => '@layer theme, foundation;@import url("imported.css") layer(foundation) supports(display:block) (min-width:1200px);@layer theme{.navbar ul.nav>li>a{color:green!important}}',
    'imported.css' => '.navbar ul.nav>li>a{color:red!important}',
);
$additionalStylesheets = array(
    'repeat-a.css' => array('route' => 'repeated.html', 'content' => '.navbar .nav>li>a{color:red}'),
    'repeat-b.css' => array('route' => 'repeated.html', 'content' => '.navbar .nav>li>a{color:blue}'),
    'named-a.css' => array('route' => 'named-repeated.html', 'content' => '@layer named{.nav>li>a{color:red!important}}'),
    'named-b.css' => array('route' => 'named-repeated.html', 'content' => '@layer named{.nav>li>a{color:blue!important}}'),
    'instance-a.css' => array('route' => 'instance-media.html', 'content' => '.nav>li>a{color:red!important}'),
    'instance-b.css' => array('route' => 'instance-media.html', 'content' => '.nav>li>a{color:blue!important}'),
    'anonymous.css' => array('route' => 'anonymous.html', 'content' => '@import url("anonymous-import.css") layer;@layer named{.nav>li>a{color:red!important}}'),
    'anonymous-import.css' => array('route' => 'anonymous.html', 'content' => '.nav>li>a{color:blue!important}'),
    'anonymous-parent.css' => array('route' => 'anonymous-nested.html', 'content' => '@import url("anonymous-child.css");@layer later{.nav>li>a{color:red!important}}'),
    'anonymous-child.css' => array('route' => 'anonymous-nested.html', 'content' => '@import url("anonymous-leaf.css") layer;'),
    'anonymous-leaf.css' => array('route' => 'anonymous-nested.html', 'content' => '.nav>li>a{color:blue!important}'),
);
$cases = array();
foreach (array(array('index.html' => $documents['index.html']), $documents) as $pages) {
    foreach (array(array('a-theme.css' => $theme, 'z-base.css' => $base), array('z-base.css' => $base, 'a-theme.css' => $theme)) as $stylesheets) {
        $importFiles = array();
        if (isset($pages['imports.html'])) {
            foreach ($imports as $path => $content) $importFiles[$path] = array('content' => $content, 'metadata' => array('compilation' => array('scope' => 'page', 'id' => 'imports.html')));
        }
        foreach (array_key_first($stylesheets) === 'a-theme.css' ? $additionalStylesheets : array_reverse($additionalStylesheets, true) as $path => $stylesheet) {
            if (isset($pages[$stylesheet['route']])) $importFiles[$path] = array('content' => $stylesheet['content'], 'metadata' => array('compilation' => array('scope' => 'page', 'id' => $stylesheet['route'])));
        }
        $result = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => $pages + $stylesheets + $importFiles))->toArray();
        $publication = $result;
        if (array_key_first($stylesheets) === 'z-base.css') $publication['source_reports']['compiled_site']['assets'] = array_reverse($publication['source_reports']['compiled_site']['assets']);
        $cases[] = array('order' => array_keys($stylesheets), 'site' => $result['source_reports']['compiled_site'], 'plan' => (new WordPressSitePlan())->fromCompilerResult($publication));
    }
}
if (in_array('--fixture', $argv, true)) {
    echo json_encode(array('documents' => $documents, 'stylesheets' => array('z-base.css' => $base, 'a-theme.css' => $theme) + $imports + array_map(static fn(array $stylesheet): string => $stylesheet['content'], $additionalStylesheets), 'cases' => $cases), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    exit;
}
$compat = static fn(array $site): array => array_values(array_map(static fn(array $asset): array => array('content' => $asset['content'], 'compilation' => $asset['compilation'] ?? null), array_filter($site['assets'], static fn(array $asset): bool => 'wordpress-compat' === ($asset['source'] ?? ''))));
foreach (array(0, 2) as $index) {
    if ($compat($cases[$index]['site']) !== $compat($cases[$index + 1]['site'])) {
        throw new RuntimeException('Compatibility replay must be invariant under stylesheet file-map insertion order.');
    }
}
$assetsByPath = array_column($cases[2]['site']['assets'], null, 'path');
$imported = $assetsByPath['imported.css']['content'];
if (!str_contains($assetsByPath['imports.css']['content'], 'layer(foundation) supports(display:block) (min-width:1200px)') || !str_contains($imported, 'wp-block-navigation-item__content') || substr_count($imported, 'color:red!important') !== 1) {
    throw new RuntimeException('Imported native selectors must share the original conditional layer and one declaration body.');
}
foreach ($compat($cases[2]['site']) as $asset) {
    if (str_contains($asset['content'], '@layer')) throw new RuntimeException('Compatibility replay must not create new authored layers.');
}
if (count(array_filter($cases[2]['site']['assets'], static fn(array $asset): bool => str_starts_with($asset['path'], 'repeat-a'))) !== 1) {
    throw new RuntimeException('Repeated cascade positions must share one resource payload, not produce URI aliases.');
}
if (substr_count($assetsByPath['anonymous-import.css']['content'], 'color:blue!important') !== 1 || !str_contains($assetsByPath['anonymous-import.css']['content'], 'wp-block-navigation-item__content')) {
    throw new RuntimeException('Anonymous imported declarations must remain a single original/native rule.');
}
echo "Navigation stylesheet order: transport permutations retain canonical replay\n";
