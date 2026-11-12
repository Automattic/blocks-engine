<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan;

use Automattic\BlocksEngine\PhpTransformer\Support\ArrayField;
use Automattic\BlocksEngine\PhpTransformer\Support\BlockAttributes;

/**
 * Every block markup string a runtime entity carries.
 *
 * A provider renders an entity's item template, item slots and readable
 * blocks in place of page content. Each takes the same reference projection
 * as the page it came from, so every pass that rewrites page markup maps
 * them too.
 */
final class EntityMarkup
{
    /**
     * Apply `$map` to every block markup a collection item template carries:
     * the template card and each item's slot values. A provider renders this
     * markup in place of page content, so it takes the same reference
     * projection as the page it came from.
     *
     * @param array<string,mixed> $entity
     * @param callable(string):string $map
     * @return array<string,mixed>
     */
    public static function map(array $entity, callable $map): array
    {
        if (is_string($entity['item_template']['block_markup'] ?? null)) $entity['item_template']['block_markup'] = $map($entity['item_template']['block_markup']);
        foreach (ArrayField::array($entity, 'items') as $itemIndex => $item) {
            foreach (ArrayField::array($item, 'slots') as $slotId => $slot) {
                if (is_string($slot['markup'] ?? null)) $entity['items'][$itemIndex]['slots'][$slotId]['markup'] = $map($slot['markup']);
            }
        }
        // A provider rebuilds a form from its readable blocks, so their media
        // must reference published assets exactly as the page does.
        if (is_array($entity['readable_blocks'] ?? null)) $entity['readable_blocks'] = self::mapBlockTree($entity['readable_blocks'], $map);
        return $entity;
    }

    /**
     * Map every markup string of a parsed block tree. Attributes travel as
     * their serialized block comment, the form markup mappers already read.
     *
     * @param array<int|string,mixed> $blocks
     * @return array<int|string,mixed>
     */
    private static function mapBlockTree(array $blocks, callable $map): array
    {
        foreach ($blocks as $index => $block) {
            if (!is_array($block) || !is_string($block['blockName'] ?? null)) continue;
            if (is_array($block['attrs'] ?? null) && array() !== $block['attrs']) {
                $comment = $map('<!-- wp:' . $block['blockName'] . ' ' . BlockAttributes::encode($block['attrs'], JSON_INVALID_UTF8_SUBSTITUTE) . ' /-->');
                if (preg_match('~^<!-- wp:\S+ (\{.*\}) /-->$~s', $comment, $match) && is_array($attrs = json_decode($match[1], true))) $block['attrs'] = $attrs;
            }
            if (is_string($block['innerHTML'] ?? null)) $block['innerHTML'] = $map($block['innerHTML']);
            foreach (ArrayField::array($block, 'innerContent') as $contentIndex => $content) {
                if (is_string($content)) $block['innerContent'][$contentIndex] = $map($content);
            }
            if (is_array($block['innerBlocks'] ?? null)) $block['innerBlocks'] = self::mapBlockTree($block['innerBlocks'], $map);
            $blocks[$index] = $block;
        }
        return $blocks;
    }
}
