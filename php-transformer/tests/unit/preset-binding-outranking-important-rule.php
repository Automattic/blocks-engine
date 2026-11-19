<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\DesignSystem;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\PresetBinding;

// #6763: a heading whose computed colour equals the palette's contrast
// preset stayed literal because a contextual `!important` rule elsewhere in
// the site (`.booking h3`) named its tag. That rule outranks the
// single-class preset rule binding adds, so wherever it applies it keeps
// painting, exactly as it did over the inline literal: binding changes no
// paint and must happen. Rules that do not outrank the preset class keep
// the `important` refusal.
$assert = static function (bool $ok, string $message): void {
    if (!$ok) throw new RuntimeException($message);
};
$system = DesignSystem::fromUsage(array('index.html' => array(
    'schema' => DesignSystem::USAGE_SCHEMA,
    'text' => array(array('color' => '#111111', 'weight' => 100, 'count' => 1)),
)));
$assert(null !== $system, 'Expected a design system.');
$slug = $system->colorSlug('#111111');
$assert(null !== $slug, 'The palette names #111111.');
$heading = '<!-- wp:heading {"level":3,"style":{"color":{"text":"rgb(17, 17, 17)"}}} --><h3 class="wp-block-heading has-text-color" style="color:rgb(17, 17, 17)">Kracht</h3><!-- /wp:heading -->';
$paragraph = '<!-- wp:paragraph {"className":"lead","style":{"color":{"text":"rgb(17, 17, 17)"}}} --><p class="lead has-text-color" style="color:rgb(17, 17, 17)">Copy</p><!-- /wp:paragraph -->';
$binding = static fn(string $css): PresetBinding => new PresetBinding($system, array(array('kind' => 'css', 'content' => $css)));

foreach (array(
    '.booking h3{color:#fff!important}' => $heading,
    '.booking :where(.lead,.label):not(be-t-0){color:#fff!important}' => $paragraph,
    'h3#title,p.lead.intro{color:#fff!important}' => $paragraph,
) as $css => $block) {
    $bound = $binding($css)->bind($block);
    $assert(str_contains($bound, '"textColor":"' . $slug . '"') && !str_contains($bound, 'rgb(17, 17, 17)'), "A preset-equal colour binds past an outranking !important rule: {$css}");
    $assert(str_contains($bound, 'has-' . $slug . '-color'), "The bound block carries the preset class: {$css}");
    $assert(0 === array_sum($binding($css)->refusals(array($block))), "Nothing is refused past an outranking !important rule: {$css}");
}

foreach (array(
    'h3{color:#fff!important}' => $heading,
    ':where(.booking) h3{color:#fff!important}' => $heading,
    '.lead{color:#fff!important}' => $paragraph,
) as $css => $block) {
    $guarded = $binding($css);
    $assert($guarded->bind($block) === $block, "A !important rule the preset class would outrank keeps the literal: {$css}");
    $assert(1 === $guarded->refusals(array($block))['important'], "The kept literal records the important refusal: {$css}");
}
echo "Outranking important rule preset binding passed\n";
