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

    /**
     * @var array<int, array<string, mixed>>
     */
    private array $sourceProvenance = array();

    private int $nextSourceProvenanceId = 1;

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
        $this->sourceProvenance = array();
        $this->nextSourceProvenanceId = 1;
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
        $sourceProvenance = $this->sourceProvenanceForBlocks($blocks);
        $serializedBlocks = $this->runtime->serializeBlocks($blocks);
        $diagnostics = array(
            array(
                'code'    => 'html_to_blocks_core_slice',
                'message' => 'Converted supported core text, media, table, button, shortcode, definition-list, details, navigation, and wrapper elements; unsupported elements are reported as fallbacks.',
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
                    'source_provenance'    => $sourceProvenance,
                ),
            ),
            coverage: array(
                array(
                    'supported_blocks' => array( 'core/button', 'core/buttons', 'core/code', 'core/details', 'core/group', 'core/heading', 'core/image', 'core/list', 'core/list-item', 'core/navigation', 'core/navigation-link', 'core/paragraph', 'core/preformatted', 'core/pullquote', 'core/quote', 'core/shortcode', 'core/table' ),
                    'block_count'      => count($blocks),
                    'fallback_count'   => count($fallbacks),
                    'source_provenance_count' => count($sourceProvenance),
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
            $citation = $this->citationFromElement($element);
            $value = $this->innerHtmlWithoutTags($element, array( 'cite', 'footer' ));
            if ( '' === trim($this->runtime->stripAllTags($value)) ) {
                return null;
            }

            if ( $this->hasClass($element, 'wp-block-pullquote') ) {
                return $this->createBlock('core/pullquote', array_filter(array_merge($this->presentationAttributes($element), array(
                    'value'    => $value,
                    'citation' => $citation,
                )), static fn ($value): bool => '' !== $value), array(), $element);
            }

            $innerBlocks = $this->convertChildrenWithoutTags($element, $fallbacks, array( 'cite', 'footer' ));
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
                return $this->convertFigureBlockquote($element, $blockquote, $fallbacks);
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

        if ( 'details' === $tagName ) {
            $summary = $this->firstChildElement($element, 'summary');
            $children = $this->convertChildrenWithoutTags($element, $fallbacks, array( 'summary' ));
            if ( null === $summary && array() === $children ) {
                return null;
            }

            return $this->createBlock('core/details', array_filter(array_merge($this->presentationAttributes($element), array(
                'summary' => $summary instanceof DOMElement ? $this->innerHtml($summary) : '',
            )), static fn ($value): bool => '' !== $value), $children, $element);
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

        if ( 'svg' === $tagName ) {
            $this->captureInlineSvgFallback($element, $fallbacks);
            return null;
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

        if ( 'nav' === $tagName ) {
            $navigationLinks = $this->navigationLinks($element);
            if ( array() !== $navigationLinks ) {
                return $this->createBlock('core/navigation', $this->presentationAttributes($element), $navigationLinks, $element);
            }
        }

        if ( in_array($tagName, array( 'article', 'body', 'div', 'footer', 'header', 'main', 'nav', 'section' ), true) ) {
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
            $provenanceId = $this->nextSourceProvenanceId++;
            $this->recordPresentationProvenance($name, $attrs, $sourceElement);
            $this->sourceProvenance[$provenanceId] = $this->sourceProvenanceEntry($name, $sourceElement);
        }

        $block = $this->blockFactory->create($name, $attrs, $innerBlocks);
        if ( isset($provenanceId) ) {
            $block['_source_provenance_id'] = $provenanceId;
        }

        return $block;
    }

    /**
     * @param array<int, array<string, mixed>> $blocks
     * @return array<int, array<string, mixed>>
     */
    private function sourceProvenanceForBlocks(array &$blocks): array
    {
        $resolved = array();
        $this->resolveSourceProvenancePaths($blocks, 'blocks', $resolved);
        return $resolved;
    }

    /**
     * @param array<int, array<string, mixed>> $blocks
     * @param array<int, array<string, mixed>> $resolved
     */
    private function resolveSourceProvenancePaths(array &$blocks, string $path, array &$resolved): void
    {
        foreach ( $blocks as $index => &$block ) {
            $blockPath = $path . '.' . $index;
            $provenanceId = $block['_source_provenance_id'] ?? null;
            if ( is_int($provenanceId) && isset($this->sourceProvenance[$provenanceId]) ) {
                $resolved[] = array_merge(array( 'block_path' => $blockPath ), $this->sourceProvenance[$provenanceId]);
            }
            unset($block['_source_provenance_id']);

            if ( ! empty($block['innerBlocks']) && is_array($block['innerBlocks']) ) {
                $this->resolveSourceProvenancePaths($block['innerBlocks'], $blockPath . '.innerBlocks', $resolved);
            }
        }
        unset($block);
    }

    /**
     * @return array<string, mixed>
     */
    private function sourceProvenanceEntry(string $blockName, DOMElement $element): array
    {
        return array(
            'block_name'        => $blockName,
            'tag'               => strtolower($element->tagName),
            'selector'          => $this->elementSelector($element),
            'source_attributes' => $this->safeSourceAttributes($element),
            'source_fragment'   => $this->safeSourceFragment($element),
        );
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
        return in_array(strtolower($element->tagName), array( 'article', 'div', 'footer', 'header', 'main', 'nav', 'section' ), true) && array() !== $this->presentationAttributes($element);
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

    /**
     * @return array<string, string>
     */
    private function safeSourceAttributes(DOMElement $element): array
    {
        $safe = array();
        $allowed = array_flip(array( 'alt', 'class', 'data-layout', 'data-wp-layout', 'height', 'href', 'id', 'open', 'src', 'style', 'title', 'width' ));
        foreach ( $this->htmlAttributes($element) as $name => $value ) {
            if ( isset($allowed[$name]) && ! preg_match('/^\s*javascript\s*:/i', $value) ) {
                $safe[$name] = $value;
            }
        }

        return $safe;
    }

    private function safeSourceFragment(DOMElement $element): string
    {
        $html = $this->safeFallbackHtml($element);
        $html = preg_replace('/\s+on[a-z]+\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)/i', '', $html) ?? '';
        $html = preg_replace('/\s+(href|src)\s*=\s*("\s*javascript:[^"]*"|\'\s*javascript:[^\']*\'|javascript:[^\s>]+)/i', '', $html) ?? '';

        if ( strlen($html) > 500 ) {
            return substr($html, 0, 500) . '...';
        }

        return $html;
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

    private function citationFromElement(DOMElement $element): string
    {
        foreach ( $element->childNodes as $child ) {
            if ( $child instanceof DOMElement && in_array(strtolower($child->tagName), array( 'cite', 'footer', 'figcaption' ), true) ) {
                return $this->innerHtml($child);
            }
        }

        return '';
    }

    /**
     * @param array<int, array<string, mixed>> $fallbacks
     * @return array<string, mixed>|null
     */
    private function convertFigureBlockquote(DOMElement $figure, DOMElement $blockquote, array &$fallbacks): ?array
    {
        $citation = $this->citationFromElement($blockquote);
        $caption = $this->firstChildElement($figure, 'figcaption');
        if ( '' === $citation && $caption instanceof DOMElement ) {
            $citation = $this->innerHtml($caption);
        }

        $value = $this->innerHtmlWithoutTags($blockquote, array( 'cite', 'footer' ));
        if ( '' === trim($this->runtime->stripAllTags($value)) ) {
            return null;
        }

        $attrs = array_filter(array_merge($this->presentationAttributes($figure), array( 'citation' => $citation )), static fn ($value): bool => is_array($value) ? array() !== $value : '' !== $value);

        if ( $this->hasClass($figure, 'wp-block-pullquote') || $this->hasClass($blockquote, 'wp-block-pullquote') ) {
            return $this->createBlock('core/pullquote', array_merge($attrs, array( 'value' => $value )), array(), $figure);
        }

        $innerBlocks = $this->convertChildrenWithoutTags($blockquote, $fallbacks, array( 'cite', 'footer' ));
        if ( array() === $innerBlocks ) {
            $innerBlocks[] = $this->createBlock('core/paragraph', array( 'content' => $value ));
        }

        return $this->createBlock('core/quote', $attrs, $innerBlocks, $figure);
    }

    /**
     * @param array<int, string> $excludedTags
     * @param array<int, array<string, mixed>> $fallbacks
     * @return array<int, array<string, mixed>>
     */
    private function convertChildrenWithoutTags(DOMElement $element, array &$fallbacks, array $excludedTags): array
    {
        $blocks = array();
        foreach ( $element->childNodes as $child ) {
            if ( $child instanceof DOMElement && in_array(strtolower($child->tagName), $excludedTags, true) ) {
                continue;
            }

            if ( XML_TEXT_NODE === $child->nodeType ) {
                $text = trim($child->textContent ?? '');
                if ( '' !== $text ) {
                    $blocks = array_merge($blocks, $this->convertText($text));
                }
                continue;
            }

            if ( $child instanceof DOMElement ) {
                $block = $this->convertElement($child, $fallbacks, true);
                if ( null !== $block ) {
                    $blocks[] = $block;
                }
            }
        }

        return $blocks;
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
        $html = preg_replace('@<(script|style)[^>]*?>.*?</\\1>@si', '', $this->outerHtml($element)) ?? '';
        $html = preg_replace('/\s+on[a-z]+\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)/i', '', $html) ?? '';
        $html = preg_replace('/\s+(?:href|src|xlink:href)\s*=\s*("\s*javascript:[^"]*"|\'\s*javascript:[^\']*\'|javascript:[^\s>]+)/i', '', $html) ?? '';

        return trim($html);
    }

    /**
     * @param array<int, array<string, mixed>> $fallbacks
     */
    private function captureInlineSvgFallback(DOMElement $element, array &$fallbacks): void
    {
        $rawHtml = $this->outerHtml($element);
        $safe = $this->isSafeSvgContent($rawHtml);
        $boundedHtml = $this->boundedFallbackHtml($this->safeFallbackHtml($element));

        $fallbacks[] = array_merge(array(
            'type'            => 'inline_svg',
            'reason'          => $safe ? 'inline_svg_fallback' : 'unsafe_inline_svg',
            'diagnostic_code' => $safe ? 'html_inline_svg_fallback' : 'html_unsafe_inline_svg',
            'message'         => $safe ? 'Inline SVG was preserved as sanitized bounded fallback metadata.' : 'Inline SVG contains scriptable content and was preserved only as sanitized bounded fallback metadata.',
            'source_format'   => 'html',
            'tag'             => 'svg',
            'selector'        => $this->elementSelector($element),
            'attributes'      => $this->safeSvgAttributes($element),
            'text_length'     => strlen(trim($element->textContent ?? '')),
            'child_count'     => $this->childElementCount($element),
            'html'            => $boundedHtml['html'],
            'html_bytes'      => $boundedHtml['bytes'],
            'html_truncated'  => $boundedHtml['truncated'],
        ), $this->fallbackProvenance);
    }

    /**
     * @return array{html: string, bytes: int, truncated: bool}
     */
    private function boundedFallbackHtml(string $html): array
    {
        $bytes = strlen($html);
        if ( $bytes > 2000 ) {
            return array(
                'html'      => substr($html, 0, 2000) . '...',
                'bytes'     => $bytes,
                'truncated' => true,
            );
        }

        return array(
            'html'      => $html,
            'bytes'     => $bytes,
            'truncated' => false,
        );
    }

    /**
     * @return array<string, string>
     */
    private function safeSvgAttributes(DOMElement $element): array
    {
        $attributes = array();
        foreach ( $this->htmlAttributes($element) as $name => $value ) {
            if ( preg_match('/^on[a-z]+$/i', $name) || preg_match('/javascript\s*:/i', $value) ) {
                continue;
            }
            $attributes[$name] = strlen($value) > 200 ? substr($value, 0, 200) . '...' : $value;
        }

        return $attributes;
    }

    private function convertImageElement(DOMElement $image, ?DOMElement $figure = null): ?array
    {
        $url = $this->safeImageUrl($this->attr($image, 'src'));
        if ( '' === $url ) {
            return null;
        }

        $attrs = $this->imagePresentationAttributes($image, $figure);
        $width = $this->attr($image, 'width');
        $height = $this->attr($image, 'height');
        if ( '' !== $width || '' !== $height ) {
            $attrs['className'] = $this->mergeClassNames((string) ($attrs['className'] ?? ''), 'is-resized');
        }

        $attrs = array_filter(array_merge($attrs, array(
            'url'    => $url,
            'alt'    => $this->attr($image, 'alt'),
            'title'  => $this->attr($image, 'title'),
            'srcset' => $this->attr($image, 'srcset'),
            'sizes'  => $this->attr($image, 'sizes'),
            'width'  => $width,
            'height' => $height,
        )), static fn ($value): bool => '' !== $value);

        $attrs = array_filter(array_merge($attrs, $this->imageIdentityAttributes($image, $figure)), static fn ($value): bool => '' !== $value);

        if ( $figure instanceof DOMElement ) {
            $caption = $this->firstChildElement($figure, 'figcaption');
            if ( $caption instanceof DOMElement ) {
                $attrs['caption'] = $this->innerHtml($caption);
            }
        }

        return $this->createBlock('core/image', $attrs, array(), $figure ?? $image);
    }

    private function safeImageUrl(string $url): string
    {
        if ( ! preg_match('#^data:image/svg\+xml(?:[;,][^,]*)?,#i', $url) ) {
            return $url;
        }

        $parts = explode(',', $url, 2);
        if ( 2 !== count($parts) ) {
            return '';
        }

        $metadata = strtolower($parts[0]);
        $svg = str_contains($metadata, ';base64') ? base64_decode($parts[1], true) : rawurldecode($parts[1]);
        if ( false === $svg || ! $this->isSafeSvgContent($svg) ) {
            return '';
        }

        return $url;
    }

    private function isSafeSvgContent(string $content): bool
    {
        return '' !== trim($content) && preg_match('/<svg(?:\s|>)/i', $content) && ! preg_match('/<\s*script\b|\son[a-z]+\s*=|javascript\s*:/i', $content);
    }

    /**
     * @return array<string, string>
     */
    private function imagePresentationAttributes(DOMElement $image, ?DOMElement $figure): array
    {
        $attrs = $this->presentationAttributes($figure ?? $image);
        if ( $figure instanceof DOMElement ) {
            $attrs['className'] = $this->mergeClassNames($this->nonCoreImageFigureClassName($figure), $this->nonCoreImageClassName($image));
        }

        return array_filter($attrs, static fn ($value): bool => is_array($value) ? array() !== $value : '' !== trim((string) $value));
    }

    /**
     * @return array<string, int|string>
     */
    private function imageIdentityAttributes(DOMElement $image, ?DOMElement $figure = null): array
    {
        $attrs = array();
        $className = trim($this->attr($image, 'class') . ' ' . ( $figure instanceof DOMElement ? $this->attr($figure, 'class') : '' ));
        if ( preg_match('/(?:^|\s)wp-image-(\d+)(?:\s|$)/', $className, $matches) ) {
            $attrs['id'] = (int) $matches[1];
        }
        if ( preg_match('/(?:^|\s)size-([a-z0-9_-]+)(?:\s|$)/i', $className, $matches) ) {
            $attrs['sizeSlug'] = strtolower($matches[1]);
        }

        return $attrs;
    }

    private function nonCoreImageClassName(DOMElement $image): string
    {
        $classes = array_filter(preg_split('/\s+/', trim($this->attr($image, 'class'))) ?: array(), static function (string $className): bool {
            return ! preg_match('/^(?:wp-image-\d+|size-[a-z0-9_-]+)$/i', $className);
        });

        return implode(' ', $classes);
    }

    private function nonCoreImageFigureClassName(DOMElement $figure): string
    {
        $classes = array_filter(preg_split('/\s+/', trim($this->attr($figure, 'class'))) ?: array(), static function (string $className): bool {
            return ! preg_match('/^(?:wp-block-image|size-[a-z0-9_-]+)$/i', $className);
        });

        return implode(' ', $classes);
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
    private function navigationLinks(DOMElement $element): array
    {
        $links = array();
        foreach ( $this->directNavigationAnchors($element) as $anchor ) {
            $links[] = $this->createBlock('core/navigation-link', array_filter(array(
                'label' => $this->innerHtml($anchor),
                'url'   => $this->attr($anchor, 'href'),
                'kind'  => 'custom',
            ), static fn ($value): bool => '' !== $value), array(), $anchor);
        }

        return $links;
    }

    /**
     * @return array<int, DOMElement>
     */
    private function directNavigationAnchors(DOMElement $element): array
    {
        $anchors = array();
        foreach ( $element->childNodes as $child ) {
            if ( XML_TEXT_NODE === $child->nodeType && '' === trim($child->textContent ?? '') ) {
                continue;
            }

            if ( $child instanceof DOMElement && 'a' === strtolower($child->tagName) && '' !== trim($child->textContent ?? '') ) {
                $anchors[] = $child;
                continue;
            }

            if ( $child instanceof DOMElement && in_array(strtolower($child->tagName), array( 'ul', 'ol' ), true) ) {
                foreach ( $child->childNodes as $item ) {
                    if ( XML_TEXT_NODE === $item->nodeType && '' === trim($item->textContent ?? '') ) {
                        continue;
                    }

                    if ( ! $item instanceof DOMElement || 'li' !== strtolower($item->tagName) ) {
                        return array();
                    }

                    $anchor = $this->firstChildElement($item, 'a');
                    if ( ! $anchor instanceof DOMElement || '' === trim($anchor->textContent ?? '') || 1 !== $this->childElementCount($item) ) {
                        return array();
                    }

                    $anchors[] = $anchor;
                }
                continue;
            }

            return array();
        }

        return $anchors;
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
