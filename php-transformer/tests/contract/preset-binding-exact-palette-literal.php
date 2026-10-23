<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

$assert = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};

// Shared typography classes do not imply shared paint: these paragraphs
// deliberately render different inline colours, just as in captured pages.
$compile = static function (string $extraCss = ''): array {
    return (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array(
        'index.html' => '<!doctype html><html><head><link rel="stylesheet" href="site.css"></head><body><main><p class="body" style="color:rgb(255, 255, 255)">Light copy</p><p class="body" style="color:rgb(26, 10, 13)">Dark copy</p><h2 style="color:rgb(26, 10, 13)">Heading</h2><a class="action" href="https://example.test/" style="background-color:rgb(0, 0, 0);color:rgb(255, 255, 255)">Continue</a></main></body></html>',
        'site.css' => '.body{font-size:18px;line-height:1.5}.action{display:inline-block;padding:12px 24px;border-radius:8px;background:#000;color:#fff}' . $extraCss,
    )))->toArray()['source_reports']['wordpress_site_plan'];
};
$plan = $compile();
$markup = $plan['pages'][0]['canonical_block_markup'];
$palette = array();
// Read the palette from the declared theme write, not inferred slug names.
foreach ($plan['writes'] as $write) {
    if ('theme.json' !== $write['target_path']) continue;
    $theme = json_decode($write['payload']['data'], true, 512, JSON_THROW_ON_ERROR);
    $palette = array_column($theme['settings']['color']['palette'], 'slug', 'color');
}
$white = $palette['#ffffff'] ?? null;
$assert(is_string($white), 'The source produces an exact white palette token.');
$assert(1 === preg_match('/<!-- wp:paragraph (\{.*?\}) -->(<p[^>]*>)Light copy<\/p>/', $markup, $light), 'The light paragraph remains native.');
$attributes = json_decode($light[1], true, 512, JSON_THROW_ON_ERROR);
$assert($white === ($attributes['textColor'] ?? null), 'An exact palette literal binds despite differing resting colours on the same authored classes.');
$assert(!isset($attributes['style']['color']['text']) && !str_contains($light[2], 'color:') && str_contains($light[2], 'has-text-color') && str_contains($light[2], 'has-' . $white . '-color'), 'Core preset attributes and classes replace the inline text declaration.');

foreach (array(':hover' => 'state_variant', '' => 'important') as $selector => $reason) {
    $unsafe = $compile('.body' . $selector . '{color:#fff' . ('' === $selector ? '!important' : '') . '}');
    $unsafeMarkup = $unsafe['pages'][0]['canonical_block_markup'];
    $assert(str_contains($unsafeMarkup, 'style="color:rgb(255, 255, 255)"'), 'Reachable ' . $reason . ' rules keep the literal.');
    $assert($unsafe['theme']['design_system']['metrics']['refusal_reasons'][$reason] > 0, 'The safety refusal remains reported.');
}
$ordinaryMedia = $compile('@media(max-width:600px){.body{color:#000}}');
$assert(!str_contains($ordinaryMedia['pages'][0]['canonical_block_markup'], 'style="color:rgb(255, 255, 255)"'), 'An ordinary breakpoint cannot override a direct inline colour.');
$unsafe = $compile('@media(max-width:600px){.body{color:#000!important}}');
$assert(str_contains($unsafe['pages'][0]['canonical_block_markup'], 'style="color:rgb(255, 255, 255)"'), 'A breakpoint that can override the inline colour still prevents binding.');

echo "Exact palette literal binding contract passed.\n";
