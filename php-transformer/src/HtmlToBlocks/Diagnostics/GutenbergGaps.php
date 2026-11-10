<?php

declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Diagnostics;

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Generators\DescriptionListBlockGenerator;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Session\HtmlTransformerSession;

/**
 * Lists the Gutenberg core limitations a conversion's output works around,
 * for the import report.
 */
final class GutenbergGaps
{
    /**
     * Core limitations this output works around, for the import report.
     *
     * @param array<int, array<string, mixed>> $blocks
     * @return list<array{id: string, block_name: string, references: list<string>}>
     */
    public static function collect(HtmlTransformerSession $session, array $blocks): array
    {
        $gaps = array();
        if ( $session->requireGeneratedBlockRegistry()->has(DescriptionListBlockGenerator::class) ) {
            $gaps[] = array('id' => 'semantic-description-list', 'block_name' => $session->requireGeneratedBlockRegistry()->blockName(DescriptionListBlockGenerator::LOCAL_NAME), 'references' => array('https://github.com/WordPress/gutenberg/issues/4880', 'https://github.com/WordPress/gutenberg/pull/20760'));
        }
        // Core's overlay opens from one button and closes from a second one
        // inside it, so a menu the source toggles from a single control gains
        // a close button. `overlayMenu` defaults to `mobile` in block.json.
        $stack = $blocks;
        while ( array() !== $stack ) {
            $block = array_pop($stack);
            if ( 'core/navigation' === ($block['blockName'] ?? '') && 'never' !== ($block['attrs']['overlayMenu'] ?? 'mobile') ) {
                $gaps[] = array('id' => 'navigation-overlay-close-control', 'block_name' => 'core/navigation', 'references' => array('https://github.com/WordPress/gutenberg/issues/49402'));
                break;
            }
            array_push($stack, ...($block['innerBlocks'] ?? array()));
        }

        return $gaps;
    }
}
