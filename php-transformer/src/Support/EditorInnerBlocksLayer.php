<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\Support;

/**
 * The editor-only inner-blocks layer: an element the block editor renders
 * between an authored parent and its children that saved markup does not
 * have.
 *
 * Two kinds exist. A Blocks Engine layout shell renders its inner blocks in
 * its own LAYOUT_SHELL_CLASS div. Any other block whose edit wraps its inner
 * blocks in one more element (Jetpack's contact form, for one) renders a
 * Gutenberg inner-blocks list, `.block-editor-block-list__layout`, that is not
 * itself a block wrapper. A block whose wrapper is the list (most core
 * containers merge the two) carries `.block-editor-block-list__block` too and
 * adds no layer. The definition names no provider.
 *
 * This is the one definition every `parent>child` restatement through the
 * layer uses: translation (EditorShellBoundary) emits it and materialization
 * (SyncedPatternProjection) widens exactly it, so the two cannot drift. It
 * lives here because materialization may not import translation.
 */
final class EditorInnerBlocksLayer
{
    /** The class a layout shell's editor puts on its one inner-blocks element. */
    public const LAYOUT_SHELL_CLASS = 'blocks-engine-layout-shell-editor-inner-blocks';

    /** Every editor-only inner-blocks layer, as a selector list. */
    public const SELECTOR_LIST = '.' . self::LAYOUT_SHELL_CLASS
        . ',.block-editor-block-list__layout:not(.block-editor-block-list__block)';

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
