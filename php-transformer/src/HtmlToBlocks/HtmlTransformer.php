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
        $blocks    = $this->convertChildren($body, $fallbacks);

        return new TransformerResult(
            blocks: $blocks,
            serializedBlocks: $this->runtime->serializeBlocks($blocks),
            diagnostics: array(
                array(
                    'code'    => 'html_to_blocks_minimal',
                    'message' => 'Converted supported heading, paragraph, and list elements; unsupported top-level elements are reported as fallbacks.',
                    'source'  => self::class,
                ),
            ),
            fallbacks: $fallbacks,
            provenance: $provenance,
            coverage: array(
                array(
                    'supported_blocks' => array( 'core/heading', 'core/paragraph', 'core/list', 'core/list-item' ),
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
    private function convertChildren(DOMNode $parent, array &$fallbacks): array
    {
        $blocks = array();

        foreach ( $parent->childNodes as $child ) {
            if ( XML_TEXT_NODE === $child->nodeType ) {
                $text = trim($child->textContent ?? '');
                if ( '' !== $text ) {
                    $blocks[] = $this->createBlock('core/paragraph', array( 'content' => htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ));
                }
                continue;
            }

            if ( ! $child instanceof DOMElement ) {
                continue;
            }

            $block = $this->convertElement($child, $fallbacks);
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
    private function convertElement(DOMElement $element, array &$fallbacks): ?array
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
                return null;
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

        if ( in_array($tagName, array( 'article', 'body', 'div', 'main', 'section' ), true) ) {
            $children = $this->convertChildren($element, $fallbacks);
            if ( 1 === count($children) ) {
                return $children[0];
            }
            if ( array() !== $children ) {
                return $this->createBlock('core/group', array(), $children);
            }
            return null;
        }

        $fallbacks[] = array(
            'type' => 'unsupported_element',
            'tag'  => $tagName,
            'html' => $this->outerHtml($element),
        );

        return null;
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
}
