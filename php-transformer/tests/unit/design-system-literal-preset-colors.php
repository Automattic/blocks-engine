<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\DesignSystem;

// literal_preset_color_count says how many block colour attributes store a
// literal equal to a preset; literalPresetColors() says where each one is.
$system = DesignSystem::fromUsage(array('index.html' => array(
    'schema' => DesignSystem::USAGE_SCHEMA,
    'text' => array(array('role' => 'body', 'color' => '#2563eb', 'font_size_px' => 16, 'count' => 1, 'weight' => 20)),
)));
if (null === $system) throw new RuntimeException('The design system derives from usage.');
$slug = $system->colorSlug('#2563eb');
if (null === $slug) throw new RuntimeException('The usage colour is a palette preset.');
$markups = array(
    'page:index.html' => '<!-- wp:group {"style":{"color":{"background":"rgb(37, 99, 235)","text":"var:preset|color|' . $slug . '"},"border":{"top":{"color":"#2563eb","style":"solid"}}}} --><div class="wp-block-group"></div><!-- /wp:group -->',
    'part:footer' => '<!-- wp:paragraph {"style":{"color":{"text":"#123456"}}} --><p>Other</p><!-- /wp:paragraph -->',
);
$expected = array(
    array('document' => 'page:index.html', 'block' => 'group', 'attribute' => 'style.color.background', 'value' => 'rgb(37, 99, 235)', 'preset' => $slug),
    array('document' => 'page:index.html', 'block' => 'group', 'attribute' => 'style.border.top.color', 'value' => '#2563eb', 'preset' => $slug),
);
if ($expected !== $system->literalPresetColors($markups)) throw new RuntimeException('Each literal preset colour is listed with its document, block, attribute and preset: ' . json_encode($system->literalPresetColors($markups)));
if (2 !== $system->metrics(array_values($markups), array())['literal_preset_color_count']) throw new RuntimeException('The count matches the listing.');
echo "Design system literal preset colours passed\n";
