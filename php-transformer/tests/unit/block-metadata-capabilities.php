<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\BlockFactory;
use Automattic\BlocksEngine\PhpTransformer\WordPress\Runtime;

$assert = static function (bool $condition, string $message): void {
    if ( ! $condition ) {
        throw new RuntimeException($message);
    }
};

$runtime = new Runtime();
$group = $runtime->blockMetadata('core/group');
$accordion = $runtime->blockMetadata('core/accordion');
$accordionItem = $runtime->blockMetadata('core/accordion-item');
$navigation = $runtime->blockMetadata('core/navigation');
$assert(is_array($group['supports']['layout'] ?? null), 'The snapshot must retain Group layout support.');
$assert(true === ($group['allowedBlocks'] ?? false), 'The snapshot must retain Group allowed-block support.');
$assert(array('core/accordion-item') === ($accordion['allowedBlocks'] ?? null), 'The snapshot must retain Accordion allowed blocks.');
$assert(array('core/accordion') === ($accordionItem['parent'] ?? null), 'The snapshot must retain Accordion Item parent restrictions.');
$assert(true === ($navigation['dynamic'] ?? false) && array('wp-block-navigation-editor') === ($navigation['assets']['editorStyle'] ?? null), 'The snapshot must retain dynamic and asset capabilities.');

$factory = new BlockFactory();
$assert('grid' === ($factory->create('core/group', array('layout' => array('type' => 'grid')))['attrs']['layout']['type'] ?? null), 'Snapshot metadata must preserve supported Group grid output.');
$assert(! isset($factory->create('core/paragraph', array('layout' => array('type' => 'grid')))['attrs']['layout']), 'Snapshot metadata must reject Paragraph layout output.');
$assert(! isset($factory->create('core/accordion-item', array('layout' => array('type' => 'flex')))['attrs']['layout']), 'Snapshot metadata must reject block-managed Accordion Item layout output.');

eval(<<<'PHP'
final class WP_Block_Type_Registry {
    public static array $types = array();
    public static function get_instance(): self { return new self(); }
    public function get_all_registered(): array { return self::$types; }
}
PHP);

WP_Block_Type_Registry::$types = array(
    'core/accordion' => (object) array(
        'name' => 'core/accordion',
        'supports' => $accordion['supports'],
        'attributes' => $accordion['attributes'],
        'allowed_blocks' => $accordion['allowedBlocks'],
        'view_script_module_ids' => $accordion['assets']['viewScriptModule'],
    ),
    'core/group' => (object) array(
        'name' => 'core/group',
        'supports' => $group['supports'],
        'attributes' => $group['attributes'],
        'parent' => $group['parent'],
        'allowed_blocks' => $group['allowedBlocks'],
    ),
);
$live = (new Runtime())->blockMetadata('core/group');
$assert($group === $live && $accordion === (new Runtime())->blockMetadata('core/accordion'), 'Equivalent live declarations must match the bundled snapshot capabilities.');

WP_Block_Type_Registry::$types['core/group'] = (object) array(
    'name' => 'core/group',
    'supports' => array('layout' => array('allowSwitching' => false)),
    'attributes' => array('layout' => array('type' => 'object')),
    'parent' => array('plugin/container'),
    'allowed_blocks' => array('plugin/card'),
    'render_callback' => static fn (): string => '',
    'editor_script_handles' => array('plugin-group-editor'),
    'style_handles' => array('plugin-group-style'),
);
$live = (new Runtime())->blockMetadata('core/group');
$assert(array('plugin/container') === $live['parent'] && array('plugin/card') === $live['allowedBlocks'], 'Live parent and allowed blocks must override snapshot drift.');
$assert(true === $live['dynamic'] && array('plugin-group-editor') === $live['assets']['editorScript'] && array('plugin-group-style') === $live['assets']['style'], 'Live dynamic and asset capabilities must override snapshot drift.');
$assert(! isset((new BlockFactory())->create('core/group', array('layout' => array('type' => 'grid')))['attrs']['layout']), 'Live layout switching metadata must control emitted Group output.');

fwrite(STDOUT, "Block metadata capability tests passed.\n");
