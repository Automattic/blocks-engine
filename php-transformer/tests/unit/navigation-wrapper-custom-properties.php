<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

$html = file_get_contents(dirname(__DIR__) . '/fixtures/navigation-wrapper-custom-properties.html');
$compile = static function (string $html): array {
    $result = (new ArtifactCompiler())->compile(array(
        'entrypoint' => 'index.html',
        'files' => array('index.html' => $html),
    ))->toArray();
    preg_match_all('/<!-- wp:(navigation(?:-link)?) (\{.*?\}) (?:\/)?-->/', $result['serialized_blocks'], $matches, PREG_SET_ORDER);
    $blocks = array();
    foreach ($matches as $match) $blocks[$match[1]][] = json_decode($match[2], true, 512, JSON_THROW_ON_ERROR);
    return $blocks;
};
$shared = $compile($html);
$navigation = $shared['navigation'][0] ?? array();
if ('14px' !== ($navigation['style']['typography']['fontSize'] ?? '')
    || '700' !== ($navigation['style']['typography']['fontWeight'] ?? '')) {
    throw new RuntimeException('Shared wrapper typography must reach the native navigation supports.');
}
foreach ($shared['navigation-link'] ?? array() as $link) {
    if ('Georgia,serif' !== ($link['style']['typography']['fontFamily'] ?? '')
        || !str_contains($link['metadata']['blocksEngineNavigationAnchor']['style'] ?? '', '--t-font:Georgia,serif')) {
        throw new RuntimeException('The rebuilt anchor must retain the wrapper font and its var() inputs.');
    }
}
if (2 !== count($shared['navigation-link'] ?? array())) throw new RuntimeException('Both source links must remain editable navigation items.');

// Different wrappers must not turn the first item's typography into a menu-wide default.
$mixed = $compile(str_replace('--t-size:14px;--t-weight:700"><a class="t" href="#two"', '--t-size:18px;--t-weight:500"><a class="t" href="#two"', $html));
if (isset($mixed['navigation'][0]['style']['typography']['fontSize'])
    || '18px' !== ($mixed['navigation-link'][1]['style']['typography']['fontSize'] ?? '')
    || '500' !== ($mixed['navigation-link'][1]['style']['typography']['fontWeight'] ?? '')) {
    throw new RuntimeException('Different wrapper typography must remain item-scoped.');
}

// A link's own inline token wins over the wrapper contribution.
$override = $compile(str_replace('<a class="t" href="#two"', '<a class="t" style="--t-size:20px" href="#two"', $html));
if ('20px' !== ($override['navigation-link'][1]['style']['typography']['fontSize'] ?? '')) {
    throw new RuntimeException('The wrapper must not override the link scope.');
}
echo "Navigation wrapper custom properties passed\n";
