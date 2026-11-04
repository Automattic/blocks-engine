<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\NavigationInstanceDiagnostics;

$assert = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};
$css = '.menu-toggle{display:none}.c{display:flex}.wrap{width:100%}.menu-wrap{display:flex}.menu-list{display:flex;list-style:none}.menu .menu-link{display:flex;padding:20px;font-size:14px}.menu .current-menu-item .menu-link{color:red}.menu [data-status=active] .menu-link{color:red}';
$page = static fn(bool $current): string => '<html><head><link rel="stylesheet" href="/menu.css"></head><body><section class="a">Logo</section><section class="c"><div class="wrap"><div class="menu-wrap m-141"><nav class="menu"><button class="menu-toggle" type="button" aria-label="Open Menu" aria-expanded="false" aria-controls="menu-141"><span class="menu-toggle-icon"><span class="menu-toggle-lines"></span></span></button><ul id="menu-141" class="menu-list"><li class="' . (!$current ? 'current-menu-item ' : '') . 'menu-item"><a class="menu-link" href="/a"' . (!$current ? ' aria-current="page"' : '') . '>A</a></li><li class="' . ($current ? 'menu-item current-menu-item current_page_item current-menu-ancestor current_page_parent current-menu-parent' : 'menu-item') . '"' . ($current ? ' data-status="active"' : '') . '><a class="menu-link" href="/b"' . ($current ? ' aria-current="page"' : '') . '>B</a></li></ul></nav></div></div></section><main><h1>' . ($current ? 'B' : 'A') . '</h1></main></body></html>';

// Compile together (shared CSS) and independently (no cross-route baseline).
$artifacts = array(
    array('a.html' => $page(false), 'b.html' => $page(true)),
    array('a.html' => $page(false)),
    array('b.html' => $page(true)),
);
$hosts = array();
foreach ($artifacts as $files) {
    $plan = (new ArtifactCompiler())->compile(array(
        'entrypoint' => array_key_first($files),
        'files' => $files + array('menu.css' => $css),
    ))->toArray()['source_reports']['wordpress_site_plan'];
    $markup = implode('', array_column(array_merge($plan['pages'], $plan['templates'], $plan['template_parts']), 'canonical_block_markup'));
    $projected = implode("\n", array_column($plan['assets'], 'content'));
    preg_match_all('/blocks-engine-navigation-instance-[a-f0-9]{12}-[0-9]+/', $markup, $instances);
    $assert(1 === count(array_unique($instances[0])), 'Both routes carry one resting navigation instance.');
    $instance = $instances[0][0];
    $assert(1 === preg_match('/[^{}]*' . preg_quote($instance, '/') . '[^{}]*wp-block-navigation-item__content[^{}]*\{[^{}]*display:flex[^{}]*padding:20px/', $projected), 'Author link box targets the emitted instance.');
    $assert(array() === NavigationInstanceDiagnostics::collect($plan['assets'], $markup), 'No projection targets an absent instance.');
    $hosts[] = $instance;
    $assert(1 === preg_match('/[^{}]*' . preg_quote($instance, '/') . '[^{}]*(?:current-menu-item|aria-current)[^{}]*\{[^{}]*color:(?:red|var\([^{}]*,red\))/', $projected), 'Current item still receives red through the runtime current-item selector.');
}
$assert(1 === count(array_unique($hosts)), 'Both routes use the same instance marker, together and independently.');
echo "Navigation instance resting source passed.\n";
