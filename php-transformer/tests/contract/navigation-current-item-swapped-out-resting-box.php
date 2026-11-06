<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

$assert = static function (bool $condition, string $message): void { if (! $condition) throw new RuntimeException($message); };

// A site builder swaps classes on the served route's link: resting links wear
// `rest` (a padded, negatively margined hit area), the current link trades it
// for `on` (an underline with its own bottom padding and margin). The shared
// menu renders every link with one resting subject, so the resting box must
// skip WordPress's runtime current item, exactly as the source current link
// never wore it. Otherwise the current link keeps the resting side padding and
// margins and its underline is wider than the source's.
$routes = array('index.html' => array('/', 'Home'), 'a/index.html' => array('/a/', 'Route A'), 'b/index.html' => array('/b/', 'Route B'));
$artifact = array('entrypoint' => 'index.html', 'files' => array());
foreach ($routes as $path => [, $title]) {
    $links = '';
    foreach ($routes as [$href, $label]) {
        $links .= '<li class="item"><a class="link ' . ($label === $title ? 'on' : 'rest') . '" href="' . $href . '">' . $label . '</a></li>';
    }
    $artifact['files'][] = array('path' => $path, 'content' => '<!doctype html><html><head><meta charset="utf-8"><title>' . $title . '</title><style>'
        . '.bar{display:flex;gap:24px}.item{list-style:none}.link{display:block;color:rgb(20, 20, 20);text-decoration:none}'
        . '.rest{padding:6px;margin:-6px}.on{border-bottom:1px solid;padding-bottom:4px;margin-bottom:-5px}'
        . '</style></head><body><header class="site-header"><h3 class="logo">Acme</h3><nav><ul class="bar">' . $links . '</ul></nav></header>'
        . '<main><h1>' . $title . '</h1><p>' . $title . ' body text.</p></main><footer><p>Copyright Acme.</p></footer></body></html>');
}

$roundTrip = static fn (array $value): array => json_decode(json_encode($value, JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);
$compiler = new ArtifactCompiler();
$shared = $roundTrip($compiler->prepareShared($artifact));
$prepared = $roundTrip($compiler->preparePages($artifact, $shared));
$result = $compiler->compose($shared, $roundTrip($compiler->compilePreparedPages($shared, $prepared)))->toArray();

$rules = array();
foreach ($result['assets'] as $asset) {
    if ('css' !== ($asset['kind'] ?? null)) continue;
    preg_match_all('/([^{}]+)\{([^{}]*)\}/', (string) ($asset['content'] ?? ''), $matches, PREG_SET_ORDER);
    foreach ($matches as [, $selectors, $declarations]) {
        if (! str_contains($selectors, 'blocks-engine-navigation-instance-')) continue;
        $rules[] = array(trim($selectors), $declarations);
    }
}
$resting = array_values(array_filter($rules, static fn (array $rule): bool => 1 === preg_match('/(?:^|;)\s*(?:padding:\s*6px|margin:\s*-6px)\s*(?:;|$)/', $rule[1])));
$assert(array() !== $resting, 'The resting link box is projected onto the shared navigation');
foreach ($resting as [$selectors, $declarations]) {
    $assert(str_contains($selectors, ':not([aria-current="page"]'), 'The resting link box skips the runtime current link: ' . $selectors . '{' . $declarations . '}');
}
$current = array_values(array_filter($rules, static fn (array $rule): bool => str_contains($rule[0], '[aria-current="page"]') && ! str_contains($rule[0], ':not([aria-current="page"]') && str_contains($rule[1], 'padding-bottom:4px')));
$assert(array() !== $current, 'The current link keeps its own underline box');

echo "navigation current item swapped-out resting box: ok\n";
