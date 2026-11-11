<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\WordPressSitePlan;

// Device stylesheets are enqueued inert and activated by the capture's
// selection script through their link markers. Hosts that concatenate
// stylesheets print one combined link without those markers, so every handle
// whose tag carries attributes must stay out of concatenation.
$filters = array();
function add_filter(string $hook, mixed $callback, int $priority = 10, int $args = 1): void { $GLOBALS['filters'][$hook][] = $callback; }
function add_action(...$args): void {}
function remove_action(...$args): void {}

$links = '';
foreach (array('desktop', 'mobile') as $device) {
    $links .= '<link rel="stylesheet" href="' . $device . '.css" media="not all" data-blocks-engine-capture-device-style="' . $device . '" data-blocks-engine-capture-source-media="all">';
}
$html = '<!doctype html><html lang="en"><head><meta charset="utf-8"><title>Device stylesheets</title><link rel="stylesheet" href="shared.css">' . $links
    . '<script data-blocks-engine-capture-device-selection="">(function(){var key=\'desktop\';document.documentElement.setAttribute(\'data-blocks-engine-capture-selected-document\',key);var s=document.querySelectorAll(\'[data-blocks-engine-capture-device-style]\');for(var j=0;j<s.length;j++){var n=s[j];if(n.getAttribute(\'data-blocks-engine-capture-device-style\').split(/\\s+/).indexOf(key)===-1)continue;var c=n.cloneNode(true);c.setAttribute(\'media\',n.getAttribute(\'data-blocks-engine-capture-source-media\'));c.removeAttribute(\'data-blocks-engine-capture-device-style\');n.after(c);}})();</script>'
    . '</head><body><main><h1>Title</h1><p class="lede">Neutral content</p></main></body></html>';
$plan = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array(
    'index.html' => $html,
    'shared.css' => 'h1{font-weight:700}',
    'desktop.css' => '.lede{font-size:20px}',
    'mobile.css' => '.lede{font-size:14px}',
)))->toArray()['source_reports']['wordpress_site_plan'];
WordPressSitePlan::assertValid($plan);

$bootstrap = array_column($plan['writes'], null, 'target_path')['functions.php']['payload']['data'];
eval(substr($bootstrap, 5));

$fail = static function (string $message): never { fwrite(STDERR, "FAIL: $message\n"); exit(1); };
$attributes = $blocks_engine_stylesheet_attributes ?? array();
$deviceHandles = array_keys(array_filter($attributes, static fn(int $index): bool => isset($blocks_engine_stylesheet_attribute_bodies[$index]['data-blocks-engine-capture-device-style'])));
if (2 !== count($deviceHandles)) $fail('both device stylesheets carry activation markers: ' . json_encode($attributes));
if (array() === ($filters['css_do_concat'] ?? array())) $fail('the theme registers no css_do_concat filter, so a concatenating host bundles the device stylesheets without their markers');
$concat = static function (string $handle) use ($filters): bool {
    $result = true;
    foreach ($filters['css_do_concat'] as $callback) $result = $callback($result, $handle);
    return $result;
};
foreach ($deviceHandles as $handle) if (false !== $concat($handle)) $fail("device stylesheet $handle stays out of concatenation");
if (true !== $concat('blocks-engine-unmarked-handle')) $fail('stylesheets without tag attributes keep concatenating');

echo "device-stylesheet-concatenation contract passed\n";
