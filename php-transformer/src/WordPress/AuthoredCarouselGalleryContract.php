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
    /** Transform option that asks for core block publication. */
    public const TRANSFORM_OPTION = 'carousels_as_core_blocks';
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
