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

$unsafe = $compile('.body:hover{color:#fff}');
$assert(str_contains($unsafe['pages'][0]['canonical_block_markup'], 'style="color:rgb(255, 255, 255)"'), 'Reachable state_variant rules keep the literal.');
$assert($unsafe['theme']['design_system']['metrics']['refusal_reasons']['state_variant'] > 0, 'The safety refusal remains reported.');
// An author !important rule overrides the inline colour, so the source never
// renders it: the literal is neither kept nor bound, and the author rule paints.
$important = $compile('.body{color:#fff!important}');
$importantMarkup = $important['pages'][0]['canonical_block_markup'];
preg_match_all('/<!-- wp:paragraph .*?<\/p>/', $importantMarkup, $paragraphs);
$assert(2 === count($paragraphs[0]) && !preg_match('/color:rgb\(|textColor/', implode('', $paragraphs[0])), 'An overridden inline colour is not projected.');
$assert($important['theme']['design_system']['metrics']['refusal_reasons']['important'] > 0, 'The important refusal remains reported.');
$ordinaryMedia = $compile('@media(max-width:600px){.body{color:#000}}');
$assert(!str_contains($ordinaryMedia['pages'][0]['canonical_block_markup'], 'style="color:rgb(255, 255, 255)"'), 'An ordinary breakpoint cannot override a direct inline colour.');
$unsafe = $compile('@media(max-width:600px){.body{color:#000!important}}');
$assert(str_contains($unsafe['pages'][0]['canonical_block_markup'], 'style="color:rgb(255, 255, 255)"'), 'A breakpoint that can override the inline colour still prevents binding.');

// Reduced sole-button paragraph from #4618, plus the outline link from #4643.
foreach (array(
    'sole-button paragraph' => array(
        str_replace('class="btn btn--primary"', 'class="btn btn--primary" style="background:#b9276b;color:#ffffff"', file_get_contents(dirname(__DIR__) . '/fixtures/button-in-paragraph.html')),
        array('backgroundColor' => '#b9276b', 'textColor' => '#ffffff'),
    ),
    'outline anchor' => array(
        '<!doctype html><html><body><main><a href="/contact/" class="btn" style="display:inline-block;padding:8px 24px;border:2px solid #b9276b;background:transparent;color:#b9276b">Contact</a></main></body></html>',
        array('borderColor' => '#b9276b'),
    ),
) as $label => [$html, $expected]) {
    $buttonPlan = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array('index.html' => $html)))->toArray()['source_reports']['wordpress_site_plan'];
    $buttonMarkup = $buttonPlan['pages'][0]['canonical_block_markup'];
    $tokens = array();
    foreach ($buttonPlan['writes'] as $write) {
        if ('theme.json' !== $write['target_path']) continue;
        $theme = json_decode($write['payload']['data'], true, 512, JSON_THROW_ON_ERROR);
        $tokens = array_column($theme['settings']['color']['palette'], 'slug', 'color');
    }
    $assert(1 === preg_match('/<!-- wp:button (\{.*?\}) -->(.*?)<!-- \/wp:button -->/s', $buttonMarkup, $button), $label . ' remains a native button.');
    $attrs = json_decode($button[1], true, 512, JSON_THROW_ON_ERROR);
    foreach ($expected as $attribute => $color) {
        $assert(isset($tokens[$color]) && $tokens[$color] === ($attrs[$attribute] ?? null), $label . ' binds ' . $attribute . ' to its palette token.');
        $section = 'borderColor' === $attribute ? 'border' : 'color';
        $key = array('borderColor' => 'color', 'backgroundColor' => 'background', 'textColor' => 'text')[$attribute];
        $property = array('borderColor' => 'border-color', 'backgroundColor' => 'background-color', 'textColor' => 'color')[$attribute];
        $assert(!isset($attrs['style'][$section][$key]) && !str_contains($button[2], $property . ':' . $color), $label . ' removes the literal ' . $property . '.');
        $class = 'borderColor' === $attribute ? 'has-' . $tokens[$color] . '-border-color' : 'has-' . $tokens[$color] . ('backgroundColor' === $attribute ? '-background-color' : '-color');
        $assert(str_contains($button[2], $class), $label . ' saves the core preset class.');
    }
}

echo "Exact palette literal binding contract passed.\n";
