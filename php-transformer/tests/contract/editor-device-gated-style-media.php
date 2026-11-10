<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\Tests\Support\BootstrapStyles;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\WordPressSitePlan;

// A device-gated author sheet stays `not all` until the capture's selection
// script activates it. The editor canvas runs no such script, so its replay
// must load the default (desktop) device's sheets at their source media and
// leave other devices' sheets inert.
$styles = '';
foreach (array('desktop' => '#5f5bcd', 'mobile' => '#123456') as $device => $color) {
    $styles .= '<style data-blocks-engine-capture-device-style="' . $device . '" data-blocks-engine-capture-source-media="all" media="not all">.cta a{display:inline-block;padding:10px 24px;border-radius:50px;background:' . $color . ';color:#fff}</style>';
}
$html = '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport"><title>Editor device CSS repro</title>' . $styles
    . '<script data-blocks-engine-capture-device-selection="">(function(){var key=\'desktop\';document.documentElement.setAttribute(\'data-blocks-engine-capture-selected-document\',key);var s=document.querySelectorAll(\'[data-blocks-engine-capture-device-style]\');for(var j=0;j<s.length;j++){var n=s[j];if(n.getAttribute(\'data-blocks-engine-capture-device-style\').split(/\\s+/).indexOf(key)===-1)continue;var c=n.cloneNode(true);c.setAttribute(\'media\',n.getAttribute(\'data-blocks-engine-capture-source-media\'));c.removeAttribute(\'data-blocks-engine-capture-device-style\');n.after(c);}})();</script>'
    . '</head><body><p class="cta"><a href="/catalog">Catalog</a></p></body></html>';
$plan = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array('index.html' => $html)))->toArray()['source_reports']['wordpress_site_plan'];
WordPressSitePlan::assertValid($plan);

$bootstrap = array_column($plan['writes'], null, 'target_path')['functions.php']['payload']['data'];
$files = BootstrapStyles::registry($bootstrap, 'blocks_engine_presentation_styles');
$media = array();
foreach (BootstrapStyles::registry($bootstrap, 'blocks_engine_presentation_replay') as $entry) {
    if (!str_starts_with($files[$entry[0]]['target_path'], 'assets/inline/')) continue;
    $content = (string) array_column($plan['assets'], 'content', 'target_path')[$files[$entry[0]]['target_path']];
    foreach (array('desktop' => '#5f5bcd', 'mobile' => '#123456') as $device => $color) if (str_contains($content, $color)) $media[$device] = $entry[3];
}
$expected = array('desktop' => 'all', 'mobile' => 'not all');
ksort($media);
if ($expected !== $media) throw new RuntimeException('Editor canvas loads the default device sheet at its source media: ' . json_encode($media));

echo "editor-device-gated-style-media contract passed\n";
