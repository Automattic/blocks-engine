<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\WordPress;

/**
 * A site import publishes an authored carousel of images as a core Gallery, so
 * an editor changes its images with core tools. The navigable stage the source
 * published around those images (the generated carousel block's saved shell)
 * is recorded in the gallery's metadata under METADATA_KEY, and the theme
 * runtime renders it around the gallery's images on the front end, loading the
 * behavior and styles of the generated `<namespace>/LOCAL_NAME` block type.
 */
final class AuthoredCarouselGalleryContract
{
    public const LOCAL_NAME = 'authored-carousel';
    public const METADATA_KEY = 'blocksEngineCarousel';
    /** Transform option that asks for core Gallery publication. */
    public const TRANSFORM_OPTION = 'image_carousels_as_core_galleries';
    /** The core Gallery figure whose images the shell surrounds. */
    public const FIGURE_PATTERN = '/^\\s*<figure\\b[^>]*>(.*)<\\/figure>\\s*$/s';

    /**
     * The carousel a block publishes: the generated carousel block itself, or
     * a core Gallery recording its stage. Returns the carousel's attributes,
     * or null when the block is no carousel.
     *
     * @param array<string, mixed> $block Parsed block.
     * @return array<string, mixed>|null
     */
    public static function carouselAttributes(array $block): ?array
    {
        $name = (string) ($block['blockName'] ?? '');
        if (str_ends_with($name, '/' . self::LOCAL_NAME)) return is_array($block['attrs'] ?? null) ? $block['attrs'] : array();
        if ('core/gallery' !== $name) return null;
        $shell = $block['attrs']['metadata'][self::METADATA_KEY] ?? null;
        return is_array($shell) && is_array($shell['attributes'] ?? null) ? $shell['attributes'] : null;
    }

    /**
     * Front-end markup of a serialized gallery: the recorded shell around the
     * gallery's images, as the theme runtime renders it. Returns the gallery
     * markup unchanged when it records no carousel shell.
     *
     * @param array<string, mixed> $attributes Gallery block attributes.
     */
    public static function render(string $content, array $attributes): string
    {
        $shell = $attributes['metadata'][self::METADATA_KEY] ?? null;
        if (! is_array($shell) || ! is_string($shell['opening'] ?? null) || ! is_string($shell['closing'] ?? null)) return $content;
        if (! preg_match(self::FIGURE_PATTERN, $content, $match)) return $content;
        return $shell['opening'] . $match[1] . $shell['closing'];
    }

    /**
     * Front-end view of serialized block markup: every carousel gallery is
     * rendered through its recorded shell, all other markup is kept.
     */
    public static function renderMarkup(string $markup): string
    {
        return (string) preg_replace_callback(
            '/<!-- wp:gallery (\{.*?\}) -->(.*?)<!-- \/wp:gallery -->/s',
            static function (array $match): string {
                $attributes = json_decode($match[1], true);
                return is_array($attributes) ? self::render($match[2], $attributes) : $match[0];
            },
            $markup
        );
    }
}
