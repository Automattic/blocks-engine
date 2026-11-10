<?php
declare(strict_types=1);
require getenv('BLOCKS_ENGINE_AUTOLOAD') ?: dirname(__DIR__, 2) . '/vendor/autoload.php';
use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

/*
 * An inline wrapper around flow content generates no box in the source, so a
 * child's `height:100%` resolves against the block beyond it. The group that
 * replaces the wrapper must stay out of that resolution; a wrapper the source
 * gives its own box (positioned, painted, blockified) keeps a real group.
 */
$track = '<div class="track" style="overflow:hidden;height:100%"><div class="slide"><p>Slide</p></div></div>';
$cases = array(
    'plain inline wrapper' => array('<span id="wrap">' . $track . '</span>', true),
    'positioned wrapper' => array('<span id="wrap" style="position:relative">' . $track . '</span>', false),
    'painted wrapper' => array('<span id="wrap" style="padding:4px;background:#eee">' . $track . '</span>', false),
    'author-blockified wrapper' => array('<span id="wrap" class="as-block">' . $track . '</span>', false),
    'flex item wrapper' => array('<div style="display:flex;height:100%"><span id="wrap">' . $track . '</span></div>', false),
);
foreach ($cases as $name => [$body, $transparent]) {
    $result = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array(
        array('path' => 'index.html', 'kind' => 'html', 'content' => '<!doctype html><html><head><link rel="stylesheet" href="s.css"></head><body><div class="host">' . $body . '</div></body></html>'),
        array('path' => 's.css', 'kind' => 'css', 'content' => '.host{width:300px;height:200px}.slide{position:absolute;inset:0;background:#2460a0}.as-block{display:block}'),
    )))->toArray();
    if (!preg_match('/<div id="wrap" class="([^"]*)"/', (string) $result['serialized_blocks'], $wrapper)) throw new RuntimeException("$name: the wrapper lowers to an addressable group.");
    $css = implode("\n", array_column($result['assets'], 'content'));
    $isTransparent = str_contains($wrapper[1], 'blocks-engine-inline-flow-wrapper') && str_contains($css, ':where(.blocks-engine-inline-flow-wrapper){display:contents}');
    if ($transparent !== $isTransparent) throw new RuntimeException("$name: " . ($transparent ? 'generates no box around its flow content' : 'keeps the box its source gives it') . " (classes: {$wrapper[1]}).");
}
echo "Inline flow wrapper box: plain wrappers transparent, boxed wrappers kept\n";
