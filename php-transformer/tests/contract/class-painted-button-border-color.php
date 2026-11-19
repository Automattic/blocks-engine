<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

/*
 * A native button whose author class paints its border colour, with a hover
 * rule that changes it, renders that colour through the class stylesheet.
 * The button must not freeze the resting border colour as a literal block
 * style: the literal sits outside the editable design system and the inline
 * declaration masks the authored hover border.
 */
$html = <<<'HTML'
<!doctype html><html><head><style>
body{font-family:Georgia,serif;color:#171717;background:#ede8e3}
.filter-btn{color:#171717;background:#fff;border:1px solid #e0ddd7;padding:10px 16px;font-size:12px;font-weight:700;cursor:pointer}
.filter-btn:hover{border-color:#171717}
</style></head><body><main>
<h1>Writing</h1><p>Filter the essays by topic.</p>
<div class="filters"><button type="button" class="filter-btn">Free speech</button><button type="button" class="filter-btn">Privacy</button><button type="button" class="filter-btn">Dignity at work</button></div>
</main></body></html>
HTML;
$plan = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array('index.html' => $html)))->toArray()['source_reports']['wordpress_site_plan'];
$assert = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};
$markup = implode('', array_column($plan['pages'], 'canonical_block_markup'));
$buttons = 0;
preg_match_all('/<!-- wp:button (\{(?:(?!-->).)*\}) -->(.*?)<!-- \/wp:button -->/s', $markup, $matches, PREG_SET_ORDER);
foreach ($matches as [, $json, $saved]) {
    $attributes = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
    if (!in_array('filter-btn', explode(' ', (string) ($attributes['className'] ?? '')), true)) continue;
    ++$buttons;
    $assert(!isset($attributes['style']['border']['color']) && !isset($attributes['borderColor']), 'A class-painted button must not freeze its resting border colour as a literal: ' . $json);
    $assert(!str_contains($saved, 'border-color:#e0ddd7') && !str_contains($saved, 'has-border-color'), 'The saved button must leave its border colour to the author class: ' . $saved);
}
$assert(3 === $buttons, 'The page must carry the three filter buttons: ' . $markup);
$css = implode("\n", array_column(array_filter($plan['assets'], static fn (array $asset): bool => 'css' === ($asset['kind'] ?? '')), 'content'));
$assert(str_contains($css, '#e0ddd7'), 'The author class must still paint the resting border colour.');

echo "class-painted button border colour: ok\n";
