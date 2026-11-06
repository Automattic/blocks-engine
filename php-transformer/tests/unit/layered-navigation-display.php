<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

$source = file_get_contents(dirname(__DIR__) . '/fixtures/layered-navigation-display.html');
$result = ( new HtmlTransformer() )->transform($source, array())->toArray();
$css = implode("\n", array_column($result['assets'], 'content'));
foreach ( array('display:none', 'display:flex', 'display:revert-layer') as $expected ) {
    if ( ! str_contains($css, $expected) ) {
        throw new RuntimeException('Missing unlayered navigation display repair: ' . $expected);
    }
}
if ( str_contains($css, '.wp-block-navigation.md-hidden{') ) {
    throw new RuntimeException('Non-navigation utility was projected');
}
// Tailwind's escaped breakpoint spelling must follow the same path.
$escapedSource = str_replace(array('.md-flex', 'links md-flex'), array('.md\\:flex', 'links md:flex'), $source);
$escaped = ( new HtmlTransformer() )->transform($escapedSource, array())->toArray();
$escapedCss = implode("\n", array_column($escaped['assets'], 'content'));
if ( ! str_contains($escapedCss, '.wp-block-navigation.md\\:flex') || ! str_contains($escapedCss, 'display:revert-layer') ) {
    throw new RuntimeException('Escaped breakpoint utility was not restated');
}
if ( in_array('--json', $argv, true) ) {
    preg_match('/<!--\s*wp:navigation\s+(\{.*?\})\s*-->/s', $result['serialized_blocks'], $match);
    $attrs = json_decode($match[1] ?? '{}', true);
    echo json_encode(array(
        'source' => $source,
        'css' => implode("\n", array_column($result['assets'], 'content')),
        'classes' => $attrs['className'] ?? '',
    ));
} else {
    echo "Layered navigation display contract passed\n";
}
