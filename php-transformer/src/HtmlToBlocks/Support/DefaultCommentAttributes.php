<?php

declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Support;

/**
 * Strips comment attributes whose value is the block's registered default
 * before serialization, matching what WordPress itself saves.
 */
final class DefaultCommentAttributes
{
    /**
     * Comment attributes whose value is the block's registered default. The
     * block editor leaves these out of saved markup, so emitting them makes
     * converted markup differ from what WordPress itself saves for the same
     * block. Mirrors each block's block.json default.
     */
    private const DEFAULT_COMMENT_ATTRIBUTES = array(
        'core/table' => array( 'hasFixedLayout' => true ),
    );

    /**
     * @param list<array<string, mixed>> $blocks
     * @return list<array<string, mixed>>
     */
    public static function strip(array $blocks): array
    {
        $strip = static function (array $block) use (&$strip): array {
            $defaults = self::DEFAULT_COMMENT_ATTRIBUTES[ (string) ($block['blockName'] ?? '') ] ?? array();
            if ( is_array($block['attrs'] ?? null) ) {
                foreach ( $defaults as $name => $default ) {
                    if ( array_key_exists($name, $block['attrs']) && $default === $block['attrs'][ $name ] ) {
                        unset($block['attrs'][ $name ]);
                    }
                }
            }
            if ( is_array($block['innerBlocks'] ?? null) ) {
                $block['innerBlocks'] = array_map($strip, $block['innerBlocks']);
            }
            return $block;
        };
        return array_map($strip, $blocks);
    }
}
