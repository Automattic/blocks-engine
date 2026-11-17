<?php
declare(strict_types=1);

// A script-faded element loses its opacity:0 when imported. The reveal also
// resets an entrance offset transform, but a slide that places itself on the
// carousel track with its own inline transform keeps that placement. If the
// reset applies, every slide stacks onto the first one.
require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

$assert = static function (bool $condition, string $message): void { if (!$condition) { fwrite(STDERR, "FAIL: {$message}\n"); exit(1); } };
$css = '.track{display:grid}.track>li{grid-area:1/1/2/2;list-style:none}.preFade{opacity:0;transition-property:opacity}.intro.preFade{opacity:0;transform:translateY(40px)}';
$html = '<!DOCTYPE html><html><head><link rel="stylesheet" href="site.css"></head><body><main>'
    . '<ul class="track"><li class="slide preFade"><h2>Unplaced</h2></li></ul>'
    . '<ul class="track"><li class="slide preFade" style="transform: translate3d(0px, 0px, 0px)"><h2>One</h2></li><li class="slide preFade" style="transform: translate3d(261px, 0px, 0px)"><h2>Two</h2></li></ul>'
    . '<p class="intro preFade">Faded in from below.</p><p class="preFade">Faded in place.</p></main></body></html>';
$result = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array(
    array('path' => 'index.html', 'content' => $html),
    array('path' => 'site.css', 'content' => $css),
)))->toArray();
$staticState = implode('', array_map(static fn (array $asset): string => str_contains((string) ($asset['path'] ?? ''), 'editor-static-state') ? (string) ($asset['content'] ?? '') : '', $result['assets']));

$assert(1 === preg_match('/\.slide\.preFade\{opacity:1!important\}/', $staticState), 'placed slides keep their track transform, even beside an unplaced slide sharing their selector');
$assert(1 === preg_match('/\.intro\.preFade\{opacity:1!important;transform:none!important\}/', $staticState), 'an unplaced faded element still drops its entrance offset');
$assert(1 === preg_match('/:root \.preFade\{opacity:1!important\}/', $staticState), 'a fade without an entrance offset resets no transform, so it cannot reach placed slides');
