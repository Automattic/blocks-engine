<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\RichText;

use Automattic\BlocksEngine\PhpTransformer\Contract\RichTextInlineTags;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Support\SourceDom;
use Automattic\BlocksEngine\PhpTransformer\Support\HtmlSerialization;
use DOMElement;

/**
 * Carries a source text element's own `lang` and `dir` onto the native
 * RichText block it becomes.
 *
 * core/paragraph saves its `direction` attribute (`ltr`/`rtl`) as the `dir`
 * of its `<p>`, so a paragraph's base direction stays a block attribute.
 * Core text blocks have no block-level `lang`, and core/heading and
 * core/list-item have no direction attribute either; those values wrap the
 * block's RichText content in one `<span lang dir>`. RichText keeps an
 * unregistered inline element with its attributes, so the saved markup
 * round-trips the editor's validation and the span stays editable.
 *
 * `<bdo>`, the language format's tag, is not used: it overrides the bidi
 * algorithm, which reverses any opposite-direction run inside the text.
 */
final class RichTextLanguageDirection
{
    private const BLOCKS = array( 'core/paragraph', 'core/heading', 'core/list-item' );

    /**
     * @param array<string, mixed> $attrs
     * @return array<string, mixed>
     */
    public static function apply(string $name, array $attrs, DOMElement $sourceElement): array
    {
        if ( ! in_array($name, self::BLOCKS, true) ) {
            return $attrs;
        }

        $lang = trim(SourceDom::attr($sourceElement, 'lang'));
        if ( 1 !== preg_match('/^[A-Za-z]{1,8}(?:-[A-Za-z0-9]{1,8})*$/', $lang) ) {
            $lang = '';
        }
        $dir = strtolower(trim(SourceDom::attr($sourceElement, 'dir')));
        if ( ! in_array($dir, array( 'ltr', 'rtl', 'auto' ), true) ) {
            $dir = '';
        }

        if ( 'core/paragraph' === $name && in_array($dir, array( 'ltr', 'rtl' ), true) ) {
            $attrs['direction'] = $dir;
            $dir = '';
        }

        $content = (string) ($attrs['content'] ?? '');
        if ( ( '' === $lang && '' === $dir ) || '' === trim($content) || ! self::isPhrasingOnly($content) ) {
            return $attrs;
        }

        $attrs['content'] = '<span' . HtmlSerialization::attributes(array( 'lang' => $lang, 'dir' => $dir )) . '>' . $content . '</span>';

        return $attrs;
    }

    /** Whether every element in RichText content is an allowed inline tag. */
    private static function isPhrasingOnly(string $content): bool
    {
        preg_match_all('/<([a-zA-Z][a-zA-Z0-9-]*)/', $content, $matches);
        foreach ( $matches[1] as $tagName ) {
            if ( ! RichTextInlineTags::isAllowed($tagName) ) {
                return false;
            }
        }

        return true;
    }
}
