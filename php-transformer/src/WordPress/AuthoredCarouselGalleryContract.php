<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\WordPress;

/**
 * A site import publishes an authored carousel as a core block an editor
 * changes with core tools: a core Gallery when every slide is an image, a core
 * Group otherwise (slides that pair an image with a caption, a link or text).
 * The navigable stage the source published around those slides (the generated
 * carousel block's saved shell) is recorded in the host's metadata under
 * METADATA_KEY, and the theme runtime renders it around the host's slides on
 * the front end, loading the behavior and styles of the generated
 * `<namespace>/LOCAL_NAME` block type.
 */
final class AuthoredCarouselGalleryContract
{
    public const LOCAL_NAME = 'authored-carousel';
    public const METADATA_KEY = 'blocksEngineCarousel';
    /** Core blocks that host a carousel's slides. */
    public const HOST_BLOCKS = array('core/gallery', 'core/group');
    /**
     * Class the host carries in the editor, where no stage is rendered: the
     * carousel block type's editor style lays a slideshow's stacked slides out
     * in flow, as the generated block's own editor does.
     */
    public const EDITOR_HOST_CLASS = 'blocks-engine-authored-carousel-host';
    /** The host's saved wrapper (gallery figure or group div) whose slides the shell surrounds. */
    public const WRAPPER_PATTERN = '/^\\s*<(figure|div)\\b[^>]*>(.*)<\\/\\1>\\s*$/s';

    /**
     * The core block that hosts a carousel of these slides.
     *
     * @param list<array<string, mixed>> $slides
     */
    public static function hostBlockName(array $slides): string
    {
        return array() !== $slides && array() === array_filter($slides, static fn (array $slide): bool => 'core/image' !== ($slide['blockName'] ?? null))
            ? 'core/gallery'
            : 'core/group';
    }

    /**
     * The carousel a block publishes: the generated carousel block itself, or
     * a core host recording its stage. Returns the carousel's attributes, or
     * null when the block is no carousel.
     *
     * @param array<string, mixed> $block Parsed block.
     * @return array<string, mixed>|null
     */
    public static function carouselAttributes(array $block): ?array
    {
        $name = (string) ($block['blockName'] ?? '');
        if (str_ends_with($name, '/' . self::LOCAL_NAME)) return is_array($block['attrs'] ?? null) ? $block['attrs'] : array();
        if (! in_array($name, self::HOST_BLOCKS, true)) return null;
        $shell = $block['attrs']['metadata'][self::METADATA_KEY] ?? null;
        return is_array($shell) && is_array($shell['attributes'] ?? null) ? $shell['attributes'] : null;
    }

    /**
     * Front-end markup of a rendered host: the recorded shell around the
     * host's slides, as the theme runtime renders it. Returns the markup
     * unchanged when the host records no carousel shell.
     *
     * @param array<string, mixed> $attributes Host block attributes.
     */
    public static function render(string $content, array $attributes): string
    {
        $shell = $attributes['metadata'][self::METADATA_KEY] ?? null;
        if (! is_array($shell) || ! is_string($shell['opening'] ?? null) || ! is_string($shell['closing'] ?? null)) return $content;
        if (! preg_match(self::WRAPPER_PATTERN, $content, $match)) return $content;
        return $shell['opening'] . $match[2] . $shell['closing'];
    }

    /**
     * Publish only after shared chrome and stylesheet planning have seen the
     * original stage DOM. Moving it into JSON earlier hides selector carriers
     * and changes site-wide CSS reachability and preset decisions.
     */
    public static function publishMarkup(string $markup): string
    {
        return self::applyEdits($markup, self::publicationEdits($markup));
    }

    /**
     * The byte edits that publish every carousel in `$markup` as its core
     * host. Each carousel's saved opening (its block comment and the shell
     * before its first slide) and closing (the shell after its last slide and
     * its closing comment) are replaced; the slides' bytes and every other
     * block stay exactly as saved, so the document keeps its block count and
     * anything anchored to a block outside a carousel shell only moves by the
     * length the shells before it changed.
     *
     * @return list<array{offset:int,length:int,text:string}> Non-overlapping, in document order.
     */
    public static function publicationEdits(string $markup): array
    {
        if (! str_contains($markup, '/' . self::LOCAL_NAME)) return array();
        $runtime = new Runtime();
        $index = new \Automattic\BlocksEngine\PhpTransformer\Support\BlockMarkupIndex($markup);
        $names = array();
        foreach ($index->tokens() as $token) if (! $token['closing']) $names[$token['offset']] = $token;
        $edits = array();
        foreach ($index->ranges() as $range) {
            $token = $names[$range['offset']] ?? null;
            if (null === $token || ! str_ends_with($token['name'], '/' . self::LOCAL_NAME)) continue;
            $children = $index->children($range);
            if (array() === $children) continue;
            $parsed = $runtime->parseBlocks(substr($markup, $range['offset'], $range['length']));
            $block = $parsed[0] ?? null;
            if (! is_array($block) || null === ($host = self::publishedHost($block))) continue;
            $first = $children[0]['offset'];
            $last = end($children);
            $lastEnd = $last['offset'] + $last['length'];
            $end = $range['offset'] + $range['length'];
            $closingComment = '<!-- /wp:' . (str_starts_with($host['blockName'], 'core/') ? substr($host['blockName'], 5) : $host['blockName']) . ' -->';
            $serialized = $runtime->serializeBlocks(array(array('blockName' => $host['blockName'], 'attrs' => $host['attrs'], 'innerBlocks' => array(), 'innerHTML' => $host['opening'] . $host['closing'], 'innerContent' => array($host['opening'], $host['closing']))));
            $tail = $host['closing'] . $closingComment;
            if (! str_ends_with($serialized, $tail)) continue;
            $edits[] = array('offset' => $range['offset'], 'length' => $first - $range['offset'], 'text' => substr($serialized, 0, strlen($serialized) - strlen($tail)));
            $edits[] = array('offset' => $lastEnd, 'length' => $end - $lastEnd, 'text' => $tail);
        }
        usort($edits, static fn (array $left, array $right): int => $left['offset'] <=> $right['offset']);
        return $edits;
    }

    /**
     * `$markup` with the edits of {@see publicationEdits()} that end within it
     * applied; a prefix of a document is published as far as it reaches.
     *
     * @param list<array{offset:int,length:int,text:string}> $edits
     */
    public static function applyEdits(string $markup, array $edits): string
    {
        $published = '';
        $cursor = 0;
        foreach ($edits as $edit) {
            if ($edit['offset'] + $edit['length'] > strlen($markup)) break;
            $published .= substr($markup, $cursor, $edit['offset'] - $cursor) . $edit['text'];
            $cursor = $edit['offset'] + $edit['length'];
        }
        return $published . substr($markup, $cursor);
    }

    /**
     * The core host a parsed carousel block publishes as, with the wrapper
     * its saved shell is replaced by; null when the block stays as it is.
     *
     * @param array<string, mixed> $block
     * @return array{blockName:string,attrs:array<string,mixed>,opening:string,closing:string}|null
     */
    private static function publishedHost(array $block): ?array
    {
        if (! str_ends_with((string) ($block['blockName'] ?? ''), '/' . self::LOCAL_NAME)) return null;
        $attrs = is_array($block['attrs'] ?? null) ? $block['attrs'] : array();
        $slides = array_map(static fn (array $slide): array => array('blockName' => self::publishedName($slide)), array_values(is_array($block['innerBlocks'] ?? null) ? $block['innerBlocks'] : array()));
        $presentation = $attrs['presentation'] ?? 'track';
        if ('src-swap' === $presentation || array() === $slides
            || ('slideshow' !== $presentation && 'core/gallery' !== self::hostBlockName($slides))) return null;
        $chunks = is_array($block['innerContent'] ?? null) ? $block['innerContent'] : array();
        // Generated carousels save one opening and closing shell around
        // the editable slides. Keep those exact, already-rebased bytes.
        if (count($chunks) !== count($slides) + 2 || ! is_string($chunks[0]) || ! is_string($chunks[count($chunks) - 1])) return null;
        foreach (array_slice($chunks, 1, -1) as $chunk) if (null !== $chunk) return null;
        $name = self::hostBlockName($slides);
        $class = 'core/gallery' === $name ? 'wp-block-gallery has-nested-images columns-default is-cropped' : 'wp-block-group';
        $hostAttrs = array('metadata' => array(self::METADATA_KEY => array('opening' => $chunks[0], 'closing' => $chunks[count($chunks) - 1], 'attributes' => $attrs)));
        if ('slideshow' === $presentation) {
            $hostAttrs['className'] = self::EDITOR_HOST_CLASS;
            $class .= ' ' . self::EDITOR_HOST_CLASS;
        }
        $tag = 'core/gallery' === $name ? 'figure' : 'div';
        return array('blockName' => $name, 'attrs' => $hostAttrs, 'opening' => '<' . $tag . ' class="' . $class . '">', 'closing' => '</' . $tag . '>');
    }

    /** @param array<string, mixed> $block The block name a parsed block publishes under. */
    private static function publishedName(array $block): string
    {
        $host = self::publishedHost($block);
        return null === $host ? (string) ($block['blockName'] ?? '') : $host['blockName'];
    }

    /**
     * Front-end view of serialized block markup, rendered statically: every
     * carousel host is rendered through its recorded shell, all other block
     * markup is kept as saved.
     */
    public static function renderMarkup(string $markup): string
    {
        return self::renderBlocks((new Runtime())->parseBlocks($markup));
    }

    /** @param array<int, array<string, mixed>> $blocks */
    private static function renderBlocks(array $blocks): string
    {
        $html = '';
        foreach ($blocks as $block) {
            $inner = is_array($block['innerBlocks'] ?? null) ? array_values($block['innerBlocks']) : array();
            $content = '';
            $index = 0;
            foreach ((array) ($block['innerContent'] ?? array($block['innerHTML'] ?? '')) as $chunk) {
                $content .= is_string($chunk) ? $chunk : self::renderBlocks(isset($inner[$index]) ? array($inner[$index++]) : array());
            }
            $attributes = is_array($block['attrs'] ?? null) ? $block['attrs'] : array();
            $html .= in_array($block['blockName'] ?? null, self::HOST_BLOCKS, true) ? self::render($content, $attributes) : $content;
        }
        return $html;
    }
}
