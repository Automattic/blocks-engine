<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks;

use Automattic\BlocksEngine\PhpTransformer\Contract\ConversionReportProjection;
use Automattic\BlocksEngine\PhpTransformer\Contract\TransformationOptions;
use Automattic\BlocksEngine\PhpTransformer\Contract\TransformerResult;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Patterns\ButtonsPattern;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Patterns\DetailsPattern;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Patterns\NavigationPattern;
use Automattic\BlocksEngine\PhpTransformer\WordPress\Runtime;
use DOMDocument;
use DOMElement;
use DOMNode;

final class HtmlTransformer
{
    private const MAX_INTERACTION_CANDIDATES = 100;

    private readonly BlockFactory $blockFactory;

    private readonly ButtonsPattern $buttonsPattern;

    private readonly DetailsPattern $detailsPattern;

    private readonly NavigationPattern $navigationPattern;

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

    /**
     * @var array<int, array<string, mixed>>
     */
    private array $structureProvenance = array();

    /**
     * @var array<string, array<string, mixed>>
     */
    private array $assetMetadata = array();

    private int $nextSourceProvenanceId = 1;

    public function __construct(private readonly Runtime $runtime = new Runtime())
    {
        $this->blockFactory      = new BlockFactory();
        $this->buttonsPattern    = new ButtonsPattern();
        $this->detailsPattern    = new DetailsPattern();
        $this->navigationPattern = new NavigationPattern();
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
        $this->structureProvenance = array();
        $this->assetMetadata = $this->assetMetadataFromOptions($options);
        $this->nextSourceProvenanceId = 1;
        $provenance               = array(
            array_merge(array(
                'source_format' => 'html',
                'input_bytes'   => strlen($html),
                'transformer'   => self::class,
            ), $this->fallbackProvenance),
        );

        $normalizedHtml = $this->normalizeHtml5VoidElements($html);
        $document = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $loaded   = $document->loadHTML('<?xml encoding="utf-8" ?><body>' . $normalizedHtml . '</body>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
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
                FallbackDiagnostic::build(array(
                    'type'            => 'html',
                    'reason'          => 'parse_failed',
                    'diagnostic_code' => 'html_parse_failed',
                    'source_format'   => 'html',
                    'html'            => $html,
                ), $this->fallbackProvenance),
            );

            $metrics = $this->metrics($html, array(), '', $fallbacks, $diagnostics, $startedAt);
            $sourceReports = array(
                'conversion_report' => ConversionReportProjection::fromResultParts('html', array(), $fallbacks, array(), array(), $provenance, $metrics),
            );

            return new TransformerResult(
                diagnostics: $diagnostics,
                sourceReports: $sourceReports,
                fallbacks: $fallbacks,
                provenance: $provenance,
                context: $context,
                metrics: $metrics
            );
        }

        $body = $document->getElementsByTagName('body')->item(0);
        if ( ! $body instanceof DOMElement ) {
            $metrics = $this->metrics($html, array(), '', array(), array(), $startedAt);
            $sourceReports = array(
                'conversion_report' => ConversionReportProjection::fromResultParts('html', array(), array(), array(), array(), $provenance, $metrics),
            );

            return new TransformerResult(
                sourceReports: $sourceReports,
                provenance: $provenance,
                context: $context,
                metrics: $metrics
            );
        }

        $fallbacks   = array();
        $interactionCandidates = $this->interactionCandidates($body);
        $blocks      = $this->convertChildren($body, $fallbacks, true);
        $sourceProvenance = $this->sourceProvenanceForBlocks($blocks);
        $serializedBlocks = $this->runtime->serializeBlocks($blocks);
        $diagnostics = array(
            array(
                'code'    => 'html_to_blocks_core_slice',
                'message' => 'Converted supported core text, layout, media, gallery, embed, file, table, button, shortcode, spacer, definition-list, details, navigation, and wrapper elements; unsupported elements are reported as fallbacks.',
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

        $metrics = $this->metrics($html, $blocks, $serializedBlocks, $fallbacks, $diagnostics, $startedAt);
        $sourceReports = array(
            'interaction_candidates' => $interactionCandidates,
            'html' => array(
                'presentation_signals' => $this->presentationProvenance,
                'source_provenance'    => $sourceProvenance,
                'structure_signals'    => $this->structureProvenance,
            ),
        );
        $sourceReports['conversion_report'] = ConversionReportProjection::fromResultParts('html', $blocks, $fallbacks, $sourceReports, array(), $provenance, $metrics);

        return new TransformerResult(
            status: $this->statusForFallbacks($fallbacks, $context),
            blocks: $blocks,
            serializedBlocks: $serializedBlocks,
            diagnostics: $diagnostics,
            fallbacks: $fallbacks,
            provenance: $provenance,
            sourceReports: $sourceReports,
            coverage: array(
                array(
                    'supported_blocks' => array( 'core/audio', 'core/button', 'core/buttons', 'core/code', 'core/column', 'core/columns', 'core/details', 'core/embed', 'core/file', 'core/gallery', 'core/group', 'core/heading', 'core/image', 'core/list', 'core/list-item', 'core/navigation', 'core/navigation-link', 'core/paragraph', 'core/preformatted', 'core/pullquote', 'core/quote', 'core/separator', 'core/shortcode', 'core/spacer', 'core/table', 'core/video' ),
                    'block_count'      => count($blocks),
                    'fallback_count'   => count($fallbacks),
                    'source_provenance_count' => count($sourceProvenance),
                ),
            ),
            context: $context,
            metrics: $metrics
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

    private function normalizeHtml5VoidElements(string $html): string
    {
        return preg_replace('/<source\b([^>]*?)(?<!\/)\s*>/i', '<source$1></source>', $html) ?? $html;
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
            $items = $this->listItems($element, $fallbacks);

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
            $gallery = $this->galleryBlockFromElement($element, $fallbacks);
            if ( null !== $gallery ) {
                return $gallery;
            }

            $codeWindow = $this->codeWindowBlockFromElement($element, $fallbacks);
            if ( null !== $codeWindow ) {
                return $codeWindow;
            }

            $image = $this->firstChildElement($element, 'img');
            if ( $image instanceof DOMElement ) {
                return $this->convertImageElement($image, $element);
            }

            $picture = $this->firstChildElement($element, 'picture');
            if ( $picture instanceof DOMElement ) {
                return $this->convertPictureElement($picture, $element);
            }

            $blockquote = $this->firstChildElement($element, 'blockquote');
            if ( $blockquote instanceof DOMElement ) {
                return $this->convertFigureBlockquote($element, $blockquote, $fallbacks);
            }
        }

        if ( 'pre' === $tagName ) {
            $code = $this->firstChildElement($element, 'code');
            if ( $code instanceof DOMElement ) {
                return $this->createBlock('core/code', array_merge($this->codePresentationAttributes($element, $code), array( 'content' => $this->codeContent($code) )), array(), $element);
            }

            return $this->createBlock('core/preformatted', array_merge($this->presentationAttributes($element), array( 'content' => $this->innerHtmlPreservingWhitespace($element) )), array(), $element);
        }

        if ( 'table' === $tagName ) {
            return $this->createBlock('core/table', array_merge($this->presentationAttributes($element), $this->tableAttributes($element)), array(), $element);
        }

        if ( 'hr' === $tagName ) {
            return $this->createBlock('core/separator', $this->presentationAttributes($element), array(), $element);
        }

        if ( 'details' === $tagName ) {
            return $this->detailsPattern->match(
                $element,
                $fallbacks,
                fn (DOMElement $sourceElement, array &$sourceFallbacks, array $excludedTags): array => $this->convertChildrenWithoutTags($sourceElement, $sourceFallbacks, $excludedTags),
                fn (DOMElement $sourceElement): array => $this->presentationAttributes($sourceElement),
                fn (DOMElement $sourceElement): string => $this->innerHtml($sourceElement),
                fn (string $name, array $attrs = array(), array $innerBlocks = array(), ?DOMElement $sourceElement = null): array => $this->createBlock($name, $attrs, $innerBlocks, $sourceElement)
            );
        }

        if ( 'img' === $tagName ) {
            return $this->convertImageElement($element);
        }

        if ( 'picture' === $tagName ) {
            return $this->convertPictureElement($element);
        }

        if ( 'iframe' === $tagName ) {
            return $this->convertIframeElement($element, $fallbacks);
        }

        if ( in_array($tagName, array( 'audio', 'video' ), true) ) {
            return $this->convertMediaElement($element);
        }

        if ( 'a' === $tagName && '' !== trim($element->textContent ?? '') ) {
            return $this->buttonsPattern->matchAnchor(
                $element,
                fn (DOMElement $anchor): ?array => $this->fileBlockFromAnchor($anchor),
                fn (DOMElement $sourceElement): array => $this->presentationAttributes($sourceElement),
                fn (DOMElement $sourceElement): string => $this->innerHtml($sourceElement),
                fn (DOMElement $sourceElement, string $name): string => $this->attr($sourceElement, $name),
                fn (string $name, array $attrs = array(), array $innerBlocks = array(), ?DOMElement $sourceElement = null): array => $this->createBlock($name, $attrs, $innerBlocks, $sourceElement)
            );
        }

        if ( 'button' === $tagName ) {
            return $this->buttonsPattern->matchButton(
                $element,
                fn (DOMElement $sourceElement): array => $this->presentationAttributes($sourceElement),
                fn (DOMElement $sourceElement): string => $this->innerHtml($sourceElement),
                fn (string $name, array $attrs = array(), array $innerBlocks = array(), ?DOMElement $sourceElement = null): array => $this->createBlock($name, $attrs, $innerBlocks, $sourceElement)
            );
        }

        if ( 'svg' === $tagName ) {
            if ( $this->isSafeDecorativeSvgElement($element) ) {
                return $this->createBlock('core/html', array( 'content' => $this->safeFallbackHtml($element) ), array(), $element);
            }

            $this->captureInlineSvgFallback($element, $fallbacks);
            return null;
        }

        if ( 'script' === $tagName ) {
            $this->captureScriptFallback($element, $fallbacks);
            return null;
        }

        if ( 'form' === $tagName ) {
            $controls = $this->formControls($element);
            $boundedHtml = $this->boundedFallbackHtml($this->safeFallbackHtml($element));
            $fallbacks[] = FallbackDiagnostic::build(array(
                'type'            => 'html',
                'reason'          => 'form_requires_runtime',
                'diagnostic_code' => 'html_form_fallback',
                'message'         => 'Form HTML requires runtime behavior and was preserved as safe fallback metadata.',
                'source_format'   => 'html',
                'tag'             => $tagName,
                'selector'        => $this->elementSelector($element),
                'attributes'      => $this->htmlAttributes($element),
                'form'            => $this->formMetadata($element),
                'context'         => $this->sourceContext($element),
                'events'          => $this->eventMetadata($element),
                'controls'        => $controls,
                'control_count'   => count($controls),
                'text_length'     => strlen(trim($element->textContent ?? '')),
                'child_count'     => $this->childElementCount($element),
                'html'            => $boundedHtml['html'],
                'html_bytes'      => $boundedHtml['bytes'],
                'html_truncated'  => $boundedHtml['truncated'],
            ), $this->fallbackProvenance);
            return null;
        }

        if ( 'nav' === $tagName ) {
            $navigation = $this->navigationPattern->match(
                $element,
                fn (DOMElement $sourceElement): array => $this->presentationAttributes($sourceElement),
                fn (DOMElement $sourceElement): string => $this->innerHtml($sourceElement),
                fn (string $name, array $attrs = array(), array $innerBlocks = array(), ?DOMElement $sourceElement = null): array => $this->createBlock($name, $attrs, $innerBlocks, $sourceElement)
            );
            if ( null !== $navigation ) {
                return $navigation;
            }
        }

        if ( in_array($tagName, array( 'article', 'body', 'div', 'footer', 'header', 'main', 'nav', 'section' ), true) ) {
            $spacer = $this->spacerBlockFromElement($element);
            if ( null !== $spacer ) {
                return $spacer;
            }

            $columns = $this->columnsBlockFromElement($element, $fallbacks);
            if ( null !== $columns ) {
                return $columns;
            }

            $gallery = $this->galleryBlockFromElement($element, $fallbacks);
            if ( null !== $gallery ) {
                return $gallery;
            }

            $codeWindow = $this->codeWindowBlockFromElement($element, $fallbacks);
            if ( null !== $codeWindow ) {
                return $codeWindow;
            }

            $buttons = $this->buttonsPattern->matchContainer(
                $element,
                fn (DOMElement $sourceElement): array => $this->presentationAttributes($sourceElement),
                fn (DOMElement $sourceElement): string => $this->innerHtml($sourceElement),
                fn (DOMElement $sourceElement, string $name): string => $this->attr($sourceElement, $name),
                fn (string $name, array $attrs = array(), array $innerBlocks = array(), ?DOMElement $sourceElement = null): array => $this->createBlock($name, $attrs, $innerBlocks, $sourceElement)
            );
            if ( null !== $buttons ) {
                return $buttons;
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
            if ( $this->shouldPreserveEmptyVisualElement($element) ) {
                return $this->createBlock('core/group', $this->presentationAttributes($element), array(), $element);
            }
            return null;
        }

        if ( $captureUnsupported ) {
            $fallback = array(
                'type'            => 'unsupported_element',
                'reason'          => 'unsupported_element',
                'diagnostic_code' => 'html_unsupported_element',
                'source_format'   => 'html',
                'tag'             => $tagName,
                'selector'        => $this->elementSelector($element),
                'attributes'      => $this->htmlAttributes($element),
                'context'         => $this->sourceContext($element),
                'events'          => $this->eventMetadata($element),
                'text_length'     => strlen(trim($element->textContent ?? '')),
                'child_count'     => $this->childElementCount($element),
                'html'            => $this->safeFallbackHtml($element),
            );

            $control = $this->formControlMetadata($element);
            if ( array() !== $control ) {
                $fallback['control'] = $control;
            }

            $fallbacks[] = FallbackDiagnostic::build($fallback, $this->fallbackProvenance);
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
            $this->recordStructureProvenance($name, $attrs, $sourceElement);
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
            'context'           => $this->sourceContext($element),
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

    private function innerHtmlPreservingWhitespace(DOMElement $element): string
    {
        $html = '';
        foreach ( $element->childNodes as $child ) {
            $html .= $element->ownerDocument->saveHTML($child);
        }

        return $html;
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

    /**
     * @param array<string, mixed> $attrs
     */
    private function recordStructureProvenance(string $blockName, array $attrs, DOMElement $element): void
    {
        $signals = $this->structureSignals($element, $attrs);
        if ( array() === $signals ) {
            return;
        }

        $this->structureProvenance[] = array(
            'block_name'        => $blockName,
            'tag'               => strtolower($element->tagName),
            'selector'          => $this->elementSelector($element),
            'signals'           => $signals,
            'source_attributes' => array_intersect_key($this->htmlAttributes($element), array_flip(array( 'class', 'id', 'role', 'style', 'data-layout', 'data-wp-layout' ))),
        );
    }

    private function shouldPreserveWrapper(DOMElement $element): bool
    {
        return in_array(strtolower($element->tagName), array( 'article', 'div', 'footer', 'header', 'main', 'nav', 'section' ), true) && ( array() !== $this->presentationAttributes($element) || array() !== $this->structureSignals($element, array()) );
    }

    private function shouldPreserveEmptyVisualElement(DOMElement $element): bool
    {
        if ( '' !== trim($element->textContent ?? '') || 0 !== $this->childElementCount($element) ) {
            return false;
        }

        return $this->shouldPreserveWrapper($element) || in_array(strtolower($this->attr($element, 'role')), array( 'presentation', 'none' ), true) || 'true' === strtolower($this->attr($element, 'aria-hidden'));
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
        $allowed = array_flip(array( 'alt', 'class', 'data-layout', 'data-wp-layout', 'height', 'href', 'id', 'media', 'open', 'sizes', 'src', 'srcset', 'style', 'title', 'type', 'width' ));
        foreach ( $this->htmlAttributes($element) as $name => $value ) {
            if ( isset($allowed[$name]) && ! preg_match('/^\s*javascript\s*:/i', $value) ) {
                $safe[$name] = $value;
            }
        }

        return $safe;
    }

    /**
     * @return array<string, mixed>
     */
    private function sourceContext(DOMElement $element): array
    {
        return array_filter(array(
            'selector'                => $this->elementSelector($element),
            'parent_tag'              => $element->parentNode instanceof DOMElement && 'body' !== strtolower($element->parentNode->tagName) ? strtolower($element->parentNode->tagName) : '',
            'ancestor_tags'           => $this->ancestorTags($element),
            'nearest_heading'         => $this->nearestPreviousHeadingText($element),
            'role'                    => $this->attr($element, 'role'),
            'id'                      => $this->attr($element, 'id'),
            'class_names'             => $this->classNames($element),
            'data_attributes'         => $this->safeDataAttributes($element),
            'structure_signals'       => $this->structureSignals($element, array()),
            'interactive_attributes'  => $this->interactiveAttributes($element),
        ), static fn (mixed $value): bool => '' !== $value && array() !== $value);
    }

    /**
     * @return array<int, string>
     */
    private function ancestorTags(DOMElement $element): array
    {
        $tags = array();
        for ( $parent = $element->parentNode; $parent instanceof DOMElement && 'body' !== strtolower($parent->tagName); $parent = $parent->parentNode ) {
            $tags[] = strtolower($parent->tagName);
        }

        return $tags;
    }

    private function nearestPreviousHeadingText(DOMElement $element): string
    {
        for ( $node = $element->previousSibling; $node instanceof DOMNode; $node = $node->previousSibling ) {
            if ( $node instanceof DOMElement && preg_match('/^h[1-6]$/i', $node->tagName) ) {
                return trim(preg_replace('/\s+/', ' ', $node->textContent ?? '') ?? '');
            }
        }

        return '';
    }

    /**
     * @return array<int, string>
     */
    private function classNames(DOMElement $element): array
    {
        return array_values(array_filter(preg_split('/\s+/', trim($this->attr($element, 'class'))) ?: array()));
    }

    /**
     * @return array<string, string>
     */
    private function safeDataAttributes(DOMElement $element): array
    {
        $data = array();
        foreach ( $this->htmlAttributes($element) as $name => $value ) {
            if ( preg_match('/^data-[a-z0-9_-]+$/i', $name) && strlen($value) <= 300 && ! preg_match('/javascript\s*:/i', $value) ) {
                $data[$name] = $value;
            }
        }

        return $data;
    }

    /**
     * @return array<string, bool|string>
     */
    private function interactiveAttributes(DOMElement $element): array
    {
        return array_filter(array(
            'tabindex'      => $this->attr($element, 'tabindex'),
            'aria-expanded' => $this->attr($element, 'aria-expanded'),
            'aria-controls' => $this->attr($element, 'aria-controls'),
            'has_events'    => array() !== $this->eventMetadata($element),
        ), static fn (mixed $value): bool => false !== $value && '' !== $value);
    }

    /**
     * @param array<string, mixed> $attrs
     * @return array<string, mixed>
     */
    private function structureSignals(DOMElement $element, array $attrs): array
    {
        $className = strtolower(trim($this->attr($element, 'class') . ' ' . (string) ($attrs['className'] ?? '')));
        $style = strtolower(trim($this->attr($element, 'style') . ';' . (string) ($attrs['style'] ?? '')));
        $signals = array();

        if ( preg_match('/(?:^|[\s_-])(?:card|tile|panel|item)(?:$|[\s_-])/', $className) || 'article' === strtolower($element->tagName) ) {
            $signals['card_like'] = true;
        }
        if ( preg_match('/(?:^|[\s_-])(?:cards|grid|tiles|columns|collection|gallery)(?:$|[\s_-])/', $className) || preg_match('/(?:^|;)\s*(?:display\s*:\s*grid|grid-template-columns\s*:)/', $style) ) {
            $signals['grid_like'] = true;
        }

        $itemCount = 0;
        foreach ( $element->childNodes as $child ) {
            if ( $child instanceof DOMElement && $this->isCardLikeElement($child) ) {
                ++$itemCount;
            }
        }
        if ( 1 < $itemCount ) {
            $signals['repeated_card_children'] = $itemCount;
        }

        return $signals;
    }

    private function isCardLikeElement(DOMElement $element): bool
    {
        $className = strtolower($this->attr($element, 'class'));
        return 'article' === strtolower($element->tagName) || (bool) preg_match('/(?:^|[\s_-])(?:card|tile|panel|item)(?:$|[\s_-])/', $className);
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

    /**
     * @return array<int, array<string, mixed>>
     */
    private function interactionCandidates(DOMElement $root): array
    {
        $candidates = array();
        $seen = array();
        foreach ( $root->getElementsByTagName('*') as $element ) {
            if ( ! $element instanceof DOMElement ) {
                continue;
            }

            foreach ( $this->interactionCandidatesForElement($element) as $candidate ) {
                $key = json_encode($candidate, JSON_UNESCAPED_SLASHES);
                if ( ! is_string($key) || isset($seen[$key]) ) {
                    continue;
                }
                $seen[$key] = true;
                $candidates[] = $candidate;
                if ( count($candidates) >= self::MAX_INTERACTION_CANDIDATES ) {
                    return $candidates;
                }
            }
        }

        return $candidates;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function interactionCandidatesForElement(DOMElement $element): array
    {
        $tagName = strtolower($element->tagName);
        $role = strtolower($this->attr($element, 'role'));
        $classes = strtolower($this->attr($element, 'class'));
        $id = strtolower($this->attr($element, 'id'));
        $data = $this->safeDataAttributes($element);
        $dataText = strtolower(implode(' ', array_merge(array_keys($data), array_values($data))));
        $nameText = trim($classes . ' ' . $id . ' ' . $dataText);
        $events = $this->eventMetadata($element);
        $actionDataAttributes = array_keys(array_filter($data, static fn (string $value, string $name): bool => preg_match('/^data-(?:action|on|event)$/i', $name) && '' !== trim($value), ARRAY_FILTER_USE_BOTH));
        $hasAriaControl = '' !== trim($this->attr($element, 'aria-controls')) || '' !== trim($this->attr($element, 'aria-expanded'));
        $candidates = array();

        if ( 'details' === $tagName ) {
            $candidates[] = $this->interactionCandidate($element, 'details', 'summary', $this->targetForElement($element), array('details_element'), 'high', 'native_toggle');
        }

        if ( 'form' === $tagName ) {
            $metadata = $this->formMetadata($element);
            $candidates[] = $this->interactionCandidate($element, 'form', 'submit', (string) ($metadata['action'] ?? ''), array_filter(array('form_element', (string) ($metadata['method'] ?? ''))), 'high', 'form_submission');
        }

        if ( in_array($tagName, array('button', 'a'), true) && ( array() !== $events || array() !== $actionDataAttributes || $hasAriaControl ) ) {
            $candidates[] = $this->interactionCandidate($element, 'control', $this->controlTrigger($element, $events), $this->controlledTarget($element), $this->controlEvidence($element, $events, $actionDataAttributes), $hasAriaControl ? 'high' : 'medium', 'client_runtime');
        }

        if ( 'dialog' === $tagName || in_array($role, array('dialog', 'alertdialog'), true) || preg_match('/(?:^|[\s_-])(?:modal|dialog|popup|lightbox)(?:$|[\s_-])/', $nameText) ) {
            $candidates[] = $this->interactionCandidate($element, 'modal', $this->modalTriggerHint($element), $this->targetForElement($element), array_filter(array('modal_like', 'dialog' === $tagName ? 'dialog_element' : '', '' !== $role ? 'role:' . $role : '')), 'medium', 'modal_runtime');
        }

        if ( in_array($role, array('tablist', 'tab', 'tabpanel'), true) ) {
            $candidates[] = $this->interactionCandidate($element, 'tabs', 'tab' === $role ? 'tab_select' : $role, $this->controlledTarget($element), array_filter(array('role:' . $role, '' !== $this->attr($element, 'aria-controls') ? 'aria-controls' : '')), 'high', 'tab_state');
        }

        if ( ( in_array($tagName, array('button', 'a'), true) || '' !== $role ) && ( preg_match('/(?:^|[\s_-])accordion(?:$|[\s_-])/', $nameText) || ( $hasAriaControl && 'tab' !== $role && '' !== trim($this->attr($element, 'aria-expanded')) ) ) ) {
            $candidates[] = $this->interactionCandidate($element, 'accordion', $this->controlTrigger($element, $events), $this->controlledTarget($element), array_filter(array('accordion_like', '' !== $this->attr($element, 'aria-expanded') ? 'aria-expanded' : '', '' !== $this->attr($element, 'aria-controls') ? 'aria-controls' : '')), 'medium', 'accordion_state');
        }

        if ( preg_match('/(?:^|[\s_-])(?:carousel|slider|slideshow|swiper)(?:$|[\s_-])/', $nameText) ) {
            $candidates[] = $this->interactionCandidate($element, 'carousel', $this->carouselTriggerHint($element), $this->targetForElement($element), array('carousel_like'), 'medium', 'carousel_runtime');
        }

        return $candidates;
    }

    /**
     * @param array<int, string> $evidence
     * @return array<string, mixed>
     */
    private function interactionCandidate(DOMElement $element, string $kind, string $trigger, string $target, array $evidence, string $confidence, string $runtimeRequirement): array
    {
        return array_filter(
            array(
                'selector'                => $this->elementSelector($element),
                'kind'                    => $kind,
                'trigger'                 => $trigger,
                'target'                  => $target,
                'evidence'                => array_values(array_unique(array_filter($evidence, static fn (string $value): bool => '' !== $value))),
                'confidence'              => $confidence,
                'runtime_requirement'     => $runtimeRequirement,
                'materialization_hint'    => $this->materializationHintForInteractionKind($kind),
            ),
            static fn (mixed $value): bool => '' !== $value && array() !== $value
        );
    }

    private function targetForElement(DOMElement $element): string
    {
        $id = trim($this->attr($element, 'id'));
        return '' !== $id ? '#' . $id : $this->elementSelector($element);
    }

    private function controlledTarget(DOMElement $element): string
    {
        $target = trim($this->attr($element, 'aria-controls'));
        return '' !== $target ? '#' . ltrim($target, '#') : $this->targetForElement($element);
    }

    /**
     * @param array<int, array<string, string>> $events
     */
    private function controlTrigger(DOMElement $element, array $events): string
    {
        if ( array() !== $events ) {
            return (string) ($events[0]['type'] ?? 'event');
        }

        $type = strtolower($this->attr($element, 'type'));
        return 'submit' === $type ? 'submit' : 'click';
    }

    /**
     * @param array<int, array<string, string>> $events
     * @param array<int, string> $actionDataAttributes
     * @return array<int, string>
     */
    private function controlEvidence(DOMElement $element, array $events, array $actionDataAttributes): array
    {
        $evidence = array();
        foreach ( $events as $event ) {
            $attribute = (string) ($event['attribute'] ?? '');
            if ( '' !== $attribute ) {
                $evidence[] = $attribute;
            }
        }
        foreach ( $actionDataAttributes as $attribute ) {
            $evidence[] = $attribute;
        }
        if ( '' !== trim($this->attr($element, 'aria-controls')) ) {
            $evidence[] = 'aria-controls';
        }
        if ( '' !== trim($this->attr($element, 'aria-expanded')) ) {
            $evidence[] = 'aria-expanded';
        }

        return $evidence;
    }

    private function modalTriggerHint(DOMElement $element): string
    {
        return '' !== trim($this->attr($element, 'open')) ? 'open' : 'show';
    }

    private function carouselTriggerHint(DOMElement $element): string
    {
        return preg_match('/(?:^|[\s_-])(?:next|prev|previous)(?:$|[\s_-])/', strtolower($this->attr($element, 'class'))) ? 'advance' : 'slide';
    }

    private function materializationHintForInteractionKind(string $kind): string
    {
        return match ( $kind ) {
            'details' => 'preserve_native_details',
            'form' => 'preserve_or_replace_form_runtime',
            'tabs' => 'materialize_tab_panels_or_runtime',
            'accordion' => 'materialize_expanded_state_or_runtime',
            'carousel' => 'preserve_static_slides_or_runtime',
            'modal' => 'preserve_dialog_markup_or_runtime',
            default => 'preserve_static_markup_with_runtime_note',
        };
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

    /**
     * @param array<int, array<string, mixed>> $fallbacks
     * @return array<int, array<string, mixed>>
     */
    private function listItems(DOMElement $list, array &$fallbacks): array
    {
        $items = array();
        foreach ( $list->childNodes as $child ) {
            if ( ! $child instanceof DOMElement || 'li' !== strtolower($child->tagName) ) {
                continue;
            }

            $nested = array();
            foreach ( $child->childNodes as $itemChild ) {
                if ( $itemChild instanceof DOMElement && in_array(strtolower($itemChild->tagName), array( 'ul', 'ol' ), true) ) {
                    $nestedBlock = $this->convertElement($itemChild, $fallbacks, true);
                    if ( null !== $nestedBlock ) {
                        $nested[] = $nestedBlock;
                    }
                }
            }

            $content = $this->innerHtmlWithoutTags($child, array( 'ul', 'ol' ));
            if ( '' === trim($this->runtime->stripAllTags($content)) && array() === $nested ) {
                continue;
            }

            $items[] = $this->createBlock('core/list-item', array_merge($this->presentationAttributes($child), array( 'content' => $content )), $nested, $child);
        }

        return $items;
    }

    private function safeFallbackHtml(DOMElement $element): string
    {
        $html = preg_replace('@<(script|style)[^>]*?>.*?</\\1>@si', '', $this->outerHtml($element)) ?? '';
        $html = preg_replace('/\s+on[a-z]+\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)/i', '', $html) ?? '';
        $html = preg_replace('/\s+(?:href|src|xlink:href)\s*=\s*("\s*javascript:[^"]*"|\'\s*javascript:[^\']*\'|javascript:[^\s>]+)/i', '', $html) ?? '';
        $html = preg_replace('/\s+srcdoc\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)/i', '', $html) ?? '';

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

        $fallbacks[] = FallbackDiagnostic::build(array(
            'type'            => 'inline_svg',
            'reason'          => $safe ? 'inline_svg_fallback' : 'unsafe_inline_svg',
            'diagnostic_code' => $safe ? 'html_inline_svg_fallback' : 'html_unsafe_inline_svg',
            'message'         => $safe ? 'Inline SVG was preserved as sanitized bounded fallback metadata.' : 'Inline SVG contains scriptable content and was preserved only as sanitized bounded fallback metadata.',
            'source_format'   => 'html',
            'tag'             => 'svg',
            'selector'        => $this->elementSelector($element),
            'attributes'      => $this->safeSvgAttributes($element),
            'context'         => $this->sourceContext($element),
            'events'          => $this->eventMetadata($element),
            'text_length'     => strlen(trim($element->textContent ?? '')),
            'child_count'     => $this->childElementCount($element),
            'html'            => $boundedHtml['html'],
            'html_bytes'      => $boundedHtml['bytes'],
            'html_truncated'  => $boundedHtml['truncated'],
        ), $this->fallbackProvenance);
    }

    /**
     * @param array<int, array<string, mixed>> $fallbacks
     */
    private function captureScriptFallback(DOMElement $element, array &$fallbacks): void
    {
        $boundedHtml = $this->boundedFallbackHtml($this->safeFallbackHtml($element));
        $boundedBody = $this->boundedFallbackText(trim($element->textContent ?? ''));
        $fallbacks[] = FallbackDiagnostic::build(array(
            'type'            => 'html',
            'reason'          => 'script_requires_runtime',
            'diagnostic_code' => 'html_script_fallback',
            'message'         => 'Script HTML requires runtime behavior and was preserved as scoped safe fallback metadata.',
            'source_format'   => 'html',
            'tag'             => 'script',
            'selector'        => $this->elementSelector($element),
            'attributes'      => $this->safeScriptAttributes($element),
            'context'         => $this->sourceContext($element),
            'events'          => $this->eventMetadata($element),
            'text_length'     => strlen(trim($element->textContent ?? '')),
            'child_count'     => $this->childElementCount($element),
            'html'            => $boundedHtml['html'],
            'html_bytes'      => $boundedHtml['bytes'],
            'html_truncated'  => $boundedHtml['truncated'],
            'body'            => $boundedBody['text'],
            'body_bytes'      => $boundedBody['bytes'],
            'body_truncated'  => $boundedBody['truncated'],
        ), $this->fallbackProvenance);
    }

    /**
     * @return array<string, string>
     */
    private function safeScriptAttributes(DOMElement $element): array
    {
        $safe = array();
        $allowed = array_flip(array( 'async', 'class', 'defer', 'id', 'src', 'type' ));
        foreach ( $this->htmlAttributes($element) as $name => $value ) {
            if ( isset($allowed[$name]) && ! preg_match('/javascript\s*:/i', $value) ) {
                $safe[$name] = strlen($value) > 300 ? substr($value, 0, 300) . '...' : $value;
            }
        }

        return $safe;
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
     * @return array{text: string, bytes: int, truncated: bool}
     */
    private function boundedFallbackText(string $text): array
    {
        $bytes = strlen($text);
        if ( $bytes > 2000 ) {
            return array(
                'text'      => substr($text, 0, 2000) . '...',
                'bytes'     => $bytes,
                'truncated' => true,
            );
        }

        return array(
            'text'      => $text,
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

    private function isSafeDecorativeSvgElement(DOMElement $element): bool
    {
        if ( ! $this->isSafeSvgContent($this->outerHtml($element)) || ! $this->isPassiveSvgMarkup($element) ) {
            return false;
        }

        $role = strtolower(trim($this->attr($element, 'role')));
        if ( 'true' === strtolower(trim($this->attr($element, 'aria-hidden'))) || in_array($role, array( 'presentation', 'none' ), true) ) {
            return true;
        }

        return $this->hasIconLikeContext($element);
    }

    private function hasIconLikeContext(DOMElement $element): bool
    {
        for ( $current = $element; $current instanceof DOMElement; $current = $current->parentNode instanceof DOMElement ? $current->parentNode : null ) {
            $context = strtolower(trim(implode(' ', array(
                $this->attr($current, 'class'),
                $this->attr($current, 'id'),
                $this->attr($current, 'aria-label'),
                $this->attr($current, 'title'),
            ))));

            if ( preg_match('/(?:^|[\s_-])(?:icon|logo)(?:$|[\s_-])/', $context) ) {
                return true;
            }

            if ( in_array(strtolower($current->tagName), array( 'body', 'main', 'article', 'section' ), true) ) {
                return false;
            }
        }

        return false;
    }

    private function isPassiveSvgMarkup(DOMElement $element): bool
    {
        $allowedTags = array_flip(array( 'circle', 'clippath', 'defs', 'desc', 'ellipse', 'g', 'line', 'lineargradient', 'mask', 'path', 'polygon', 'polyline', 'radialgradient', 'rect', 'stop', 'svg', 'title' ));
        $allowedAttributes = array_flip(array( 'aria-hidden', 'class', 'clip-path', 'clip-rule', 'cx', 'cy', 'd', 'fill', 'fill-opacity', 'fill-rule', 'height', 'id', 'offset', 'opacity', 'points', 'r', 'role', 'rx', 'ry', 'stroke', 'stroke-dasharray', 'stroke-linecap', 'stroke-linejoin', 'stroke-opacity', 'stroke-width', 'style', 'transform', 'viewbox', 'width', 'x', 'x1', 'x2', 'xmlns', 'y', 'y1', 'y2' ));

        foreach ( $element->getElementsByTagName('*') as $child ) {
            if ( ! $child instanceof DOMElement || ! $this->isPassiveSvgElement($child, $allowedTags, $allowedAttributes) ) {
                return false;
            }
        }

        return $this->isPassiveSvgElement($element, $allowedTags, $allowedAttributes);
    }

    /**
     * @param array<string, int> $allowedTags
     * @param array<string, int> $allowedAttributes
     */
    private function isPassiveSvgElement(DOMElement $element, array $allowedTags, array $allowedAttributes): bool
    {
        if ( ! isset($allowedTags[strtolower($element->tagName)]) ) {
            return false;
        }

        foreach ( $this->htmlAttributes($element) as $name => $value ) {
            $name = strtolower($name);
            if ( ! isset($allowedAttributes[$name]) || preg_match('/^on[a-z]+$|(?:^|:)href$/i', $name) || preg_match('/javascript\s*:|\b(?:expression|behavior)\s*:|\burl\s*\(/i', $value) ) {
                return false;
            }
        }

        return true;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function formControls(DOMElement $form): array
    {
        $controls = array();
        foreach ( $form->getElementsByTagName('*') as $control ) {
            if ( ! $control instanceof DOMElement || ! $this->isFormControlElement($control) ) {
                continue;
            }

            $metadata = $this->formControlMetadata($control);
            if ( array() !== $metadata ) {
                $controls[] = $metadata;
            }
        }

        return $controls;
    }

    /**
     * @return array<string, string>
     */
    private function formMetadata(DOMElement $form): array
    {
        return array_filter(
            array(
                'action'  => $this->attr($form, 'action'),
                'method'  => strtolower($this->attr($form, 'method')),
                'enctype' => $this->attr($form, 'enctype'),
            ),
            static fn (string $value): bool => '' !== $value
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function formControlMetadata(DOMElement $control): array
    {
        if ( ! $this->isFormControlElement($control) ) {
            return array();
        }

        $tagName = strtolower($control->tagName);
        $metadata = array_filter(array(
            'tag'         => $tagName,
            'selector'    => $this->elementSelector($control),
            'name'        => $this->attr($control, 'name'),
            'type'        => $this->formControlType($control),
            'label'       => $this->formControlLabel($control),
            'placeholder' => $this->attr($control, 'placeholder'),
        ), static fn (string $value): bool => '' !== $value);

        if ( $control->hasAttribute('required') ) {
            $metadata['required'] = true;
        }
        if ( $control->hasAttribute('disabled') ) {
            $metadata['disabled'] = true;
        }

        $value = $this->attr($control, 'value');
        if ( '' !== $value && 'select' !== $tagName ) {
            $metadata['value'] = $value;
        }

        if ( 'select' === $tagName ) {
            $options = $this->selectOptions($control);
            if ( array() !== $options ) {
                $metadata['options'] = $options;
            }
        }

        return $metadata;
    }

    private function isFormControlElement(DOMElement $element): bool
    {
        return in_array(strtolower($element->tagName), array( 'button', 'input', 'select', 'textarea' ), true);
    }

    private function formControlType(DOMElement $control): string
    {
        $tagName = strtolower($control->tagName);
        if ( 'input' === $tagName ) {
            $type = strtolower(trim($this->attr($control, 'type')));
            return '' !== $type ? $type : 'text';
        }
        if ( 'button' === $tagName ) {
            $type = strtolower(trim($this->attr($control, 'type')));
            return '' !== $type ? $type : 'submit';
        }
        if ( 'select' === $tagName && $control->hasAttribute('multiple') ) {
            return 'select-multiple';
        }

        return $tagName;
    }

    private function formControlLabel(DOMElement $control): string
    {
        $ariaLabel = trim($this->attr($control, 'aria-label'));
        if ( '' !== $ariaLabel ) {
            return $ariaLabel;
        }

        $id = $this->attr($control, 'id');
        if ( '' !== $id && $control->ownerDocument instanceof DOMDocument ) {
            foreach ( $control->ownerDocument->getElementsByTagName('label') as $label ) {
                if ( $label instanceof DOMElement && $id === $this->attr($label, 'for') ) {
                    return $this->normalizedControlLabelText($label);
                }
            }
        }

        for ( $parent = $control->parentNode; $parent instanceof DOMElement; $parent = $parent->parentNode ) {
            if ( 'label' === strtolower($parent->tagName) ) {
                return $this->normalizedControlLabelText($parent);
            }
        }

        return '';
    }

    private function normalizedControlLabelText(DOMElement $label): string
    {
        return trim(preg_replace('/\s+/', ' ', $this->labelTextWithoutControls($label)) ?? '');
    }

    private function labelTextWithoutControls(DOMNode $node): string
    {
        if ( XML_TEXT_NODE === $node->nodeType ) {
            return $node->textContent ?? '';
        }

        if ( $node instanceof DOMElement && $this->isFormControlElement($node) ) {
            return '';
        }

        $text = '';
        foreach ( $node->childNodes as $child ) {
            $text .= $this->labelTextWithoutControls($child);
        }

        return $text;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function selectOptions(DOMElement $select): array
    {
        $options = array();
        foreach ( $select->getElementsByTagName('option') as $option ) {
            if ( ! $option instanceof DOMElement ) {
                continue;
            }

            $value = $this->attr($option, 'value');
            $optionMetadata = array(
                'label' => trim(preg_replace('/\s+/', ' ', $option->textContent ?? '') ?? ''),
                'value' => '' !== $value ? $value : trim($option->textContent ?? ''),
            );
            if ( $option->hasAttribute('selected') ) {
                $optionMetadata['selected'] = true;
            }
            if ( $option->hasAttribute('disabled') ) {
                $optionMetadata['disabled'] = true;
            }

            $options[] = $optionMetadata;
        }

        return $options;
    }

    /**
     * @param array<int, array<string, mixed>> $fallbacks
     * @return array<string, mixed>|null
     */
    private function galleryBlockFromElement(DOMElement $element, array &$fallbacks): ?array
    {
        $images = array();
        foreach ( $element->childNodes as $child ) {
            if ( XML_TEXT_NODE === $child->nodeType && '' === trim($child->textContent ?? '') ) {
                continue;
            }

            if ( ! $child instanceof DOMElement ) {
                return null;
            }

            $tagName = strtolower($child->tagName);
            if ( 'figcaption' === $tagName ) {
                continue;
            }

            if ( 'figure' === $tagName ) {
                $image = $this->firstChildElement($child, 'img');
                if ( $image instanceof DOMElement ) {
                    $images[] = $this->convertImageElement($image, $child);
                    continue;
                }

                $picture = $this->firstChildElement($child, 'picture');
                if ( $picture instanceof DOMElement ) {
                    $images[] = $this->convertPictureElement($picture, $child);
                    continue;
                }
            }

            if ( 'img' === $tagName ) {
                $images[] = $this->convertImageElement($child);
                continue;
            }

            if ( 'picture' === $tagName ) {
                $images[] = $this->convertPictureElement($child);
                continue;
            }

            return null;
        }

        $images = array_values(array_filter($images));
        if ( count($images) < 2 ) {
            return null;
        }

        $attrs = $this->presentationAttributes($element);
        $caption = $this->firstChildElement($element, 'figcaption');
        if ( $caption instanceof DOMElement ) {
            $attrs['caption'] = $this->innerHtml($caption);
        }

        return $this->createBlock('core/gallery', array_filter($attrs, static fn ($value): bool => is_array($value) ? array() !== $value : '' !== trim((string) $value)), $images, $element);
    }

    /**
     * @param array<int, array<string, mixed>> $fallbacks
     * @return array<string, mixed>|null
     */
    private function columnsBlockFromElement(DOMElement $element, array &$fallbacks): ?array
    {
        if ( ! $this->looksLikeColumnsContainer($element) ) {
            return null;
        }

        $columns = array();
        foreach ( $element->childNodes as $child ) {
            if ( XML_TEXT_NODE === $child->nodeType && '' === trim($child->textContent ?? '') ) {
                continue;
            }

            if ( ! $child instanceof DOMElement ) {
                return null;
            }

            $children = $this->convertChildren($child, $fallbacks, true);
            $columns[] = $this->createBlock('core/column', $this->presentationAttributes($child), $children, $child);
        }

        if ( count($columns) < 2 ) {
            return null;
        }

        return $this->createBlock('core/columns', $this->presentationAttributes($element), $columns, $element);
    }

    private function looksLikeColumnsContainer(DOMElement $element): bool
    {
        if ( $this->hasClass($element, 'wp-block-columns') ) {
            return true;
        }

        $className = strtolower($this->attr($element, 'class'));
        $style = strtolower($this->attr($element, 'style'));

        return (bool) preg_match('/(?:^|[\s_-])columns?(?:$|[\s_-])/', $className)
            || preg_match('/(?:^|;)\s*(?:display\s*:\s*(?:inline-)?flex|grid-template-columns\s*:)/', $style);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function spacerBlockFromElement(DOMElement $element): ?array
    {
        if ( '' !== trim($element->textContent ?? '') || 0 !== $this->childElementCount($element) ) {
            return null;
        }

        $height = $this->spacerHeightFromStyle($this->attr($element, 'style'));
        if ( '' === $height ) {
            return null;
        }

        if ( ! $this->hasClass($element, 'wp-block-spacer') && ! $this->hasClass($element, 'spacer') ) {
            return null;
        }

        $attrs = $this->presentationAttributes($element);
        $attrs['height'] = $height;
        unset($attrs['style']);

        return $this->createBlock('core/spacer', $attrs, array(), $element);
    }

    private function spacerHeightFromStyle(string $style): string
    {
        if ( ! preg_match('/(?:^|;)\s*height\s*:\s*([^;]+)/i', $style, $matches) ) {
            return '';
        }

        $height = trim($matches[1]);
        if ( '' === $height || preg_match('/[{}]/', $height) || strlen($height) > 80 ) {
            return '';
        }

        return $height;
    }

    private function convertMediaElement(DOMElement $element): ?array
    {
        $tagName = strtolower($element->tagName);
        $src = $this->safeMediaUrl($this->attr($element, 'src'));
        if ( '' === $src ) {
            $source = $this->firstChildElement($element, 'source');
            $src = $source instanceof DOMElement ? $this->safeMediaUrl($this->attr($source, 'src')) : '';
        }
        if ( '' === $src ) {
            return null;
        }

        $attrs = array_filter(array_merge($this->presentationAttributes($element), array(
            'src'      => $src,
            'poster'   => 'video' === $tagName ? $this->attr($element, 'poster') : '',
            'preload'  => $this->attr($element, 'preload'),
            'width'    => $this->attr($element, 'width'),
            'height'   => $this->attr($element, 'height'),
            'controls' => $element->hasAttribute('controls'),
        )), static fn (mixed $value): bool => is_bool($value) ? $value : '' !== $value);

        return $this->createBlock('core/' . $tagName, $attrs, array(), $element);
    }

    private function safeMediaUrl(string $url): string
    {
        $url = trim($url);
        if ( '' === $url || preg_match('/[\x00-\x1f\x7f]|javascript\s*:/i', $url) ) {
            return '';
        }

        return $url;
    }

    private function fileBlockFromAnchor(DOMElement $anchor): ?array
    {
        $href = $this->safeFileUrl($this->attr($anchor, 'href'));
        if ( '' === $href ) {
            return null;
        }

        $attrs = array_filter(array_merge($this->presentationAttributes($anchor), array(
            'href'               => $href,
            'url'                => $href,
            'text'               => $this->innerHtml($anchor),
            'showDownloadButton' => $anchor->hasAttribute('download'),
        )), static fn (mixed $value): bool => is_bool($value) ? $value : '' !== $value);

        return $this->createBlock('core/file', $attrs, array(), $anchor);
    }

    private function safeFileUrl(string $url): string
    {
        $url = trim($url);
        if ( '' === $url || preg_match('/[\x00-\x1f\x7f]|javascript\s*:/i', $url) ) {
            return '';
        }

        $path = (string) parse_url($url, PHP_URL_PATH);
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        return in_array($extension, array( 'doc', 'docx', 'odp', 'ods', 'odt', 'pdf', 'ppt', 'pptx', 'rtf', 'txt', 'xls', 'xlsx', 'zip' ), true) ? $url : '';
    }

    private function convertPictureElement(DOMElement $picture, ?DOMElement $figure = null): ?array
    {
        $image = $this->firstChildElement($picture, 'img');
        if ( ! $image instanceof DOMElement ) {
            return null;
        }

        return $this->convertImageElement($image, $figure ?? $picture, $picture);
    }

    private function convertImageElement(DOMElement $image, ?DOMElement $figure = null, ?DOMElement $picture = null): ?array
    {
        $url = $this->safeImageUrl($this->attr($image, 'src'));
        if ( '' === $url ) {
            return null;
        }

        $attrs = $this->imagePresentationAttributes($image, $figure);
        if ( null !== $picture && ! $figure instanceof DOMElement ) {
            $attrs = array_merge($this->presentationAttributes($picture), $attrs);
        }
        $width = $this->attr($image, 'width');
        $height = $this->attr($image, 'height');
        $sourceAttrs = $picture instanceof DOMElement ? $this->pictureSourceAttributes($picture) : array();
        if ( '' !== $width || '' !== $height ) {
            $attrs['className'] = $this->mergeClassNames((string) ($attrs['className'] ?? ''), 'is-resized');
        }

        $attrs = array_filter(array_merge($attrs, array(
            'url'    => $url,
            'alt'    => $this->attr($image, 'alt'),
            'title'  => $this->attr($image, 'title'),
            'srcset' => '' !== $this->attr($image, 'srcset') ? $this->attr($image, 'srcset') : (string) ($sourceAttrs['srcset'] ?? ''),
            'sizes'  => '' !== $this->attr($image, 'sizes') ? $this->attr($image, 'sizes') : (string) ($sourceAttrs['sizes'] ?? ''),
            'width'  => $width,
            'height' => $height,
        )), static fn ($value): bool => '' !== $value);

        $attrs = array_filter(array_merge($attrs, $this->imageIdentityAttributes($image, $figure)), static fn ($value): bool => '' !== $value);
        $attrs = array_filter(array_merge($attrs, $this->assetMetadataImageAttributes($url)), static fn ($value): bool => '' !== $value);

        if ( $figure instanceof DOMElement ) {
            $caption = $this->firstChildElement($figure, 'figcaption');
            if ( $caption instanceof DOMElement ) {
                $attrs['caption'] = $this->innerHtml($caption);
            }
        }

        return $this->createBlock('core/image', $attrs, array(), $figure ?? $image);
    }

    /**
     * @return array<string, string>
     */
    private function pictureSourceAttributes(DOMElement $picture): array
    {
        foreach ( $picture->childNodes as $child ) {
            if ( ! $child instanceof DOMElement || 'source' !== strtolower($child->tagName) ) {
                continue;
            }

            $srcset = $this->attr($child, 'srcset');
            if ( '' === $srcset || preg_match('/javascript\s*:/i', $srcset) ) {
                continue;
            }

            return array_filter(array(
                'srcset' => $srcset,
                'sizes'  => $this->attr($child, 'sizes'),
            ), static fn (string $value): bool => '' !== $value);
        }

        return array();
    }

    private function safeEmbedUrl(string $url): string
    {
        $url = trim($url);
        if ( '' === $url || ! preg_match('#^https?://#i', $url) ) {
            return '';
        }

        return preg_match('/[\x00-\x1f\x7f]|javascript\s*:/i', $url) ? '' : $url;
    }

    private function canonicalEmbedUrl(string $url): string
    {
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        $path = (string) parse_url($url, PHP_URL_PATH);

        if ( str_ends_with($host, 'youtube.com') && preg_match('~^/embed/([^/?#]+)~', $path, $matches) ) {
            return 'https://www.youtube.com/watch?v=' . $matches[1];
        }

        if ( 'youtu.be' === $host && '' !== trim($path, '/') ) {
            return 'https://www.youtube.com/watch?v=' . trim($path, '/');
        }

        if ( str_ends_with($host, 'vimeo.com') && preg_match('#/(?:video/)?(\d+)#', $path, $matches) ) {
            return 'https://vimeo.com/' . $matches[1];
        }

        return $url;
    }

    private function embedProviderSlug(string $url): string
    {
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        if ( str_ends_with($host, 'youtube.com') || 'youtu.be' === $host ) {
            return 'youtube';
        }
        if ( str_ends_with($host, 'vimeo.com') ) {
            return 'vimeo';
        }

        return '';
    }

    /**
     * @return array<string, string>
     */
    private function safeEmbedAttributes(DOMElement $element): array
    {
        $safe = array();
        $allowed = array_flip(array( 'allow', 'allowfullscreen', 'class', 'height', 'loading', 'referrerpolicy', 'sandbox', 'src', 'title', 'width' ));
        foreach ( $this->htmlAttributes($element) as $name => $value ) {
            if ( isset($allowed[$name]) && ! preg_match('/javascript\s*:/i', $value) ) {
                $safe[$name] = strlen($value) > 300 ? substr($value, 0, 300) . '...' : $value;
            }
        }

        return $safe;
    }

    /**
     * @param array<int, array<string, mixed>> $fallbacks
     * @return array<string, mixed>|null
     */
    private function convertIframeElement(DOMElement $iframe, array &$fallbacks): ?array
    {
        $url = $this->safeEmbedUrl($this->attr($iframe, 'src'));
        if ( '' !== $url ) {
            return $this->createBlock('core/embed', array_filter(array_merge($this->presentationAttributes($iframe), array(
                'url'              => $this->canonicalEmbedUrl($url),
                'type'             => 'video',
                'providerNameSlug' => $this->embedProviderSlug($url),
            )), static fn ($value): bool => '' !== $value), array(), $iframe);
        }

        $boundedHtml = $this->boundedFallbackHtml($this->safeFallbackHtml($iframe));
        $fallbacks[] = FallbackDiagnostic::build(array(
            'type'            => 'html',
            'reason'          => 'iframe_embed_fallback',
            'diagnostic_code' => 'html_iframe_embed_fallback',
            'message'         => 'Iframe embed HTML was preserved as sanitized bounded fallback metadata.',
            'source_format'   => 'html',
            'tag'             => 'iframe',
            'selector'        => $this->elementSelector($iframe),
            'attributes'      => $this->safeEmbedAttributes($iframe),
            'context'         => $this->sourceContext($iframe),
            'events'          => $this->eventMetadata($iframe),
            'html'            => $boundedHtml['html'],
            'html_bytes'      => $boundedHtml['bytes'],
            'html_truncated'  => $boundedHtml['truncated'],
        ), $this->fallbackProvenance);

        return null;
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

    /**
     * @param array<string, mixed> $options
     * @return array<string, array<string, mixed>>
     */
    private function assetMetadataFromOptions(array $options): array
    {
        $metadata = array();

        foreach ( array( $options['provenance'] ?? null, $options['context'] ?? null, $options ) as $container ) {
            if ( ! is_array($container) || ! isset($container['asset_metadata']) || ! is_array($container['asset_metadata']) ) {
                continue;
            }

            foreach ( $container['asset_metadata'] as $path => $asset ) {
                if ( ! is_string($path) || '' === trim($path) || ! is_array($asset) ) {
                    continue;
                }

                $metadata[trim($path)] = $asset;
            }
        }

        return $metadata;
    }

    /**
     * @return array<string, int|string>
     */
    private function assetMetadataImageAttributes(string $url): array
    {
        $asset = $this->assetMetadataForUrl($url);
        if ( null === $asset ) {
            return array();
        }

        $attrs = array();
        if ( isset($asset['id']) && ( is_int($asset['id']) || ( is_string($asset['id']) && ctype_digit($asset['id']) ) ) ) {
            $attrs['id'] = (int) $asset['id'];
        }

        if ( isset($asset['url']) && is_string($asset['url']) ) {
            $resolvedUrl = $this->safeResolvedAssetImageUrl(trim($asset['url']));
            if ( '' !== $resolvedUrl ) {
                $attrs['url'] = $resolvedUrl;
            }
        }

        return $attrs;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function assetMetadataForUrl(string $url): ?array
    {
        foreach ( $this->assetMetadataLookupKeys($url) as $key ) {
            if ( isset($this->assetMetadata[$key]) ) {
                return $this->assetMetadata[$key];
            }
        }

        return null;
    }

    /**
     * @return array<int, string>
     */
    private function assetMetadataLookupKeys(string $url): array
    {
        $keys = array();
        foreach ( array( trim($url), ltrim(trim($url), '/') ) as $key ) {
            if ( '' !== $key && ! in_array($key, $keys, true) ) {
                $keys[] = $key;
            }
        }

        $path = parse_url($url, PHP_URL_PATH);
        if ( is_string($path) ) {
            foreach ( array( $path, ltrim($path, '/') ) as $key ) {
                if ( '' !== $key && ! in_array($key, $keys, true) ) {
                    $keys[] = $key;
                }
            }
        }

        return $keys;
    }

    private function safeResolvedAssetImageUrl(string $url): string
    {
        if ( '' === $url || preg_match('/[\x00-\x1f\x7f]|javascript\s*:/i', $url) ) {
            return '';
        }

        return $this->safeImageUrl($url);
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

    /**
     * @return array<string, mixed>
     */
    private function codePresentationAttributes(DOMElement $pre, DOMElement $code): array
    {
        $attrs = $this->presentationAttributes($pre);
        $codeClassName = $this->attr($code, 'class');
        if ( '' !== trim($codeClassName) ) {
            $attrs['className'] = $this->mergeClassNames((string) ($attrs['className'] ?? ''), $codeClassName);
        }

        return array_filter($attrs, static fn ($value): bool => is_array($value) ? array() !== $value : '' !== trim((string) $value));
    }

    private function codeContent(DOMElement $code): string
    {
        foreach ( $code->childNodes as $child ) {
            if ( $child instanceof DOMElement ) {
                return $this->sanitizedSyntaxHtml($code);
            }
        }

        return $code->textContent ?? '';
    }

    /**
     * @param array<int, array<string, mixed>> $fallbacks
     * @return array<string, mixed>|null
     */
    private function codeWindowBlockFromElement(DOMElement $element, array &$fallbacks): ?array
    {
        $pre = $this->firstChildElement($element, 'pre');
        if ( ! $pre instanceof DOMElement ) {
            return null;
        }

        $code = $this->firstChildElement($pre, 'code');
        if ( ! $code instanceof DOMElement ) {
            return null;
        }

        $label = $this->codeWindowLabel($element, $pre);
        if ( '' === $label && ! $this->hasClass($element, 'code-window') && ! $this->hasClass($element, 'code-frame') ) {
            return null;
        }

        $children = array();
        if ( '' !== $label ) {
            $children[] = $this->createBlock('core/paragraph', array( 'content' => $label ));
        }
        $children[] = $this->createBlock('core/code', array_merge($this->codePresentationAttributes($pre, $code), array( 'content' => $this->codeContent($code) )), array(), $pre);

        return $this->createBlock('core/group', $this->presentationAttributes($element), $children, $element);
    }

    private function codeWindowLabel(DOMElement $element, DOMElement $pre): string
    {
        foreach ( array( 'data-label', 'data-title', 'data-filename', 'aria-label' ) as $attribute ) {
            $value = trim($this->attr($element, $attribute));
            if ( '' !== $value ) {
                return htmlspecialchars($value, ENT_NOQUOTES | ENT_SUBSTITUTE, 'UTF-8');
            }
        }

        foreach ( $element->childNodes as $child ) {
            if ( ! $child instanceof DOMElement || $child->isSameNode($pre) ) {
                continue;
            }

            $tagName = strtolower($child->tagName);
            if ( 'figcaption' === $tagName || 'header' === $tagName || $this->hasClass($child, 'code-label') || $this->hasClass($child, 'filename') || $this->hasClass($child, 'window-title') ) {
                return $this->innerHtml($child);
            }
        }

        return '';
    }

    private function sanitizedSyntaxHtml(DOMElement $element): string
    {
        $html = '';
        foreach ( $element->childNodes as $child ) {
            if ( XML_TEXT_NODE === $child->nodeType ) {
                $html .= htmlspecialchars($child->textContent ?? '', ENT_NOQUOTES | ENT_SUBSTITUTE, 'UTF-8');
                continue;
            }

            if ( ! $child instanceof DOMElement ) {
                continue;
            }

            $tagName = strtolower($child->tagName);
            if ( in_array($tagName, array( 'span', 'mark', 'b', 'strong', 'i', 'em' ), true) ) {
                $attrs = array_intersect_key($this->htmlAttributes($child), array_flip(array( 'class', 'data-token', 'title' )));
                $attrs = array_filter($attrs, static fn (string $value): bool => '' !== $value && strlen($value) <= 200 && ! preg_match('/javascript\s*:/i', $value));
                $html .= '<' . $tagName . $this->htmlAttributeString($attrs) . '>' . $this->sanitizedSyntaxHtml($child) . '</' . $tagName . '>';
                continue;
            }

            $html .= htmlspecialchars($child->textContent ?? '', ENT_NOQUOTES | ENT_SUBSTITUTE, 'UTF-8');
        }

        return $html;
    }

    /**
     * @param array<string, string> $attrs
     */
    private function htmlAttributeString(array $attrs): string
    {
        $html = '';
        foreach ( $attrs as $name => $value ) {
            $html .= ' ' . $name . '="' . htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '"';
        }
        return $html;
    }

    /**
     * @return array<int, array<string, string>>
     */
    private function eventMetadata(DOMElement $element): array
    {
        $events = array();
        foreach ( $this->htmlAttributes($element) as $name => $value ) {
            if ( preg_match('/^on([a-z]+)$/i', $name, $matches) ) {
                $events[] = array(
                    'type'      => strtolower($matches[1]),
                    'attribute' => strtolower($name),
                );
            }
            if ( preg_match('/^data-(?:action|on|event)$/i', $name) && '' !== trim($value) ) {
                $events[] = array(
                    'type'      => 'declared',
                    'attribute' => $name,
                );
            }
        }

        return $events;
    }

}
