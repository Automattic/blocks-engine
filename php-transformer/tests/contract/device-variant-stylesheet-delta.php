<?php
declare(strict_types=1);
require_once __DIR__ . '/../../vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\WordPressSitePlan;

// A canonical document with the base and captured phone document's head styles.
$base = '.shared{--shared:1}.changed{--size:20px}';
$phone = '.shared{--shared:1}.changed{--size:10px}';
$plan = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array(
    'index.html' => '<html><head><title>Delta</title><link rel="stylesheet" href="shared.css"><style>' . $base . '</style><link rel="stylesheet" href="shared.css" media="(max-width:1199px)"><style media="(max-width:1199px)">' . $phone . '</style></head><body><main><h1 class="shared changed">Delta</h1></main></body></html>',
    'shared.css' => '.shared{--external:1}',
)))->toWordPressSitePlanView()['wordpress_site_plan'];
$assets = array_column($plan['assets'], null, 'target_path');
$sequence = WordPressSitePlan::documentStylesheets($plan, 'index.html');
$urls = array(); $css = ''; $delta = ''; $bytes = 0;
foreach ($sequence as $sheet) {
    $path = $sheet['target_path'];
    $urls[$path] = ($urls[$path] ?? 0) + 1;
    $content = $assets[$path]['content'] ?? '';
    $bytes += strlen($content);
    $css .= $content;
    if ('(max-width:1199px)' === $sheet['media']) $delta .= $content;
}
echo 'Emitted route CSS: ' . $bytes . " bytes\n";
$assert = static function (bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); };
$assert(max($urls) === 1, 'Each stylesheet URL must be enqueued at most once per route.');
$assert(trim($delta) === '.changed{--size:10px}', 'The phone sheet must contain only the differing rule: ' . $delta);
$assert(substr_count($css, '.shared{--shared:1}') === 1, 'Base rules must appear once.');
$assert(strpos($css, '--size:20px') < strpos($css, '--size:10px'), 'The phone override must follow the base.');
// A shared rule after a variant-only rule must still beat that rule.
// This is the A,B,A case that forbids simply dropping repeated URLs.
$cascade = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array(
    'index.html' => '<html><head><title>Cascade</title><link rel="stylesheet" href="base.css"><link rel="stylesheet" href="variant.css" media="(max-width:1199px)"><link rel="stylesheet" href="base.css" media="(max-width:1199px)"></head><body><main><h1 class="shared changed">Cascade</h1></main></body></html>',
    'base.css' => '.shared{--size:20px}',
    'variant.css' => '.changed{--size:10px}',
)))->toWordPressSitePlanView()['wordpress_site_plan'];
$cascadeAssets = array_column($cascade['assets'], null, 'target_path');
$cascadeUrls = array(); $cascadeDelta = '';
foreach (WordPressSitePlan::documentStylesheets($cascade, 'index.html') as $sheet) {
    $cascadeUrls[] = $sheet['target_path'];
    if ('(max-width:1199px)' === $sheet['media']) $cascadeDelta .= $cascadeAssets[$sheet['target_path']]['content'];
}
$assert(count($cascadeUrls) > count(array_unique($cascadeUrls)), 'An unsafe identical-asset replay retains its source URL and instance.');
$assert(strpos($cascadeDelta, '--size:10px') < strpos($cascadeDelta, '--size:20px') && str_contains($cascadeDelta, '--size:20px'), 'The later shared rule must retain its winning position in the delta.');
// Intervening unconditional and conditioned sheets both matter. Selectors
// with different text can match the same element; shorthand families overlap.
foreach (array(
    array('', '', true),
    array('.changed{--other:1}', '', true),
    array('.changed{--size:10px}', '', false),
    array('.changed{--size:10px}', ' media="(min-width:500px)"', false),
    array('.changed{all:initial}', '', false),
) as [$intervening, $condition, $redundant]) {
    $head = '<link rel="stylesheet" href="base.css">';
    if ('' !== $intervening) $head .= '<link rel="stylesheet" href="between.css"' . $condition . '>';
    $head .= '<link rel="stylesheet" href="base.css" media="(max-width:1199px)">';
    $fixture = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array(
        'index.html' => '<html><head><title>Replay</title>' . $head . '</head><body><main><h1 class="shared changed">Replay</h1></main></body></html>',
        'base.css' => '.shared{--size:20px}',
        'between.css' => $intervening,
    )))->toWordPressSitePlanView()['wordpress_site_plan'];
    $sheets = WordPressSitePlan::documentStylesheets($fixture, 'index.html');
    $paths = array_column($sheets, 'target_path');
    $hasReplay = count($paths) > count(array_unique($paths));
    $assert($hasReplay !== $redundant, 'Only provably redundant asset replays are removed: ' . $intervening . $condition);
}
// A rule equal to the base is not redundant when an intervening base
// stylesheet changed that property. The device copy must restore it.
$restore = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array(
    'index.html' => '<html><head><title>Restore</title><style>.shared{--size:20px}</style><style>.changed{--size:30px}</style><style media="(max-width:1199px)">.shared{--size:20px}.changed{--phone:1}</style></head><body><main><h1 class="shared changed">Restore</h1></main></body></html>',
)))->toWordPressSitePlanView()['wordpress_site_plan'];
$restoreAssets = array_column($restore['assets'], null, 'target_path');
$restoreCss = '';
foreach (WordPressSitePlan::documentStylesheets($restore, 'index.html') as $sheet) {
    if ('(max-width:1199px)' === $sheet['media']) $restoreCss .= $restoreAssets[$sheet['target_path']]['content'];
}
$assert(str_contains($restoreCss, '--size:20px') && str_contains($restoreCss, '--phone:1'), 'Inline variants restore base rules that may override intervening sheets.');
$transformer = new Automattic\BlocksEngine\PhpTransformer\Css\CssStylesheetTransformer();
$assert(!$transformer->canOmitReplay('.a{margin:0}', '.b{margin-left:1px}'), 'Shorthands and longhands can compete.');
$assert(!$transformer->canOmitReplay('.a{color:red}', '@import "other.css";'), 'Imports make replay safety unknown.');
echo "device-variant-stylesheet-delta: ok\n";
