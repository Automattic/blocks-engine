<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\DesignSystem;

// Block literals are editing boundaries, not evidence that author stylesheets
// should gain relative-color overrides (which can win over contextual paint).
$system = DesignSystem::fromUsage(array('index.html' => array(
    'schema' => DesignSystem::USAGE_SCHEMA,
    'text' => array(array('role' => 'body', 'color' => '#2a2a28', 'font_size_px' => 16, 'count' => 1, 'weight' => 20)),
)));
if (null === $system) throw new RuntimeException('Expected a usage-derived palette.');
$before = $system->nearShadeColor('#282826');
$extended = $system->withBlockColors(array(
    '<!-- wp:paragraph {"style":{"color":{"text":"#282826"}}} --><p class="has-text-color" style="color:#282826">Explicit text</p><!-- /wp:paragraph -->',
));
if (null !== $before || $before !== $extended->nearShadeColor('#282826')) {
    throw new RuntimeException('A block-only color must not extend stylesheet near-shade bindings.');
}
if ($system->colors !== $extended->colors) {
    throw new RuntimeException('A near-shade block literal must not change the palette.');
}
echo "Block color stylesheet scope passed\n";
