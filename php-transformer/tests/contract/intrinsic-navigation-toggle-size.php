<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

$assert = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};
$source = (string) file_get_contents(dirname(__DIR__) . '/fixtures/intrinsic-navbar-toggler/index.html');
$plan = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array('index.html' => $source)))->toWordPressSitePlanView()['wordpress_site_plan'];
$css = json_encode($plan, JSON_UNESCAPED_SLASHES);
$assert(str_contains($css, 'blocks-engine-native-navigation-toggle-'), 'The projected navigation keeps its native toggle marker.');
$assert(!str_contains($css, 'min-height:44px'), 'A toggle sized by padding, border and an icon child must keep its intrinsic height, not gain a 44px floor.');
$assert(!str_contains($css, 'min-width:44px'), 'A toggle sized by padding, border and an icon child must keep its intrinsic width, not gain a 44px floor.');

// An empty control has no intrinsic box and still gets the touch-target floor.
$empty = str_replace('<span class="navbar-toggler-icon"></span>', '', $source);
$plan = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array('index.html' => $empty)))->toWordPressSitePlanView()['wordpress_site_plan'];
$css = json_encode($plan, JSON_UNESCAPED_SLASHES);
$assert(str_contains($css, 'min-height:44px') && str_contains($css, 'min-width:44px'), 'An empty toggle keeps the 44px floor.');
// Real-site shape: font-relative icon box (1.5em at font-size:2rem = 48px).
$em = (string) file_get_contents(dirname(__DIR__) . '/fixtures/intrinsic-navbar-toggler-em/index.html');
$plan = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array('index.html' => $em)))->toWordPressSitePlanView()['wordpress_site_plan'];
$css = json_encode($plan, JSON_UNESCAPED_SLASHES);
$assert(str_contains($css, 'blocks-engine-native-navigation-toggle-'), 'The em-sized toggle keeps its native toggle marker.');
$assert(!str_contains($css, 'min-height:44px') && !str_contains($css, 'min-width:44px'), 'An em-sized icon child is an intrinsic box; no 44px floor.');
$assert(str_contains($css, 'padding'), 'The opener carries the source padding.');
echo "ok\n";
