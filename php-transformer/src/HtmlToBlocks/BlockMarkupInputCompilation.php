<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks;

use Automattic\BlocksEngine\PhpTransformer\Contract\BlockCompilationOutput;
use Automattic\BlocksEngine\PhpTransformer\Contract\ConversionReportProjection;
use Automattic\BlocksEngine\PhpTransformer\Contract\TransformationOptions;
use Automattic\BlocksEngine\PhpTransformer\Contract\TransformerResult;
use Automattic\BlocksEngine\PhpTransformer\WordPress\Runtime;
use Closure;

/**
 * Block markup as transform() input.
 *
 * Input that already carries block delimiters is parsed with the runtime block
 * parser. Every parsed block is kept as written; only HTML that no block owns
 * is converted through the HTML path and spliced back at its position:
 *
 * - top-level freeform (null-name) blocks with element or text content;
 * - loose HTML inside a container block, between the container's own wrapper
 *   tags, before, between or after its inner blocks.
 *
 * Whitespace-only and comment-only strings stay as they are. HTML that cannot be
 * split cleanly from a block's own markup, or that converts to no blocks, is
 * left in place and reported rather than dropped.
 */
final class BlockMarkupInputCompilation
{
    private const DELIMITER_PATTERN = '/<!--\s+\/?wp:[a-z][a-z0-9_-]*(?:\/[a-z][a-z0-9_-]*)?[\s\/]/';

    /** Statuses in the order the result contract defines them, best first. */
    private const STATUS_RANK = array( 'success', 'success_with_warnings', 'failed' );

    /**
     * Containers whose saved markup carries the block's own content (text,
     * captions, summaries) in the strings around their inner blocks. Only the
     * strings between inner blocks are treated as loose HTML for these.
     */
    private const OWN_CONTENT_CONTAINERS = array(
        'core/list-item',
        'core/quote',
        'core/pullquote',
        'core/details',
        'core/gallery',
        'core/navigation-submenu',
    );

    /**
     * Wrapper containers whose loose HTML is converted even when they have no
     * inner blocks yet (the whole inner content is loose). Values name the
     * class of the inner wrapper element, or '' for the outermost element.
     */
    private const EMPTY_WRAPPER_CONTAINERS = array(
        'core/group'  => '',
        'core/column' => '',
        'core/cover'  => 'wp-block-cover__inner-container',
    );

    private const VOID_ELEMENTS = array( 'area', 'base', 'br', 'col', 'embed', 'hr', 'img', 'input', 'link', 'meta', 'param', 'source', 'track', 'wbr' );

    private const RAW_TEXT_ELEMENTS = array( 'script', 'style', 'textarea', 'title', 'xmp', 'iframe', 'noembed', 'noframes' );

    /** @var Closure(string, array<string, mixed>): TransformerResult */
    private Closure $convertHtml;

    /** @var array<string, mixed> */
    private array $options = array();

    /** @var array<int, TransformerResult> */
    private array $pieces = array();

    /** @var array<int, array<string, mixed>> */
    private array $diagnostics = array();

    private string $status = 'success';

    private int $unconvertedCount = 0;

    /**
     * @param Closure(string, array<string, mixed>): TransformerResult $convertHtml Converts one HTML fragment through the HTML path.
     */
    public function __construct(
        private readonly Runtime $runtime,
        Closure $convertHtml
    ) {
        $this->convertHtml = $convertHtml;
    }

    public static function containsBlockDelimiters(string $html): bool
    {
        return 1 === preg_match(self::DELIMITER_PATTERN, $html);
    }

    /** @param array<string, mixed> $options */
    public function transform(string $html, array $options = array()): TransformerResult
    {
        $startedAt = hrtime(true);
        $this->options = $options;
        $this->pieces = array();
        $this->diagnostics = array();
        $this->status = 'success';
        $this->unconvertedCount = 0;

        $parsed = $this->parsedBlocks($html);
        if ( null === $parsed ) {
            return $this->htmlPathResult($html, $options, 'block_markup_input_unparsed', 'The input contains block delimiters that could not be parsed into blocks; it was converted as plain HTML and the delimiters were not preserved.');
        }
        if ( ! self::delimitersAreStructural($parsed) ) {
            return $this->htmlPathResult($html, $options, 'block_markup_input_stray_delimiters', 'The input contains block delimiters inside HTML elements (for example pasted into a text widget), so it is an HTML document rather than block markup; it was converted as HTML and those delimiters were not preserved.');
        }

        $blocks = array();
        $index = 0;
        foreach ( $parsed as $block ) {
            if ( null !== ( $block['blockName'] ?? null ) ) {
                $blocks[] = $this->processBlock($block, (string) $index);
                ++$index;
                continue;
            }

            $freeform = (string) ( $block['innerHTML'] ?? '' );
            $converted = self::hasContent($freeform) ? $this->convertTopLevelFreeform($freeform, 'top-level HTML at position ' . $index) : null;
            if ( null === $converted ) {
                $blocks[] = $block;
                ++$index;
                continue;
            }
            foreach ( $converted as $convertedBlock ) {
                $blocks[] = $convertedBlock;
                ++$index;
            }
        }

        $serialized = $this->runtime->serializeBlocks($blocks);

        return $this->result($html, $parsed, $blocks, $serialized, $startedAt);
    }

    /**
     * @return array<int, array<string, mixed>>|null Parsed blocks, or null when no named block could be parsed.
     */
    private function parsedBlocks(string $html): ?array
    {
        $parsed = array_values(array_filter($this->runtime->parseBlocks($html), 'is_array'));
        foreach ( $parsed as $block ) {
            if ( is_string($block['blockName'] ?? null) && '' !== $block['blockName'] ) {
                return $parsed;
            }
        }

        return null;
    }

    /**
     * Block markup sits at element boundaries: every top-level block and every
     * top-level freeform run is a self-contained HTML fragment. A delimiter that
     * opens inside one element and closes inside another is a stray comment in
     * an HTML document, not a block.
     *
     * @param array<int, array<string, mixed>> $parsed
     */
    private static function delimitersAreStructural(array $parsed): bool
    {
        foreach ( $parsed as $block ) {
            $html = self::staticHtml($block);
            if ( self::hasContent($html) && ! self::isBalanced(trim($html)) ) {
                return false;
            }
        }

        return true;
    }

    /** @param array<string, mixed> $block */
    private static function staticHtml(array $block): string
    {
        $innerBlocks = is_array($block['innerBlocks'] ?? null) ? array_values($block['innerBlocks']) : array();
        $html = '';
        $index = 0;
        foreach ( is_array($block['innerContent'] ?? null) ? $block['innerContent'] : array() as $part ) {
            if ( is_string($part) ) {
                $html .= $part;
                continue;
            }
            $inner = $innerBlocks[$index] ?? null;
            ++$index;
            $html .= is_array($inner) ? self::staticHtml($inner) : '';
        }

        return $html;
    }

    /**
     * The input is converted as HTML: never report success for a result that
     * no longer carries the delimiters it was given.
     *
     * @param array<string, mixed> $options
     */
    private function htmlPathResult(string $html, array $options, string $code, string $message): TransformerResult
    {
        $result = ( $this->convertHtml )($html, $options);
        $diagnostic = array(
            'code'        => $code,
            'message'     => $message,
            'source'      => HtmlTransformer::class,
            'reason_code' => $code,
            'severity'    => 'warning',
        );

        return new TransformerResult(
            status: self::worseStatus($result->status, TransformationOptions::context($options)['strict'] ? 'failed' : 'success_with_warnings'),
            components: $result->components,
            blockTypes: $result->blockTypes,
            sourceReports: $result->sourceReports,
            blocks: $result->blocks,
            serializedBlocks: $result->serializedBlocks,
            documents: $result->documents,
            assets: $result->assets,
            diagnostics: array_merge($result->diagnostics, array( $diagnostic )),
            fallbacks: $result->fallbacks,
            provenance: $result->provenance,
            coverage: $result->coverage,
            context: $result->context,
            metrics: array_merge($result->metrics, array( 'diagnostic_count' => count($result->diagnostics) + 1 )),
            blockCompilationOutput: $result->blockCompilationOutput
        );
    }

    /**
     * Keep a named block as written; convert loose HTML inside it in place.
     *
     * @param array<string, mixed> $block
     * @return array<string, mixed>
     */
    private function processBlock(array $block, string $path): array
    {
        $name = (string) $block['blockName'];
        $innerBlocks = is_array($block['innerBlocks'] ?? null) ? array_values($block['innerBlocks']) : array();
        $innerContent = is_array($block['innerContent'] ?? null) ? array_values($block['innerContent']) : array();

        $stringCount = count(array_filter($innerContent, 'is_string'));
        if ( 0 === $stringCount && array() === $innerBlocks ) {
            return $block;
        }

        $lastIndex = count($innerContent) - 1;
        $newContent = array();
        $newBlocks = array();
        $blockIndex = 0;
        $changed = false;

        foreach ( $innerContent as $position => $part ) {
            if ( ! is_string($part) ) {
                $inner = $innerBlocks[$blockIndex] ?? null;
                ++$blockIndex;
                if ( is_array($inner) ) {
                    $processed = $this->processBlock($inner, $path . '.' . ( $blockIndex - 1 ));
                    $changed = $changed || $processed !== $inner;
                    $newContent[] = null;
                    $newBlocks[] = $processed;
                }
                continue;
            }

            $range = $this->looseRange($name, $part, 0 === $position, $lastIndex === $position, array() !== $innerBlocks);
            if ( null === $range ) {
                $newContent[] = $part;
                continue;
            }

            [ $start, $end ] = $range;
            $loose = substr($part, $start, $end - $start);
            $start += strlen($loose) - strlen(ltrim($loose));
            $loose = trim($loose);
            $end = $start + strlen($loose);

            $converted = self::hasContent($loose) && self::isBalanced($loose)
                ? $this->convertPiece($loose, 'loose HTML inside ' . $name . ' (block ' . $path . ')')
                : null;
            if ( null === $converted ) {
                $newContent[] = $part;
                continue;
            }

            $changed = true;
            $prefix = substr($part, 0, $start);
            $suffix = substr($part, $end);
            if ( '' !== $prefix ) {
                $newContent[] = $prefix;
            }
            foreach ( $converted as $convertedBlock ) {
                $newContent[] = null;
                $newBlocks[] = $convertedBlock;
            }
            if ( '' !== $suffix ) {
                $newContent[] = $suffix;
            }
        }

        if ( ! $changed ) {
            return $block;
        }

        $innerHtml = '';
        foreach ( $newContent as $part ) {
            if ( is_string($part) ) {
                $innerHtml .= $part;
            }
        }

        $block['innerBlocks'] = $newBlocks;
        $block['innerContent'] = $newContent;
        $block['innerHTML'] = $innerHtml;

        return $block;
    }

    /**
     * Byte range of loose HTML inside one innerContent string, or null when the
     * string belongs entirely to the block's own markup.
     *
     * @return array{0: int, 1: int}|null
     */
    private function looseRange(string $name, string $part, bool $first, bool $last, bool $hasInnerBlocks): ?array
    {
        if ( ! self::hasContent($part) ) {
            return null;
        }

        if ( ! $hasInnerBlocks ) {
            return array_key_exists($name, self::EMPTY_WRAPPER_CONTAINERS)
                ? self::wrapperContentRange($part, self::EMPTY_WRAPPER_CONTAINERS[$name])
                : null;
        }

        if ( ! $first && ! $last ) {
            return array( 0, strlen($part) );
        }

        if ( in_array($name, self::OWN_CONTENT_CONTAINERS, true) ) {
            return null;
        }

        if ( $first ) {
            $start = self::openingWrapperEnd($part);
            return null === $start ? null : array( $start, strlen($part) );
        }

        $end = self::closingWrapperStart($part);
        return null === $end ? null : array( 0, $end );
    }

    /**
     * Offset just after the innermost element the string leaves open: the
     * wrapper that holds the inner blocks. Anything after it is loose.
     */
    private static function openingWrapperEnd(string $html): ?int
    {
        $stack = array();
        foreach ( self::tags($html) as $tag ) {
            if ( 'open' === $tag['kind'] ) {
                $stack[] = $tag;
            } elseif ( 'close' === $tag['kind'] ) {
                if ( ! self::popTo($stack, $tag['name']) ) {
                    return null;
                }
            }
        }

        $innermost = end($stack);

        return false === $innermost ? 0 : $innermost['end'];
    }

    /** Offset of the first closing tag the string did not open: the wrapper end. */
    private static function closingWrapperStart(string $html): ?int
    {
        $stack = array();
        foreach ( self::tags($html) as $tag ) {
            if ( 'open' === $tag['kind'] ) {
                $stack[] = $tag;
            } elseif ( 'close' === $tag['kind'] ) {
                if ( array() === $stack ) {
                    return $tag['start'];
                }
                if ( ! self::popTo($stack, $tag['name']) ) {
                    return null;
                }
            }
        }

        return array() === $stack ? strlen($html) : null;
    }

    /**
     * Inner content range of a wrapper container with no inner blocks: the
     * outermost element, or the descendant carrying $innerClass.
     *
     * @return array{0: int, 1: int}|null
     */
    private static function wrapperContentRange(string $html, string $innerClass): ?array
    {
        $stack = array();
        $target = null;
        foreach ( self::tags($html) as $tag ) {
            if ( 'open' === $tag['kind'] ) {
                $tag['depth'] = count($stack);
                $stack[] = $tag;
                if ( null === $target && ( '' === $innerClass ? 0 === $tag['depth'] : self::hasClass($tag['raw'], $innerClass) ) ) {
                    $target = $tag;
                }
                continue;
            }
            if ( 'close' !== $tag['kind'] ) {
                continue;
            }
            $open = null;
            for ( $i = count($stack) - 1; $i >= 0; --$i ) {
                if ( $stack[$i]['name'] === $tag['name'] ) {
                    $open = $stack[$i];
                    $stack = array_slice($stack, 0, $i);
                    break;
                }
            }
            if ( null === $open ) {
                return null;
            }
            if ( null !== $target && $open['start'] === $target['start'] ) {
                return array( $target['end'], $tag['start'] );
            }
        }

        return null;
    }

    private static function hasClass(string $tag, string $class): bool
    {
        return 1 === preg_match('/\sclass\s*=\s*(["\'])(?:[^"\']*\s)?' . preg_quote($class, '/') . '(?:\s[^"\']*)?\1/i', $tag);
    }

    /** @param array<int, array<string, mixed>> $stack */
    private static function popTo(array &$stack, string $name): bool
    {
        for ( $i = count($stack) - 1; $i >= 0; --$i ) {
            if ( $stack[$i]['name'] === $name ) {
                $stack = array_slice($stack, 0, $i);
                return true;
            }
        }

        return false;
    }

    /** Every element opened in the fragment closes in it, and nothing else closes. */
    private static function isBalanced(string $html): bool
    {
        $stack = array();
        foreach ( self::tags($html) as $tag ) {
            if ( 'open' === $tag['kind'] ) {
                $stack[] = $tag['name'];
            } elseif ( 'close' === $tag['kind'] ) {
                if ( array() === $stack || array_pop($stack) !== $tag['name'] ) {
                    return false;
                }
            }
        }

        return array() === $stack;
    }

    /**
     * @return array<int, array{kind: string, name: string, start: int, end: int, raw: string}>
     */
    private static function tags(string $html): array
    {
        $tags = array();
        $offset = 0;
        $length = strlen($html);
        while ( $offset < $length && false !== ( $lt = strpos($html, '<', $offset) ) ) {
            if ( str_starts_with(substr($html, $lt, 4), '<!--') ) {
                $close = strpos($html, '-->', $lt + 4);
                $offset = false === $close ? $length : $close + 3;
                continue;
            }
            if ( ! preg_match('/\G<(\/?)([a-zA-Z][a-zA-Z0-9:-]*)(?:\s+(?:[^\s"\'>\/=]+(?:\s*=\s*(?:"[^"]*"|\'[^\']*\'|[^\s"\'>]+))?|\/(?!>)))*\s*(\/?)>/', $html, $match, 0, $lt) ) {
                $offset = $lt + 1;
                continue;
            }
            $name = strtolower($match[2]);
            $end = $lt + strlen($match[0]);
            $kind = '' !== $match[1] ? 'close' : ( '' !== $match[3] || in_array($name, self::VOID_ELEMENTS, true) ? 'void' : 'open' );
            $tags[] = array( 'kind' => $kind, 'name' => $name, 'start' => $lt, 'end' => $end, 'raw' => $match[0] );
            $offset = $end;
            if ( 'open' === $kind && in_array($name, self::RAW_TEXT_ELEMENTS, true) ) {
                $closeAt = stripos($html, '</' . $name, $end);
                $offset = false === $closeAt ? $length : $closeAt;
            }
        }

        return $tags;
    }

    /** Element or text content once comments and whitespace are removed. */
    private static function hasContent(string $html): bool
    {
        $withoutComments = preg_replace('/<!--.*?(?:-->|$)/s', '', $html) ?? $html;

        return '' !== trim($withoutComments);
    }

    /**
     * Top-level freeform HTML: convert it when it is a self-contained fragment.
     *
     * @return array<int, array<string, mixed>>|null
     */
    private function convertTopLevelFreeform(string $html, string $label): ?array
    {
        $trimmed = trim($html);
        if ( ! self::isBalanced($trimmed) ) {
            $this->reportUnconverted($label, 'its tags open or close outside the fragment, so it cannot be converted without the surrounding blocks');
            return null;
        }

        $converted = $this->convertPiece($trimmed, $label);
        if ( null === $converted ) {
            return null;
        }

        // Keep the whitespace that separated the freeform HTML from its neighbours.
        $leading = substr($html, 0, strlen($html) - strlen(ltrim($html)));
        $trailing = substr($html, strlen(rtrim($html)));
        $blocks = array();
        if ( '' !== $leading ) {
            $blocks[] = self::freeform($leading);
        }
        array_push($blocks, ...$converted);
        if ( '' !== $trailing ) {
            $blocks[] = self::freeform($trailing);
        }

        return $blocks;
    }

    /** @return array<string, mixed> */
    private static function freeform(string $html): array
    {
        return array(
            'blockName'    => null,
            'attrs'        => array(),
            'innerBlocks'  => array(),
            'innerHTML'    => $html,
            'innerContent' => array( $html ),
        );
    }

    /**
     * Convert one HTML fragment and return its blocks as parsed from the
     * fragment's serialized output, so re-serializing them reproduces it.
     *
     * @return array<int, array<string, mixed>>|null Null when the fragment produced no blocks and stays as written.
     */
    private function convertPiece(string $html, string $label): ?array
    {
        if ( ! self::hasContent($html) ) {
            return null;
        }
        if ( ! self::isBalanced($html) ) {
            $this->reportUnconverted($label, 'its tags open or close outside the fragment');
            return null;
        }

        $result = ( $this->convertHtml )($html, $this->options);
        $blocks = array();
        foreach ( $this->runtime->parseBlocks($result->serializedBlocks) as $block ) {
            if ( ! is_array($block) ) {
                continue;
            }
            if ( null === ( $block['blockName'] ?? null ) && ! self::hasContent((string) ( $block['innerHTML'] ?? '' )) ) {
                continue;
            }
            $blocks[] = $block;
        }

        $this->pieces[] = $result;
        if ( 'success' !== $result->status ) {
            $this->status = self::worseStatus($this->status, $result->status);
            $this->diagnostics[] = array(
                'code'        => 'block_markup_input_piece_status',
                'message'     => sprintf('Converting %s returned status "%s"; see its fallbacks and diagnostics.', $label, $result->status),
                'source'      => HtmlTransformer::class,
                'reason_code' => 'block_markup_input_piece_status',
                'severity'    => 'warning',
                'piece'       => $label,
                'status'      => $result->status,
            );
        }

        if ( array() === $blocks ) {
            $this->reportUnconverted($label, 'it converted to no blocks');
            return null;
        }

        return $blocks;
    }

    private function reportUnconverted(string $label, string $reason): void
    {
        ++$this->unconvertedCount;
        $this->status = self::worseStatus($this->status, $this->warningStatus());
        $this->diagnostics[] = array(
            'code'        => 'block_markup_input_html_kept',
            'message'     => sprintf('Kept %s as written because %s.', $label, $reason),
            'source'      => HtmlTransformer::class,
            'reason_code' => 'block_markup_input_html_kept',
            'severity'    => 'warning',
            'piece'       => $label,
        );
    }

    private function warningStatus(): string
    {
        return TransformationOptions::context($this->options)['strict'] ? 'failed' : 'success_with_warnings';
    }

    private static function worseStatus(string $a, string $b): string
    {
        $rankA = array_search($a, self::STATUS_RANK, true);
        $rankB = array_search($b, self::STATUS_RANK, true);
        $rankA = false === $rankA ? count(self::STATUS_RANK) - 1 : $rankA;
        $rankB = false === $rankB ? count(self::STATUS_RANK) - 1 : $rankB;

        return self::STATUS_RANK[max($rankA, $rankB)];
    }

    /**
     * @param array<int, array<string, mixed>> $parsed
     * @param array<int, array<string, mixed>> $blocks
     */
    private function result(string $html, array $parsed, array $blocks, string $serialized, int $startedAt): TransformerResult
    {
        $inputNames = self::blockNameCounts($parsed);
        $outputNames = self::blockNameCounts($this->runtime->parseBlocks($serialized));
        $missing = array();
        foreach ( $inputNames as $name => $count ) {
            if ( ( $outputNames[$name] ?? 0 ) < $count ) {
                $missing[] = $name;
            }
        }
        if ( array() !== $missing ) {
            $this->status = self::worseStatus($this->status, $this->warningStatus());
            $this->diagnostics[] = array(
                'code'        => 'block_markup_input_blocks_missing',
                'message'     => 'Input blocks are missing from the output: ' . implode(', ', $missing) . '.',
                'source'      => HtmlTransformer::class,
                'reason_code' => 'block_markup_input_blocks_missing',
                'severity'    => 'warning',
                'blocks'      => $missing,
            );
        }

        $keptCount = array_sum($inputNames);
        array_unshift($this->diagnostics, array(
            'code'        => 'block_markup_input',
            'message'     => sprintf(
                'Input carried block markup: kept %d parsed block(s) as written and converted %d HTML fragment(s) outside them%s.',
                $keptCount,
                count($this->pieces),
                0 === $this->unconvertedCount ? '' : sprintf('; kept %d fragment(s) as HTML', $this->unconvertedCount)
            ),
            'source'      => HtmlTransformer::class,
            'reason_code' => 'block_markup_input',
            'severity'    => 'info',
        ));

        $assets = array();
        $diagnostics = array();
        $fallbacks = array();
        $components = array();
        $blockTypes = array();
        $documents = array();
        $coverage = array();
        $sourceReports = array();
        $metrics = array();
        $provenance = array(
            array_merge(array(
                'source_format' => 'html',
                'input_format'  => 'block_markup',
                'input_bytes'   => strlen($html),
                'transformer'   => HtmlTransformer::class,
            ), TransformationOptions::provenance($this->options)),
        );
        foreach ( $this->pieces as $piece ) {
            $assets = self::mergeAssets($assets, $piece->assets);
            $diagnostics = array_merge($diagnostics, $piece->diagnostics);
            $fallbacks = array_merge($fallbacks, $piece->fallbacks);
            $components = self::appendUnique($components, $piece->components);
            $blockTypes = self::appendUnique($blockTypes, $piece->blockTypes);
            $documents = self::appendUnique($documents, $piece->documents);
            $coverage = self::appendUnique($coverage, $piece->coverage);
            $provenance = self::appendUnique($provenance, $piece->provenance);
            $pieceReports = $piece->sourceReports;
            unset($pieceReports['conversion_report']);
            $sourceReports = self::mergeReports($sourceReports, $pieceReports);
            $metrics = self::mergeMetrics($metrics, $piece->metrics);
        }
        $diagnostics = array_merge($this->diagnostics, $diagnostics);

        $metrics = array_merge($metrics, array(
            'input_bytes'                     => strlen($html),
            'block_count'                     => self::countBlocks($blocks),
            'diagnostic_count'                => count($diagnostics),
            'transform_duration_ms'           => ( hrtime(true) - $startedAt ) / 1000000,
            'output_bytes'                    => strlen($serialized),
            'block_markup_kept_block_count'   => $keptCount,
            'block_markup_converted_fragment_count' => count($this->pieces),
            'block_markup_kept_html_fragment_count' => $this->unconvertedCount,
        ));
        /** @var array<string, int|float> $metrics */
        $sourceReports['conversion_report'] = ConversionReportProjection::fromResultParts('html', $blocks, $fallbacks, $sourceReports, $assets, $provenance, $metrics);

        return new TransformerResult(
            status: $this->status,
            components: $components,
            blockTypes: $blockTypes,
            sourceReports: $sourceReports,
            blocks: $blocks,
            serializedBlocks: $serialized,
            documents: $documents,
            assets: $assets,
            diagnostics: $diagnostics,
            fallbacks: $fallbacks,
            provenance: $provenance,
            coverage: $coverage,
            context: TransformationOptions::context($this->options),
            metrics: $metrics,
            blockCompilationOutput: BlockCompilationOutput::empty()
        );
    }

    /**
     * @param array<int, mixed> $blocks
     * @return array<string, int>
     */
    private static function blockNameCounts(array $blocks): array
    {
        $counts = array();
        foreach ( $blocks as $block ) {
            if ( ! is_array($block) ) {
                continue;
            }
            if ( is_string($block['blockName'] ?? null) && '' !== $block['blockName'] ) {
                $counts[$block['blockName']] = ( $counts[$block['blockName']] ?? 0 ) + 1;
            }
            foreach ( self::blockNameCounts(is_array($block['innerBlocks'] ?? null) ? $block['innerBlocks'] : array()) as $name => $count ) {
                $counts[$name] = ( $counts[$name] ?? 0 ) + $count;
            }
        }

        return $counts;
    }

    /** @param array<int, array<string, mixed>> $blocks */
    private static function countBlocks(array $blocks): int
    {
        $count = 0;
        foreach ( $blocks as $block ) {
            if ( null === ( $block['blockName'] ?? null ) ) {
                continue;
            }
            ++$count;
            $count += self::countBlocks(is_array($block['innerBlocks'] ?? null) ? $block['innerBlocks'] : array());
        }

        return $count;
    }

    /**
     * Assets are keyed by path; the same support stylesheet emitted by two
     * fragments is returned once.
     *
     * @param array<int, array<string, mixed>> $assets
     * @param array<int, array<string, mixed>> $more
     * @return array<int, array<string, mixed>>
     */
    private static function mergeAssets(array $assets, array $more): array
    {
        $seen = array();
        foreach ( $assets as $asset ) {
            $seen[self::assetKey($asset)] = true;
        }
        foreach ( $more as $asset ) {
            $key = self::assetKey($asset);
            if ( isset($seen[$key]) ) {
                continue;
            }
            $seen[$key] = true;
            $assets[] = $asset;
        }

        return $assets;
    }

    /** @param array<string, mixed> $asset */
    private static function assetKey(array $asset): string
    {
        foreach ( array( 'path', 'target_path', 'id' ) as $key ) {
            if ( is_string($asset[$key] ?? null) && '' !== $asset[$key] ) {
                return $key . ':' . $asset[$key];
            }
        }

        return 'hash:' . md5(serialize($asset));
    }

    /**
     * @param array<int, mixed> $list
     * @param array<int, mixed> $more
     * @return array<int, mixed>
     */
    private static function appendUnique(array $list, array $more): array
    {
        $seen = array();
        foreach ( $list as $item ) {
            $seen[md5(serialize($item))] = true;
        }
        foreach ( $more as $item ) {
            $key = md5(serialize($item));
            if ( ! isset($seen[$key]) ) {
                $seen[$key] = true;
                $list[] = $item;
            }
        }

        return $list;
    }

    /**
     * Metrics merge per key by type: numbers add, arrays merge, anything else
     * keeps the last value.
     *
     * @param array<string, mixed> $metrics
     * @param array<string, mixed> $more
     * @return array<string, mixed>
     */
    private static function mergeMetrics(array $metrics, array $more): array
    {
        foreach ( $more as $key => $value ) {
            $current = $metrics[$key] ?? null;
            if ( ( is_int($value) || is_float($value) ) && ( is_int($current) || is_float($current) ) ) {
                $metrics[$key] = $current + $value;
            } elseif ( is_array($value) && is_array($current) ) {
                $metrics[$key] = array_merge($current, $value);
            } else {
                $metrics[$key] = $value;
            }
        }

        return $metrics;
    }

    /**
     * Reports merge structurally: lists append (without repeats), maps merge per
     * key, scalars keep the last value. Report scalars are often ratios or
     * statuses, so they are never added.
     *
     * @param array<mixed> $reports
     * @param array<mixed> $more
     * @return array<mixed>
     */
    private static function mergeReports(array $reports, array $more): array
    {
        if ( array_is_list($reports) && array_is_list($more) ) {
            return self::appendUnique($reports, $more);
        }

        foreach ( $more as $key => $value ) {
            $current = $reports[$key] ?? null;
            $reports[$key] = is_array($value) && is_array($current) ? self::mergeReports($current, $value) : $value;
        }

        return $reports;
    }
}
