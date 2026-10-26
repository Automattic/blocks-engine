<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\Support;

/**
 * The editor-only inner-blocks layer: an element the block editor renders
 * between an authored parent and its children that saved markup does not
 * have.
 *
 * Jetpack's contact form renders a separate list inside its block wrapper.
 * Only this proven topology is named here. Core containers and layout shells
 * merge the list into an authored wrapper and add no layer, even when a
 * shell's innermost wrapper does not carry Gutenberg's block marker.
 */
final class EditorInnerBlocksLayer
{
    /** Shell wrappers are themselves lists, not additional editor layers. */
    public const SELECTOR_LIST = '.jetpack-contact-form.block-editor-block-list__layout:not(.block-editor-block-list__block)';

    /**
     * The layer as a weightless compound, `:where(<layers>)`, optionally
     * widened by further layer selectors. `:where()` keeps a restated step
     * tied in specificity with the authored selector.
     */
    public static function compound(string ...$extra): string
    {
        return ':where(' . implode(',', array_merge(array( self::SELECTOR_LIST ), $extra)) . ')';
    }
}
