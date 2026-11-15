<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Patterns;

trait PatternGateHelpersTrait
{
    /**
     * @param array<int, array<string, mixed>> $blocks
     */
    private function containsTextBearingBlock(array $blocks): bool
    {
        $textBearingNames = array( 'core/heading', 'core/paragraph', 'core/list', 'core/buttons', 'core/quote' );

        foreach ( $blocks as $block ) {
            if ( ! is_array($block) ) {
                continue;
            }

            if ( in_array($block['blockName'] ?? null, $textBearingNames, true) ) {
                return true;
            }

            $innerBlocks = $block['innerBlocks'] ?? array();
            if ( is_array($innerBlocks) && $this->containsTextBearingBlock($innerBlocks) ) {
                return true;
            }
        }

        return false;
    }
}
