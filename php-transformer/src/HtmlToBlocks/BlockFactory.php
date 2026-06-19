<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks;

final class BlockFactory
{
    /**
     * @param array<string, mixed> $attrs
     * @param array<int, array<string, mixed>> $innerBlocks
     * @return array<string, mixed>
     */
    public function create(string $name, array $attrs = array(), array $innerBlocks = array()): array
    {
        $innerHtml = $this->blockHtml($name, $attrs, $innerBlocks);
        if ( is_array($innerHtml) ) {
            $innerContent = array( $innerHtml['opening'] );
            foreach ( $innerBlocks as $_ ) {
                $innerContent[] = null;
            }
            $innerContent[] = $innerHtml['closing'];
            $innerHtml      = $innerHtml['opening'] . $innerHtml['closing'];
        } else {
            $innerContent = array( $innerHtml );
        }

        return array(
            'blockName'    => $name,
            'attrs'        => $attrs,
            'innerBlocks'  => $innerBlocks,
            'innerHTML'    => $innerHtml,
            'innerContent' => $innerContent,
        );
    }

    /**
     * @param array<string, mixed> $attrs
     * @param array<int, array<string, mixed>> $innerBlocks
     * @return string|array{opening: string, closing: string}
     */
    private function blockHtml(string $name, array $attrs, array $innerBlocks): string|array
    {
        if ( 'core/heading' === $name ) {
            $level = (int) ($attrs['level'] ?? 2);
            $level = max(1, min(6, $level));
            return '<h' . $level . '>' . ($attrs['content'] ?? '') . '</h' . $level . '>';
        }

        if ( 'core/paragraph' === $name ) {
            return '<p>' . ($attrs['content'] ?? '') . '</p>';
        }

        if ( 'core/list-item' === $name ) {
            return '<li>' . ($attrs['content'] ?? '') . '</li>';
        }

        if ( 'core/list' === $name ) {
            $tagName = ! empty($attrs['ordered']) ? 'ol' : 'ul';
            return array( 'opening' => '<' . $tagName . '>', 'closing' => '</' . $tagName . '>' );
        }

        if ( 'core/quote' === $name ) {
            $closing = '' !== ($attrs['citation'] ?? '') ? '<cite>' . $attrs['citation'] . '</cite></blockquote>' : '</blockquote>';
            return array( 'opening' => '<blockquote class="wp-block-quote">', 'closing' => $closing );
        }

        if ( 'core/pullquote' === $name ) {
            $citation = '' !== ($attrs['citation'] ?? '') ? '<cite>' . $attrs['citation'] . '</cite>' : '';
            return '<figure class="wp-block-pullquote"><blockquote>' . ($attrs['value'] ?? '') . $citation . '</blockquote></figure>';
        }

        if ( 'core/code' === $name ) {
            return '<pre class="wp-block-code"><code>' . htmlspecialchars((string) ($attrs['content'] ?? ''), ENT_NOQUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</code></pre>';
        }

        if ( 'core/preformatted' === $name ) {
            return '<pre class="wp-block-preformatted">' . ($attrs['content'] ?? '') . '</pre>';
        }

        if ( 'core/table' === $name ) {
            return $this->tableHtml($attrs);
        }

        if ( 'core/image' === $name ) {
            return $this->imageHtml($attrs);
        }

        if ( 'core/buttons' === $name ) {
            return array( 'opening' => '<div class="wp-block-buttons">', 'closing' => '</div>' );
        }

        if ( 'core/button' === $name ) {
            $href = '' !== ($attrs['url'] ?? '') ? ' href="' . htmlspecialchars((string) $attrs['url'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '"' : '';
            return '<div class="wp-block-button"><a class="wp-block-button__link wp-element-button"' . $href . '>' . ($attrs['text'] ?? '') . '</a></div>';
        }

        if ( 'core/shortcode' === $name ) {
            return '<div class="wp-block-shortcode">' . ($attrs['text'] ?? '') . '</div>';
        }

        if ( 'core/group' === $name ) {
            return array( 'opening' => '<div class="wp-block-group">', 'closing' => '</div>' );
        }

        return '';
    }

    /**
     * @param array<string, mixed> $attrs
     */
    private function tableHtml(array $attrs): string
    {
        $html = '<figure class="wp-block-table"><table>';
        foreach ( array( 'head' => 'thead', 'body' => 'tbody', 'foot' => 'tfoot' ) as $attrName => $tagName ) {
            if ( empty($attrs[$attrName]) || ! is_array($attrs[$attrName]) ) {
                continue;
            }
            $html .= '<' . $tagName . '>';
            foreach ( $attrs[$attrName] as $row ) {
                $html .= '<tr>';
                foreach ( $row['cells'] ?? array() as $cell ) {
                    $cellTag = 'th' === ($cell['tag'] ?? '') ? 'th' : 'td';
                    $html .= '<' . $cellTag . '>' . ($cell['content'] ?? '') . '</' . $cellTag . '>';
                }
                $html .= '</tr>';
            }
            $html .= '</' . $tagName . '>';
        }
        $html .= '</table>';
        if ( ! empty($attrs['caption']) ) {
            $html .= '<figcaption class="wp-element-caption">' . $attrs['caption'] . '</figcaption>';
        }
        return $html . '</figure>';
    }

    /**
     * @param array<string, mixed> $attrs
     */
    private function imageHtml(array $attrs): string
    {
        $imageAttrs = array(
            'src'    => $attrs['url'] ?? '',
            'alt'    => $attrs['alt'] ?? '',
            'title'  => $attrs['title'] ?? '',
            'srcset' => $attrs['srcset'] ?? '',
            'sizes'  => $attrs['sizes'] ?? '',
            'width'  => $attrs['width'] ?? '',
            'height' => $attrs['height'] ?? '',
        );

        $img = '<img' . $this->htmlAttrs($imageAttrs, array( 'alt' )) . '/>';
        $caption = ! empty($attrs['caption']) ? '<figcaption class="wp-element-caption">' . $attrs['caption'] . '</figcaption>' : '';
        return '<figure class="wp-block-image">' . $img . $caption . '</figure>';
    }

    /**
     * @param array<string, string> $attrs
     * @param array<int, string> $includeEmpty
     */
    private function htmlAttrs(array $attrs, array $includeEmpty = array()): string
    {
        $html = '';
        foreach ( $attrs as $name => $value ) {
            if ( '' === $value && ! in_array($name, $includeEmpty, true) ) {
                continue;
            }
            $html .= ' ' . $name . '="' . htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '"';
        }
        return $html;
    }
}
