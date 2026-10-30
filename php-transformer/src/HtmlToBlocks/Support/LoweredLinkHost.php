<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Support;

use DOMElement;

/**
 * The contract for a synthetic paragraph that only hosts a lowered source link.
 *
 * The source link is the element author CSS was written against, so it keeps
 * its classes inside the paragraph's RichText. The paragraph is an engine
 * carrier, not a source element: it carries none of the link's classes, so
 * `.x .link-class` still matches the link and `.x .link-class a` still matches
 * nothing, exactly as in the source tree. Engine marker classes stay on the
 * host where the support CSS expects them.
 */
final class LoweredLinkHost
{
    /**
     * Removes the link's source classes, and the inherited presentation they
     * resolve to, from the host paragraph's attributes.
     *
     * The host saves the link with its classes, so spacing or inherited type
     * the author wrote on the link still arrives with it; restating either as
     * a block attribute applies it a second time, once on the host box and
     * once on the link inside it. Spacing or type the author never wrote on
     * the link has no source to arrive from, so only the duplicated half is
     * dropped.
     *
     * A restated line-height/font-size is not just redundant: it is a genuine
     * line box on the host paragraph, sized from the link's own (frequently
     * smaller) type instead of the host's inherited font. Dropping these
     * properties is safe specifically because they are the ordinarily
     * inherited ones: with nothing restated on the host, the saved link still
     * resolves the same value from its own rule, or by inheriting through the
     * host exactly as it inherited through its source ancestor.
     * `textDecoration` is deliberately kept: it does not inherit on its own
     * (only the literal `inherit` keyword propagates it), so a host that
     * resolved it from an ancestor must keep restating it. Colour is kept as
     * well: the support CSS hands a host text colour to the link
     * (`p.blocks-engine-synthetic-paragraph.has-text-color>a{color:inherit}`).
     *
     * @param array<string, mixed> $attrs
     * @return array<string, mixed>
     */
    public static function attributes(array $attrs, DOMElement $link): array
    {
        $sourceClasses = self::sourceClasses($link);
        if ( array() === $sourceClasses ) {
            return $attrs;
        }

        $classes = array_values(array_filter(
            preg_split('/\s+/', trim((string) ($attrs['className'] ?? ''))) ?: array(),
            static fn (string $class): bool => '' !== $class && ! in_array($class, $sourceClasses, true)
        ));
        if ( array() === $classes ) {
            unset($attrs['className']);
        } else {
            $attrs['className'] = implode(' ', $classes);
        }

        if ( ! is_array($attrs['style'] ?? null) ) {
            return $attrs;
        }
        unset($attrs['style']['spacing']);
        if ( is_array($attrs['style']['typography'] ?? null) ) {
            foreach ( array( 'fontSize', 'fontWeight', 'lineHeight', 'letterSpacing', 'textTransform', 'fontStyle' ) as $inheritedTypographyProperty ) {
                unset($attrs['style']['typography'][$inheritedTypographyProperty]);
            }
            if ( array() === $attrs['style']['typography'] ) {
                unset($attrs['style']['typography']);
            }
        }
        if ( array() === $attrs['style'] ) {
            unset($attrs['style']);
        }

        return $attrs;
    }

    /** The link's source class attribute value, normalized, for its saved `<a>`. */
    public static function classAttribute(DOMElement $link): string
    {
        return implode(' ', self::sourceClasses($link));
    }

    /** @return list<string> */
    private static function sourceClasses(DOMElement $link): array
    {
        return array_values(array_filter(
            preg_split('/\s+/', trim(SourceDom::attr($link, 'class'))) ?: array(),
            static fn (string $class): bool => '' !== $class
        ));
    }
}
