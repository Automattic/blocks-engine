<?php
declare(strict_types=1);

/**
 * A sidebar toggle makes the importer probe its landmark before conversion.
 * The probe has no recursive converter, so it cannot hoist the brand link and
 * reads it as menu item one. Item subjects must come from the menu the
 * conversion emits: every item, Home included, keeps its source box paint.
 */
require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

$item = static fn(string $label, string $href): string => '<li><div class="item-box"><div><a href="' . $href . '">' . $label . '</a></div></div></li>';
$html = '<html><head><link rel="stylesheet" href="site.css"></head><body><header><div>'
    . '<div role="button" aria-controls="side"></div>'
    . '<nav><a href="/"><span>Brand</span></a><ul>' . $item('Home', '/') . $item('About', '/about/') . $item('Kings', '/kings/') . '</ul></nav>'
    . '</div></header><main><p>Body</p></main></body></html>';
$result = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array(
    array('path' => 'index.html', 'content' => $html),
    array('path' => 'site.css', 'content' => '.item-box{font-family:Lora;padding-left:36px}'),
)))->toArray();
$output = stripslashes((string) json_encode($result, JSON_UNESCAPED_SLASHES));

if (1 !== preg_match('/\.wp-block-navigation-item>div:not\([^)]*\)\{font-family:Lora;padding-left:36px\}/', $output)) {
    throw new RuntimeException('every menu item box keeps the source box paint');
}
if (0 !== preg_match('/nth-child\(\d+\)>div[^{}]*\{[^}]*padding-left:36px/', $output)) {
    throw new RuntimeException('the box paint is not shifted onto brand-offset item positions');
}
echo "Navigation probe subject publication passed: 2 assertions\n";
