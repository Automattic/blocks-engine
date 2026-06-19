<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks;

use Automattic\BlocksEngine\PhpTransformer\Contract\TransformationOptions;
use Automattic\BlocksEngine\PhpTransformer\Contract\TransformerResult;
use Automattic\BlocksEngine\PhpTransformer\WordPress\Runtime;
use DOMDocument;
use DOMElement;
use DOMNode;

final class HtmlTransformer
{
    private readonly BlockFactory $blockFactory;

    /**
     * @var array<string, string>
     */
    private array $fallbackProvenance = array();

    /**
     * @var array<int, array<string, mixed>>
     */
    private array $presentationProvenance = array();

    public function __construct(private readonly Runtime $runtime = new Runtime())
    {
        $this->blockFactory = new BlockFactory();
    }

    /**
     * @param array<string, mixed> $options
     */
    public function transform(string $html, array $options = array()): TransformerResult
    {
        $context                  = TransformationOptions::context($options);
        $startedAt                = hrtime(true);
        $this->fallbackProvenance = TransformationOptions::provenance($options);
        $this->presentationProvenance = array();
        $provenance               = array(
            array_merge(array(
                'source_format' => 'html',
                'input_bytes'   => strlen($html),
                'transformer'   => self::class,
            ), $this->fallbackProvenance),
        );

        $document = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $loaded   = $document->loadHTML('<?xml encoding="utf-8" ?><body>' . $html . '</body>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if ( ! $loaded ) {
            $diagnostics = array(
                array(
                    'code'    => 'html_parse_failed',
                    'message' => 'Unable to parse HTML input.',
                    'source'  => self::class,
                ),
            );
            $fallbacks = array(
                array_merge(array(
                    'type'            => 'html',
                    'reason'          => 'parse_failed',
                    'diagnostic_code' => 'html_parse_failed',
                    'source_format'   => 'html',
                    'html'            => $html,
                ), $this->fallbackProvenance),
            );

            return new TransformerResult(
                diagnostics: $diagnostics,
                fallbacks: $fallbacks,
                provenance: $provenance,
                context: $context,
                metrics: $this->metrics($html, array(), '', $fallbacks, $diagnostics, $startedAt)
            );
        }

        $body = $document->getElementsByTagName('body')->item(0);
        if ( ! $body instanceof DOMElement ) {
            return new TransformerResult(
                provenance: $provenance,
                context: $context,
                metrics: $this->metrics($html, array(), '', array(), array(), $startedAt)
            );
        }

        $fallbacks   = array();
        $blocks      = $this->convertChildren($body, $fallbacks, true);
        $serializedBlocks = $this->runtime->serializeBlocks($blocks);
        $diagnostics = array(
            array(
                'code'    => 'html_to_blocks_core_slice',
                'message' => 'Converted supported core text, media, table, button, shortcode, definition-list, and wrapper elements; unsupported elements are reported as fallbacks.',
                'source'  => self::class,
            ),
        );

        foreach ( $fallbacks as $fallback ) {
            if ( ! empty($fallback['diagnostic_code']) ) {
                $diagnostics[] = array(
                    'code'    => $fallback['diagnostic_code'],
                    'message' => $fallback['message'] ?? 'HTML element preserved as fallback metadata.',
                    'source'  => self::class,
                    'reason'  => $fallback['reason'] ?? null,
                    'tag'     => $fallback['tag'] ?? null,
                    'selector' => $fallback['selector'] ?? null,
                );
            }
        }

        return new TransformerResult(
            status: $this->statusForFallbacks($fallbacks, $context),
            blocks: $blocks,
            serializedBlocks: $serializedBlocks,
            diagnostics: $diagnostics,
            fallbacks: $fallbacks,
            provenance: $provenance,
            sourceReports: array(
                'html' => array(
                    'presentation_signals' => $this->presentationProvenance,
                ),
            ),
            coverage: array(
                array(
                    'supported_blocks' => array( 'core/button', 'core/buttons', 'core/code', 'core/group', 'core/heading', 'core/image', 'core/list', 'core/list-item', 'core/paragraph', 'core/preformatted', 'core/pullquote', 'core/quote', 'core/shortcode', 'core/table' ),
                    'block_count'      => count($blocks),
                    'fallback_count'   => count($fallbacks),
                ),
            ),
            context: $context,
            metrics: $this->metrics($html, $blocks, $serializedBlocks, $fallbacks, $diagnostics, $startedAt)
        );
    }

    /**
     * @param array<int, array<string, mixed>> $blocks
     * @param array<int, array<string, mixed>> $fallbacks
     * @param array<int, array<string, mixed>> $diagnostics
     * @return array<string, int|float>
     */
    private function metrics(string $input, array $blocks, string $output, array $fallbacks, array $diagnostics, int $startedAt): array
    {
        return array(
            'input_bytes'           => strlen($input),
            'block_count'           => $this->countBlocks($blocks),
            'fallback_count'        => count($fallbacks),
            'diagnostic_count'      => count($diagnostics),
            'transform_duration_ms' => (hrtime(true) - $startedAt) / 1000000,
            'output_bytes'          => strlen($output),
        );
    }

    /**
     * @param array<int, array<string, mixed>> $blocks
     */
    private function countBlocks(array $blocks): int
    {
        $count = 0;

        foreach ( $blocks as $block ) {
            ++$count;
            if ( ! empty($block['innerBlocks']) && is_array($block['innerBlocks']) ) {
                $count += $this->countBlocks($block['innerBlocks']);
            }
        }

        return $count;
    }

    /**
     * @param array<int, array<string, mixed>> $fallbacks
     * @param array{strict: bool, allow_fallbacks: bool} $context
     */
    private function statusForFallbacks(array $fallbacks, array $context): string
    {
        if ( array() === $fallbacks || $context['allow_fallbacks'] ) {
            return 'success';
        }

        return $context['strict'] ? 'failed' : 'success_with_warnings';
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
            if ( '' === trim($this->runtime->stripAllTags($content)) ) {
                return null;
            }

            return $this->createBlock('core/heading', array_merge($this->presentationAttributes($element), array(
                'content' => $content,
                'level'   => (int) $matches[1],
            )), array(), $element);
        }

        if ( 'p' === $tagName ) {
            $content = $this->innerHtml($element);
            if ( '' === trim($this->runtime->stripAllTags($content)) ) {
                $textBlocks = $this->convertText(trim($element->textContent ?? ''));
                return $textBlocks[0] ?? null;
            }

            return $this->createBlock('core/paragraph', array_merge($this->presentationAttributes($element), array( 'content' => $content )), array(), $element);
        }

        if ( $this->isInlineContentElement($tagName) ) {
            $content = $this->outerHtml($element);
            if ( '' === trim($this->runtime->stripAllTags($content)) ) {
                return null;
            }

            return $this->createBlock('core/paragraph', array( 'content' => $content ));
        }

        if ( 'ul' === $tagName || 'ol' === $tagName ) {
            $items = array();
            foreach ( $element->childNodes as $child ) {
                if ( $child instanceof DOMElement && 'li' === strtolower($child->tagName) ) {
                    $items[] = $this->createBlock('core/list-item', array_merge($this->presentationAttributes($child), array( 'content' => $this->innerHtml($child) )), array(), $child);
                }
            }

            if ( array() === $items ) {
                return null;
            }

            return $this->createBlock('core/list', array_merge($this->presentationAttributes($element), 'ol' === $tagName ? array( 'ordered' => true ) : array()), $items, $element);
        }

        if ( 'dl' === $tagName ) {
            $items = $this->definitionListItems($element);
            if ( array() === $items ) {
                return null;
            }

            return $this->createBlock('core/list', $this->presentationAttributes($element), $items, $element);
        }

        if ( 'blockquote' === $tagName ) {
            $citation = '';
            foreach ( $element->childNodes as $child ) {
                if ( $child instanceof DOMElement && in_array(strtolower($child->tagName), array( 'cite', 'footer' ), true) ) {
                    $citation = $this->innerHtml($child);
                }
            }

            $value = $this->innerHtmlWithoutTags($element, array( 'cite', 'footer' ));
            if ( '' === trim($this->runtime->stripAllTags($value)) ) {
                return null;
            }

            if ( $this->hasClass($element, 'wp-block-pullquote') || $this->closestTagName($element) === 'figure' ) {
                return $this->createBlock('core/pullquote', array_filter(array_merge($this->presentationAttributes($element), array(
                    'value'    => $value,
                    'citation' => $citation,
                )), static fn ($value): bool => '' !== $value), array(), $element);
            }

            $innerBlocks = $this->convertChildren($element, $fallbacks);
            if ( array() === $innerBlocks ) {
                $innerBlocks[] = $this->createBlock('core/paragraph', array( 'content' => $value ));
            }

            return $this->createBlock('core/quote', array_filter(array_merge($this->presentationAttributes($element), array( 'citation' => $citation )), static fn ($value): bool => '' !== $value), $innerBlocks, $element);
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
                return $this->createBlock('core/code', array_merge($this->presentationAttributes($element), array( 'content' => $code->textContent ?? '' )), array(), $element);
            }

            return $this->createBlock('core/preformatted', array_merge($this->presentationAttributes($element), array( 'content' => $this->innerHtml($element) )), array(), $element);
        }

        if ( 'table' === $tagName ) {
            return $this->createBlock('core/table', array_merge($this->presentationAttributes($element), $this->tableAttributes($element)), array(), $element);
        }

        if ( 'img' === $tagName ) {
            return $this->convertImageElement($element);
        }

        if ( 'a' === $tagName && '' !== trim($element->textContent ?? '') ) {
            return $this->createBlock('core/buttons', array(), array( $this->buttonBlockFromAnchor($element) ), $element);
        }

        if ( 'button' === $tagName ) {
            return $this->createBlock('core/buttons', array(), array( $this->createBlock('core/button', array_merge($this->presentationAttributes($element), array( 'text' => $this->innerHtml($element) )), array(), $element) ), $element);
        }

        if ( 'form' === $tagName ) {
            $fallbacks[] = array_merge(array(
                'type'            => 'html',
                'reason'          => 'form_requires_runtime',
                'diagnostic_code' => 'html_form_fallback',
                'message'         => 'Form HTML requires runtime behavior and was preserved as safe fallback metadata.',
                'source_format'   => 'html',
                'tag'             => $tagName,
                'selector'        => $this->elementSelector($element),
                'attributes'      => $this->htmlAttributes($element),
                'text_length'     => strlen(trim($element->textContent ?? '')),
                'child_count'     => $this->childElementCount($element),
                'html'            => $this->safeFallbackHtml($element),
            ), $this->fallbackProvenance);
            return null;
        }

        if ( in_array($tagName, array( 'article', 'body', 'div', 'main', 'section' ), true) ) {
            $buttonChildren = $this->buttonChildren($element);
            if ( array() !== $buttonChildren ) {
                return $this->createBlock('core/buttons', $this->presentationAttributes($element), $buttonChildren, $element);
            }

            $children = $this->convertChildren($element, $fallbacks, true);
            if ( 1 === count($children) ) {
                if ( $this->shouldPreserveWrapper($element) && 'core/group' !== ($children[0]['blockName'] ?? '') ) {
                    return $this->createBlock('core/group', $this->presentationAttributes($element), $children, $element);
                }
                return $children[0];
            }
            if ( array() !== $children ) {
                return $this->createBlock('core/group', $this->presentationAttributes($element), $children, $element);
            }
            return null;
        }

        if ( $captureUnsupported ) {
            $fallbacks[] = array_merge(array(
                'type'            => 'unsupported_element',
                'reason'          => 'unsupported_element',
                'diagnostic_code' => 'html_unsupported_element',
                'source_format'   => 'html',
                'tag'             => $tagName,
                'selector'        => $this->elementSelector($element),
                'attributes'      => $this->htmlAttributes($element),
                'text_length'     => strlen(trim($element->textContent ?? '')),
                'child_count'     => $this->childElementCount($element),
                'html'            => $this->safeFallbackHtml($element),
            ), $this->fallbackProvenance);
        }

        return null;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function convertText(string $text): array
    {
        $blocks = array();
        if ( $this->runtime->isShortcodeOnly($text) ) {
            $blocks[] = $this->createBlock('core/shortcode', array( 'text' => $this->runtime->preserveShortcodeText($text) ));
            return $blocks;
        }

        $blocks[] = $this->createBlock('core/paragraph', array( 'content' => $this->runtime->escapeHtml($text) ));
        return $blocks;
    }

    /**
     * @param array<string, mixed> $attrs
     * @param array<int, array<string, mixed>> $innerBlocks
     * @return array<string, mixed>
     */
    private function createBlock(string $name, array $attrs = array(), array $innerBlocks = array(), ?DOMElement $sourceElement = null): array
    {
        if ( $sourceElement instanceof DOMElement ) {
            $this->recordPresentationProvenance($name, $attrs, $sourceElement);
        }

        return $this->blockFactory->create($name, $attrs, $innerBlocks);
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

    /**
     * @return array<string, mixed>
     */
    private function presentationAttributes(DOMElement $element): array
    {
        return array_filter(array(
            'className' => $this->attr($element, 'class'),
            'style'     => $this->attr($element, 'style'),
            'layout'    => $this->layoutAttribute($element),
        ), static fn ($value): bool => is_array($value) ? array() !== $value : '' !== trim((string) $value));
    }

    /**
     * @return array<string, string>
     */
    private function layoutAttribute(DOMElement $element): array
    {
        $declared = trim($this->attr($element, 'data-layout'));
        if ( '' === $declared ) {
            $declared = trim($this->attr($element, 'data-wp-layout'));
        }

        if ( '' !== $declared ) {
            $decoded = json_decode($declared, true);
            $type = is_array($decoded) ? (string) ($decoded['type'] ?? '') : $declared;
            if ( in_array($type, array( 'constrained', 'flex', 'flow', 'grid' ), true) ) {
                return array( 'type' => $type );
            }
        }

        $style = strtolower($this->attr($element, 'style'));
        if ( preg_match('/(?:^|;)\s*display\s*:\s*(inline-)?flex\b/', $style) ) {
            return array( 'type' => 'flex' );
        }
        if ( preg_match('/(?:^|;)\s*display\s*:\s*(inline-)?grid\b/', $style) ) {
            return array( 'type' => 'grid' );
        }

        return array();
    }

    /**
     * @param array<string, mixed> $attrs
     */
    private function recordPresentationProvenance(string $blockName, array $attrs, DOMElement $element): void
    {
        $signals = array_intersect_key($attrs, array_flip(array( 'className', 'style', 'layout' )));
        $signals = array_filter($signals, static fn ($value): bool => is_array($value) ? array() !== $value : '' !== trim((string) $value));
        if ( array() === $signals ) {
            return;
        }

        $this->presentationProvenance[] = array(
            'block_name'        => $blockName,
            'tag'               => strtolower($element->tagName),
            'selector'          => $this->elementSelector($element),
            'signals'           => $signals,
            'source_attributes' => array_intersect_key($this->htmlAttributes($element), array_flip(array( 'class', 'style', 'data-layout', 'data-wp-layout' ))),
        );
    }

    private function shouldPreserveWrapper(DOMElement $element): bool
    {
        return in_array(strtolower($element->tagName), array( 'article', 'div', 'main', 'section' ), true) && array() !== $this->presentationAttributes($element);
    }

    private function isInlineContentElement(string $tagName): bool
    {
        return in_array($tagName, array( 'abbr', 'b', 'cite', 'code', 'em', 'i', 'mark', 'small', 'span', 'strong', 'sub', 'sup', 'time' ), true);
    }

    private function hasClass(DOMElement $element, string $className): bool
    {
        return in_array($className, preg_split('/\s+/', trim($this->attr($element, 'class'))) ?: array(), true);
    }

    private function elementSelector(DOMElement $element): string
    {
        $parts = array();
        $current = $element;
        while ( $current instanceof DOMElement && 'body' !== strtolower($current->tagName) ) {
            $tagName = strtolower($current->tagName);
            $index = 1;
            for ( $sibling = $current->previousSibling; $sibling instanceof DOMNode; $sibling = $sibling->previousSibling ) {
                if ( $sibling instanceof DOMElement && strtolower($sibling->tagName) === $tagName ) {
                    ++$index;
                }
            }
            array_unshift($parts, $tagName . ':nth-of-type(' . $index . ')');
            $current = $current->parentNode instanceof DOMElement ? $current->parentNode : null;
        }

        return implode(' > ', $parts);
    }

    /**
     * @return array<string, string>
     */
    private function htmlAttributes(DOMElement $element): array
    {
        $attributes = array();
        foreach ( $element->attributes ?? array() as $attribute ) {
            $attributes[$attribute->nodeName] = $attribute->nodeValue ?? '';
        }

        ksort($attributes);
        return $attributes;
    }

    private function childElementCount(DOMElement $element): int
    {
        $count = 0;
        foreach ( $element->childNodes as $child ) {
            if ( $child instanceof DOMElement ) {
                ++$count;
            }
        }

        return $count;
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
     * @return array<int, array<string, mixed>>
     */
    private function definitionListItems(DOMElement $list): array
    {
        $items = array();
        $term = '';

        foreach ( $list->childNodes as $child ) {
            if ( ! $child instanceof DOMElement ) {
                continue;
            }

            $tagName = strtolower($child->tagName);
            if ( 'dt' === $tagName ) {
                $term = $this->innerHtml($child);
                continue;
            }

            if ( 'dd' === $tagName ) {
                $description = $this->innerHtml($child);
                if ( '' === trim($this->runtime->stripAllTags($term . $description)) ) {
                    continue;
                }

                $prefix = '' !== trim($term) ? '<strong>' . $term . '</strong>' : '';
                $items[] = $this->createBlock('core/list-item', array_merge($this->presentationAttributes($child), array(
                    'content' => trim($prefix . ( '' !== $prefix && '' !== trim($description) ? ' ' : '' ) . $description),
                )), array(), $child);
            }
        }

        return $items;
    }

    private function safeFallbackHtml(DOMElement $element): string
    {
        return trim(preg_replace('@<(script|style)[^>]*?>.*?</\\1>@si', '', $this->outerHtml($element)) ?? '');
    }

    private function convertImageElement(DOMElement $image, ?DOMElement $figure = null): array
    {
        $attrs = array_filter(array_merge($this->presentationAttributes($figure ?? $image), array(
            'url'    => $this->attr($image, 'src'),
            'alt'    => $this->attr($image, 'alt'),
            'title'  => $this->attr($image, 'title'),
            'srcset' => $this->attr($image, 'srcset'),
            'sizes'  => $this->attr($image, 'sizes'),
            'width'  => $this->attr($image, 'width'),
            'height' => $this->attr($image, 'height'),
        )), static fn ($value): bool => '' !== $value);

        if ( $figure instanceof DOMElement ) {
            $caption = $this->firstChildElement($figure, 'figcaption');
            if ( $caption instanceof DOMElement ) {
                $attrs['caption'] = $this->innerHtml($caption);
            }
        }

        return $this->createBlock('core/image', $attrs, array(), $figure ?? $image);
    }

    private function buttonBlockFromAnchor(DOMElement $anchor): array
    {
        return $this->createBlock('core/button', array_filter(array_merge($this->presentationAttributes($anchor), array(
            'text' => $this->innerHtml($anchor),
            'url'  => $this->attr($anchor, 'href'),
        )), static fn ($value): bool => is_array($value) ? array() !== $value : '' !== $value), array(), $anchor);
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

}
