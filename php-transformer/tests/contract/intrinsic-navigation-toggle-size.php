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
// Responsive builder icons can declare their box through scoped custom
// properties and viewport math rather than a literal pixel dimension.
$math = str_replace('width:30px;height:30px', 'width:var(--size);height:var(--size)', $source);
$math = str_replace('</style>', '.navbar-toggler-icon{--size:16px}@media(max-width:991px){.navbar-toggler-icon{--size:max(0.5px, 0.0769231 * (100vw - 0px))}}</style>', $math);
$plan = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array('index.html' => $math)))->toWordPressSitePlanView()['wordpress_site_plan'];
$css = json_encode($plan, JSON_UNESCAPED_SLASHES);
$assert(str_contains($css, 'blocks-engine-native-navigation-toggle-'), 'The responsive math icon projects through native navigation.');
$assert(!str_contains($css, 'min-height:44px') && !str_contains($css, 'min-width:44px'), 'A responsive CSS-math icon must not inflate the header with a 44px floor.');
$assert(str_contains($css, 'width:max(0.5px, 0.0769231 * (100vw - 0px))!important'), 'Core receives the collapsed icon box, not a token scoped to the removed child.');
echo "ok\n";
