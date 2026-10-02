<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Generators;

/** Keeps rich label markup and its plain accessible label in sync in editors. */
final class AuthoredFormLabelEditorScript
{
    public static function synchronize(string $script): string
    {
        return str_replace(
            array(
                'props.setAttributes( { label: label } );',
                'props.setAttributes( { labelMarkup: labelMarkup } );',
            ),
            array(
                'props.setAttributes( { label: label, labelMarkup: "" } );',
                'props.setAttributes( { labelMarkup: labelMarkup, label: window.wp.richText.create( { html: labelMarkup } ).text } );',
            ),
            $script
        );
    }
}
