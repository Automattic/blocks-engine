<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\Support;

use Automattic\BlocksEngine\PhpTransformer\Css\CssValueSplitter;

/** Carries inline body spacing and inert custom properties into authored CSS. */
final class InlineBodyStyle
{
    /**
     * Carry margin/padding and custom properties only. Positioning, sizing,
     * overflow and all other document mechanics remain owned by WordPress.
     * Reject the entire style if it could break out of the generated rule.
     */
    public static function rule(string $html): string
    {
        if ( 1 !== preg_match('/<body\b[^>]*\sstyle\s*=\s*(?:"([^"]*)"|\'([^\']*)\')/i', $html, $matches) ) {
            return '';
        }

        $style = html_entity_decode('' !== ($matches[1] ?? '') ? $matches[1] : ($matches[2] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        if ( '' === trim($style) || preg_match('/[{}<>]/', $style) ) {
            return '';
        }

        $declarations = array();
        foreach ( CssValueSplitter::splitTopLevel($style, array( ';' )) as $declaration ) {
            $parts = explode(':', $declaration, 2);
            if ( 2 !== count($parts) ) {
                continue;
            }
            $property = trim($parts[0]);
            $custom = str_starts_with($property, '--');
            // Custom property names are case-sensitive.
            if ( ! $custom ) {
                $property = strtolower($property);
            }
            $value = trim($parts[1]);
            if ( '' !== $value && ($custom || 1 === preg_match('/^(?:margin|padding)(?:-(?:top|right|bottom|left))?$/', $property)) ) {
                $declarations[] = $property . ':' . $value;
            }
        }

        return array() === $declarations ? '' : 'body{' . implode(';', $declarations) . '}';
    }
}
