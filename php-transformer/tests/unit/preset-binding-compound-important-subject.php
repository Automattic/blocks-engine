<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\DesignSystem;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\PresetBinding;

// A projected `!important` utility (`.text-secondary`) lands on a compound
// subject of engine classes. A block that shares only some of those classes
// is not its subject, so its preset-equal literal colour binds; a block that
// carries the whole compound keeps the literal under the important refusal.
$assert = static function (bool $ok, string $message): void {
    if (!$ok) throw new RuntimeException($message);
};
$system = DesignSystem::fromUsage(array('index.html' => array(
    'schema' => DesignSystem::USAGE_SCHEMA,
    'text' => array(array('color' => '#2a493b', 'weight' => 100, 'count' => 1)),
)));
$assert(null !== $system, 'Expected a design system.');
$slug = $system->colorSlug('#2a493b');
$css = ':where(.blocks-engine-role-richtext.blocks-engine-subject.blocks-engine-subject-class-1):not(.be-c-0){color:#6c757d!important}';
$block = static fn(string $classes): string => '<!-- wp:group {"className":"' . $classes . '","style":{"color":{"text":"#2A493B"}}} --><div class="wp-block-group has-text-color ' . $classes . '" style="color:#2A493B"><!-- wp:paragraph --><p>Card</p><!-- /wp:paragraph --></div><!-- /wp:group -->';

$card = $block('blocks-engine-role-semantic blocks-engine-subject');
$bound = (new PresetBinding($system, array(array('kind' => 'css', 'content' => $css))))->bind($card);
$assert(str_contains($bound, '"textColor":"' . $slug . '"') && !str_contains($bound, '#2A493B'), 'A block sharing one class of a compound !important subject binds its preset colour.');

$muted = $block('blocks-engine-role-richtext blocks-engine-subject blocks-engine-subject-class-1');
$guarded = new PresetBinding($system, array(array('kind' => 'css', 'content' => $css)));
$assert($guarded->bind($muted) === $muted, 'A block carrying the whole compound keeps its literal.');
$assert(1 === $guarded->refusals(array($muted))['important'], 'The kept literal records the important refusal.');
echo "Compound important subject preset binding passed\n";
