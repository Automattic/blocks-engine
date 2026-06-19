<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks;

use Automattic\BlocksEngine\PhpTransformer\Contract\TransformerResult;
use Automattic\BlocksEngine\PhpTransformer\WordPress\Runtime;
use DOMDocument;
use DOMElement;
use DOMNode;

final class HtmlTransformer
{
    public function __construct(private readonly Runtime $runtime = new Runtime())
    {
    }

    public function transform(string $html): TransformerResult
    {
        $provenance = array(
            array(
                'source_format' => 'html',
                'input_bytes'   => strlen($html),
                'transformer'   => self::class,
            ),
        );

        $document = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $loaded   = $document->loadHTML('<?xml encoding="utf-8" ?><body>' . $html . '</body>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if ( ! $loaded ) {
            return new TransformerResult(
                diagnostics: array(
                    array(
                        'code'    => 'html_parse_failed',
                        'message' => 'Unable to parse HTML input.',
                        'source'  => self::class,
                    ),
                ),
                fallbacks: array(
                    array(
                        'type' => 'html',
                        'html' => $html,
                    ),
                ),
                provenance: $provenance
            );
        }

        $body = $document->getElementsByTagName('body')->item(0);
        if ( ! $body instanceof DOMElement ) {
            return new TransformerResult(provenance: $provenance);
        }

        $fallbacks = array();
        $blocks    = $this->convertChildren($body, $fallbacks, true);

        return new TransformerResult(
            blocks: $blocks,
            serializedBlocks: $this->runtime->serializeBlocks($blocks),
            diagnostics: array(
                array(
                    'code'    => 'html_to_blocks_core_slice',
                    'message' => 'Converted supported core text, media, table, button, and shortcode elements; unsupported elements are reported as fallbacks.',
                    'source'  => self::class,
                ),
            ),
            fallbacks: $fallbacks,
            provenance: $provenance,
            coverage: array(
                array(
                    'supported_blocks' => array( 'core/button', 'core/buttons', 'core/code', 'core/heading', 'core/image', 'core/list', 'core/list-item', 'core/paragraph', 'core/preformatted', 'core/pullquote', 'core/quote', 'core/shortcode', 'core/table' ),
                    'block_count'      => count($blocks),
                    'fallback_count'   => count($fallbacks),
                ),
            )
        );
    }

    /**
     * @param array<int, array<string, mixed>> $fallbacks
     * @return array<int, array<string, mixed>>
     */
    private function convertChildren(DOMNode $parent, array &$fallbacks, bool $captureUnsupported = false): array
    {
        $blocks = array();

        foreach ( $parent->childNodes as $child ) {
            if ( XML_TEXT_NODE === $child->nodeType ) {
                $text = trim($child->textContent ?? '');
                if ( '' !== $text ) {
                    $blocks = array_merge($blocks, $this->convertText($text));
                }
                continue;
            }

            if ( ! $child instanceof DOMElement ) {
                continue;
            }

            $block = $this->convertElement($child, $fallbacks, $captureUnsupported);
            if ( null !== $block ) {
                $blocks[] = $block;
            }
        }

        return $blocks;
    }

    /**
     * @param array<int, array<string, mixed>> $fallbacks
     * @return array<string, mixed>|null
     */
    private function convertElement(DOMElement $element, array &$fallbacks, bool $captureUnsupported = false): ?array
    {
        $tagName = strtolower($element->tagName);

        if ( preg_match('/^h([1-6])$/', $tagName, $matches) ) {
            $content = $this->innerHtml($element);
            if ( '' === trim(strip_tags($content)) ) {
                return null;
            }

            return $this->createBlock('core/heading', array(
                'content' => $content,
                'level'   => (int) $matches[1],
            ));
        }

        if ( 'p' === $tagName ) {
            $content = $this->innerHtml($element);
            if ( '' === trim(strip_tags($content)) ) {
                $textBlocks = $this->convertText(trim($element->textContent ?? ''));
                return $textBlocks[0] ?? null;
            }

            return $this->createBlock('core/paragraph', array( 'content' => $content ));
        }

        if ( 'ul' === $tagName || 'ol' === $tagName ) {
            $items = array();
            foreach ( $element->childNodes as $child ) {
                if ( $child instanceof DOMElement && 'li' === strtolower($child->tagName) ) {
                    $items[] = $this->createBlock('core/list-item', array( 'content' => $this->innerHtml($child) ));
                }
            }

            if ( array() === $items ) {
                return null;
            }

            return $this->createBlock('core/list', 'ol' === $tagName ? array( 'ordered' => true ) : array(), $items);
        }

        if ( 'blockquote' === $tagName ) {
            $citation = '';
            foreach ( $element->childNodes as $child ) {
                if ( $child instanceof DOMElement && in_array(strtolower($child->tagName), array( 'cite', 'footer' ), true) ) {
                    $citation = $this->innerHtml($child);
                }
            }

            $value = $this->innerHtmlWithoutTags($element, array( 'cite', 'footer' ));
            if ( '' === trim(strip_tags($value)) ) {
                return null;
            }

            if ( $this->hasClass($element, 'wp-block-pullquote') || $this->closestTagName($element) === 'figure' ) {
                return $this->createBlock('core/pullquote', array_filter(array(
                    'value'    => $value,
                    'citation' => $citation,
                ), static fn ($value): bool => '' !== $value));
            }

            $innerBlocks = $this->convertChildren($element, $fallbacks);
            if ( array() === $innerBlocks ) {
                $innerBlocks[] = $this->createBlock('core/paragraph', array( 'content' => $value ));
            }

            return $this->createBlock('core/quote', array_filter(array( 'citation' => $citation ), static fn ($value): bool => '' !== $value), $innerBlocks);
        }

        if ( 'figure' === $tagName ) {
            $image = $this->firstChildElement($element, 'img');
            if ( $image instanceof DOMElement ) {
                return $this->convertImageElement($image, $element);
            }

            $blockquote = $this->firstChildElement($element, 'blockquote');
            if ( $blockquote instanceof DOMElement ) {
                return $this->convertElement($blockquote, $fallbacks, $captureUnsupported);
            }
        }

        if ( 'pre' === $tagName ) {
            $code = $this->firstChildElement($element, 'code');
            if ( $code instanceof DOMElement ) {
                return $this->createBlock('core/code', array( 'content' => $code->textContent ?? '' ));
            }

            return $this->createBlock('core/preformatted', array( 'content' => $this->innerHtml($element) ));
        }

        if ( 'table' === $tagName ) {
            return $this->createBlock('core/table', $this->tableAttributes($element));
        }

        if ( 'img' === $tagName ) {
            return $this->convertImageElement($element);
        }

        if ( 'a' === $tagName && '' !== trim($element->textContent ?? '') ) {
            return $this->createBlock('core/buttons', array(), array( $this->buttonBlockFromAnchor($element) ));
        }

        if ( 'button' === $tagName ) {
            return $this->createBlock('core/buttons', array(), array( $this->createBlock('core/button', array( 'text' => $this->innerHtml($element) )) ));
        }

        if ( in_array($tagName, array( 'article', 'body', 'div', 'main', 'section' ), true) ) {
            $buttonChildren = $this->buttonChildren($element);
            if ( array() !== $buttonChildren ) {
                return $this->createBlock('core/buttons', array(), $buttonChildren);
            }

            $children = $this->convertChildren($element, $fallbacks, true);
            if ( 1 === count($children) ) {
                return $children[0];
            }
            if ( array() !== $children ) {
                return $this->createBlock('core/group', array(), $children);
            }
            return null;
        }

        if ( $captureUnsupported ) {
            $fallbacks[] = array(
                'type' => 'unsupported_element',
                'tag'  => $tagName,
                'html' => $this->outerHtml($element),
            );
        }

        return null;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function convertText(string $text): array
    {
        $blocks = array();
        if ( preg_match('/^\[[A-Za-z][A-Za-z0-9_-]*(?:\s[^\]]*)?\](?:.*\[\/[A-Za-z][A-Za-z0-9_-]*\])?$/s', trim($text)) ) {
            $blocks[] = $this->createBlock('core/shortcode', array( 'text' => trim($text) ));
            return $blocks;
        }

        $blocks[] = $this->createBlock('core/paragraph', array( 'content' => htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ));
        return $blocks;
    }

    /**
     * @param array<string, mixed> $attrs
     * @param array<int, array<string, mixed>> $innerBlocks
     * @return array<string, mixed>
     */
    private function createBlock(string $name, array $attrs = array(), array $innerBlocks = array()): array
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

    private function innerHtml(DOMElement $element): string
    {
        $html = '';
        foreach ( $element->childNodes as $child ) {
            $html .= $element->ownerDocument->saveHTML($child);
        }

        return trim($html);
    }

    private function outerHtml(DOMElement $element): string
    {
        return trim($element->ownerDocument->saveHTML($element) ?: '');
    }

    private function attr(DOMElement $element, string $name): string
    {
        return $element->hasAttribute($name) ? $element->getAttribute($name) : '';
    }

    private function hasClass(DOMElement $element, string $className): bool
    {
        return in_array($className, preg_split('/\s+/', trim($this->attr($element, 'class'))) ?: array(), true);
    }

    private function closestTagName(DOMElement $element): ?string
    {
        return $element->parentNode instanceof DOMElement ? strtolower($element->parentNode->tagName) : null;
    }

    private function firstChildElement(DOMElement $element, string $tagName): ?DOMElement
    {
        foreach ( $element->childNodes as $child ) {
            if ( $child instanceof DOMElement && strtolower($child->tagName) === $tagName ) {
                return $child;
            }
        }
        return null;
    }

    /**
     * @param array<int, string> $excludedTags
     */
    private function innerHtmlWithoutTags(DOMElement $element, array $excludedTags): string
    {
        $html = '';
        foreach ( $element->childNodes as $child ) {
            if ( $child instanceof DOMElement && in_array(strtolower($child->tagName), $excludedTags, true) ) {
                continue;
            }
            $html .= $element->ownerDocument->saveHTML($child);
        }
        return trim($html);
    }

    /**
     * @return array<string, mixed>
     */
    private function tableAttributes(DOMElement $table): array
    {
        $attrs = array();
        foreach ( array( 'thead' => 'head', 'tbody' => 'body', 'tfoot' => 'foot' ) as $sectionTag => $attrName ) {
            $rows = array();
            foreach ( $table->getElementsByTagName($sectionTag) as $section ) {
                foreach ( $section->getElementsByTagName('tr') as $row ) {
                    $rows[] = array( 'cells' => $this->tableCells($row) );
                }
            }
            if ( array() !== $rows ) {
                $attrs[$attrName] = $rows;
            }
        }

        if ( empty($attrs['body']) ) {
            $rows = array();
            foreach ( $table->getElementsByTagName('tr') as $row ) {
                if ( in_array($this->closestTagName($row), array( 'thead', 'tfoot' ), true) ) {
                    continue;
                }
                $rows[] = array( 'cells' => $this->tableCells($row) );
            }
            if ( array() !== $rows ) {
                $attrs['body'] = $rows;
            }
        }

        $caption = $this->firstChildElement($table, 'caption');
        if ( $caption instanceof DOMElement ) {
            $attrs['caption'] = $this->innerHtml($caption);
        }

        return $attrs;
    }

    /**
     * @return array<int, array<string, string>>
     */
    private function tableCells(DOMElement $row): array
    {
        $cells = array();
        foreach ( $row->childNodes as $cell ) {
            if ( ! $cell instanceof DOMElement || ! in_array(strtolower($cell->tagName), array( 'td', 'th' ), true) ) {
                continue;
            }
            $cells[] = array(
                'content' => $this->innerHtml($cell),
                'tag'     => strtolower($cell->tagName),
            );
        }
        return $cells;
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

    private function convertImageElement(DOMElement $image, ?DOMElement $figure = null): array
    {
        $attrs = array_filter(array(
            'url'    => $this->attr($image, 'src'),
            'alt'    => $this->attr($image, 'alt'),
            'title'  => $this->attr($image, 'title'),
            'srcset' => $this->attr($image, 'srcset'),
            'sizes'  => $this->attr($image, 'sizes'),
            'width'  => $this->attr($image, 'width'),
            'height' => $this->attr($image, 'height'),
        ), static fn ($value): bool => '' !== $value);

        if ( $figure instanceof DOMElement ) {
            $caption = $this->firstChildElement($figure, 'figcaption');
            if ( $caption instanceof DOMElement ) {
                $attrs['caption'] = $this->innerHtml($caption);
            }
        }

        return $this->createBlock('core/image', $attrs);
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

    private function buttonBlockFromAnchor(DOMElement $anchor): array
    {
        return $this->createBlock('core/button', array_filter(array(
            'text' => $this->innerHtml($anchor),
            'url'  => $this->attr($anchor, 'href'),
        ), static fn ($value): bool => '' !== $value));
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function buttonChildren(DOMElement $element): array
    {
        $buttons = array();
        foreach ( $element->childNodes as $child ) {
            if ( $child instanceof DOMElement && 'a' === strtolower($child->tagName) && '' !== trim($child->textContent ?? '') ) {
                $buttons[] = $this->buttonBlockFromAnchor($child);
            }
        }
        return 1 < count($buttons) ? $buttons : array();
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
