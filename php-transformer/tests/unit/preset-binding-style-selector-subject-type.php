<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\DesignSystem;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\PresetBinding;

$system = DesignSystem::fromUsage(array('index.html' => array(
    'schema' => DesignSystem::USAGE_SCHEMA,
    'text' => array(array('color' => '#116dff', 'weight' => 100, 'count' => 1)),
)));
if (null === $system) throw new RuntimeException('Expected a design system');
$binding = new PresetBinding($system, array(array('kind' => 'css', 'content' =>
    'span.blocks-engine-subject[style*="color"]{color:red}'
)));
$button = '<!-- wp:button {"className":"blocks-engine-subject","style":{"color":{"text":"#116dff"}}} --><div class="wp-block-button blocks-engine-subject"><a class="wp-block-button__link has-text-color wp-element-button" style="color:#116dff">Continue</a></div><!-- /wp:button -->';
$result = $binding->bind($button);
if (str_contains($result, 'color:#116dff') || !str_contains($result, '"textColor":')) {
    throw new RuntimeException('A span style reader must not prevent exact button preset binding through a shared engine class');
}
$guarded = new PresetBinding($system, array(array('kind' => 'css', 'content' =>
    'a.blocks-engine-subject[style*="color"]{color:red}'
)));
if (!str_contains($guarded->bind($button), 'color:#116dff')) {
    throw new RuntimeException('A matching subject type must retain the style-text safety guard');
}
// A bare type selector stays below the single-class preset rule, so only
// the subject type decides whether the guard applies.
foreach (array('span' => false, 'a' => true) as $tag => $matches) {
    $important = new PresetBinding($system, array(array('kind' => 'css', 'content' =>
        $tag . '{color:red!important}'
    )));
    $retained = str_contains($important->bind($button), 'color:#116dff');
    if ($retained !== $matches) throw new RuntimeException('Important paint guard must respect the subject type: ' . $tag);
}
echo "Style selector subject type passed\n";
