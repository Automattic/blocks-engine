<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\Contract;

/**
 * Core blocks the HTML producer can emit, keyed by the contract that verifies
 * that producer. Shared so capability reporting can read the map without
 * depending on the conversion layer.
 */
final class EmittedCoreBlockContracts
{
    /** @return array<string,string> */
    public static function map(): array
    {
        return array(
            'core/accordion' => 'html_transformer_contract',
            'core/accordion-heading' => 'html_transformer_contract',
            'core/accordion-item' => 'html_transformer_contract',
            'core/accordion-panel' => 'html_transformer_contract',
            'core/audio' => 'html_transformer_contract',
            'core/button' => 'html_transformer_contract',
            'core/buttons' => 'html_transformer_contract',
            'core/code' => 'html_transformer_contract',
            'core/column' => 'html_transformer_contract',
            'core/columns' => 'html_transformer_contract',
            'core/cover' => 'html_transformer_contract',
            'core/details' => 'html_transformer_contract',
            'core/embed' => 'html_transformer_contract',
            'core/file' => 'html_transformer_contract',
            'core/gallery' => 'html_transformer_contract',
            'core/group' => 'html_transformer_contract',
            'core/heading' => 'html_transformer_contract',
            'core/image' => 'html_transformer_contract',
            'core/list' => 'html_transformer_contract',
            'core/list-item' => 'html_transformer_contract',
            'core/math' => 'html_transformer_contract',
            'core/media-text' => 'html_transformer_contract',
            'core/navigation' => 'html_transformer_contract',
            'core/navigation-link' => 'html_transformer_contract',
            'core/navigation-submenu' => 'html_transformer_contract',
            'core/paragraph' => 'html_transformer_contract',
            'core/preformatted' => 'html_transformer_contract',
            'core/pullquote' => 'html_transformer_contract',
            'core/quote' => 'html_transformer_contract',
            'core/search' => 'html_transformer_contract',
            'core/separator' => 'html_transformer_contract',
            'core/shortcode' => 'html_transformer_contract',
            'core/social-link' => 'html_transformer_contract',
            'core/social-links' => 'html_transformer_contract',
            'core/spacer' => 'html_transformer_contract',
            'core/table' => 'html_transformer_contract',
            'core/video' => 'html_transformer_contract',
        );
    }
}
