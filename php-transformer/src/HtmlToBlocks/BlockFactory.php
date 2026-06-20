<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks;

/**
 * @internal Block construction is owned by HtmlTransformer.
 */
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
            return '<h' . $level . $this->blockSupportAttrs($attrs) . '>' . ($attrs['content'] ?? '') . '</h' . $level . '>';
        }

        if ( 'core/paragraph' === $name ) {
            return '<p' . $this->blockSupportAttrs($attrs) . '>' . ($attrs['content'] ?? '') . '</p>';
        }

        if ( 'core/list-item' === $name ) {
            return '<li' . $this->blockSupportAttrs($attrs) . '>' . ($attrs['content'] ?? '') . '</li>';
        }

        if ( 'core/list' === $name ) {
            $tagName = ! empty($attrs['ordered']) ? 'ol' : 'ul';
            return array( 'opening' => '<' . $tagName . $this->blockSupportAttrs($attrs) . '>', 'closing' => '</' . $tagName . '>' );
        }

        if ( 'core/quote' === $name ) {
            $closing = '' !== ($attrs['citation'] ?? '') ? '<cite>' . $attrs['citation'] . '</cite></blockquote>' : '</blockquote>';
            return array( 'opening' => '<blockquote' . $this->blockSupportAttrs($attrs, 'wp-block-quote') . '>', 'closing' => $closing );
        }

        if ( 'core/pullquote' === $name ) {
            $citation = '' !== ($attrs['citation'] ?? '') ? '<cite>' . $attrs['citation'] . '</cite>' : '';
            return '<figure' . $this->blockSupportAttrs($attrs, 'wp-block-pullquote') . '><blockquote>' . ($attrs['value'] ?? '') . $citation . '</blockquote></figure>';
        }

        if ( 'core/code' === $name ) {
            return '<pre class="wp-block-code"><code>' . htmlspecialchars((string) ($attrs['content'] ?? ''), ENT_NOQUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</code></pre>';
        }

        if ( 'core/preformatted' === $name ) {
            return '<pre' . $this->blockSupportAttrs($attrs, 'wp-block-preformatted') . '>' . ($attrs['content'] ?? '') . '</pre>';
        }

        if ( 'core/table' === $name ) {
            return $this->tableHtml($attrs);
        }

        if ( 'core/details' === $name ) {
            return array(
                'opening' => '<details' . $this->blockSupportAttrs($attrs, 'wp-block-details') . '><summary>' . ($attrs['summary'] ?? '') . '</summary>',
                'closing' => '</details>',
            );
        }

        if ( 'core/image' === $name ) {
            return $this->imageHtml($attrs);
        }

        if ( 'core/gallery' === $name ) {
            $caption = ! empty($attrs['caption']) ? '<figcaption class="blocks-gallery-caption wp-element-caption">' . $attrs['caption'] . '</figcaption>' : '';
            return array( 'opening' => '<figure' . $this->blockSupportAttrs($attrs, 'wp-block-gallery') . '>', 'closing' => $caption . '</figure>' );
        }

        if ( 'core/embed' === $name ) {
            return $this->embedHtml($attrs);
        }

        if ( 'core/buttons' === $name ) {
            return array( 'opening' => '<div' . $this->blockSupportAttrs($attrs, 'wp-block-buttons') . '>', 'closing' => '</div>' );
        }

        if ( 'core/button' === $name ) {
            $href = '' !== ($attrs['url'] ?? '') ? ' href="' . htmlspecialchars((string) $attrs['url'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '"' : '';
            return '<div class="wp-block-button"><a class="wp-block-button__link wp-element-button"' . $href . '>' . ($attrs['text'] ?? '') . '</a></div>';
        }

        if ( 'core/navigation' === $name ) {
            return array( 'opening' => '<nav' . $this->blockSupportAttrs($attrs, 'wp-block-navigation') . '><ul class="wp-block-navigation__container">', 'closing' => '</ul></nav>' );
        }

        if ( 'core/navigation-link' === $name ) {
            $href = '' !== ($attrs['url'] ?? '') ? ' href="' . htmlspecialchars((string) $attrs['url'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '"' : '';
            return '<li' . $this->blockSupportAttrs($attrs, 'wp-block-navigation-item wp-block-navigation-link') . '><a class="wp-block-navigation-item__content"' . $href . '><span class="wp-block-navigation-item__label">' . ($attrs['label'] ?? '') . '</span></a></li>';
        }

        if ( 'core/shortcode' === $name ) {
            return '<div class="wp-block-shortcode">' . ($attrs['text'] ?? '') . '</div>';
        }

        if ( 'core/group' === $name ) {
            return array( 'opening' => '<div' . $this->blockSupportAttrs($attrs, 'wp-block-group') . '>', 'closing' => '</div>' );
        }

        return '';
    }

    /**
     * @param array<string, mixed> $attrs
     */
    private function tableHtml(array $attrs): string
    {
        $html = '<figure' . $this->blockSupportAttrs($attrs, 'wp-block-table') . '><table>';
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
        $figureAttrs = $attrs;
        if ( ! empty($attrs['sizeSlug']) ) {
            $figureAttrs['className'] = $this->mergeClassNames((string) ($figureAttrs['className'] ?? ''), 'size-' . (string) $attrs['sizeSlug']);
        }

        $imageAttrs = array(
            'src'    => $attrs['url'] ?? '',
            'alt'    => $attrs['alt'] ?? '',
            'title'  => $attrs['title'] ?? '',
            'srcset' => $attrs['srcset'] ?? '',
            'sizes'  => $attrs['sizes'] ?? '',
            'width'  => (string) ($attrs['width'] ?? ''),
            'height' => (string) ($attrs['height'] ?? ''),
            'class'  => ! empty($attrs['id']) ? 'wp-image-' . (string) $attrs['id'] : '',
        );

        $img = '<img' . $this->htmlAttrs($imageAttrs, array( 'alt' )) . '/>';
        $caption = ! empty($attrs['caption']) ? '<figcaption class="wp-element-caption">' . $attrs['caption'] . '</figcaption>' : '';
        return '<figure' . $this->blockSupportAttrs($figureAttrs, 'wp-block-image') . '>' . $img . $caption . '</figure>';
    }

    /**
     * @param array<string, mixed> $attrs
     */
    private function embedHtml(array $attrs): string
    {
        $url = htmlspecialchars((string) ($attrs['url'] ?? ''), ENT_NOQUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $classes = array('wp-block-embed');
        if ( ! empty($attrs['type']) ) {
            $classes[] = 'is-type-' . (string) $attrs['type'];
        }
        if ( ! empty($attrs['providerNameSlug']) ) {
            $classes[] = 'is-provider-' . (string) $attrs['providerNameSlug'];
            $classes[] = 'wp-block-embed-' . (string) $attrs['providerNameSlug'];
        }

        $figureAttrs = $attrs;
        $figureAttrs['className'] = $this->mergeClassNames(implode(' ', $classes), (string) ($attrs['className'] ?? ''));

        return '<figure' . $this->blockSupportAttrs($figureAttrs) . '><div class="wp-block-embed__wrapper">' . $url . '</div></figure>';
    }

    private function mergeClassNames(string ...$classNames): string
    {
        $classes = array();
        foreach ( $classNames as $className ) {
            foreach ( preg_split('/\s+/', trim($className)) ?: array() as $class ) {
                if ( '' !== $class && ! in_array($class, $classes, true) ) {
                    $classes[] = $class;
                }
            }
        }

        return implode(' ', $classes);
    }

    /**
     * @param array<string, mixed> $attrs
     */
    private function blockSupportAttrs(array $attrs, string $baseClass = ''): string
    {
        $classes = $this->mergeClassNames($baseClass, (string) ($attrs['className'] ?? ''));
        return $this->htmlAttrs(array(
            'class' => $classes,
            'style' => (string) ($attrs['style'] ?? ''),
        ));
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
