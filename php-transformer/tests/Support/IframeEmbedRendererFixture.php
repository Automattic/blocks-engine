<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\Tests\Support {

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Generators\VisualIframeBlockGenerator;
use Automattic\BlocksEngine\PhpTransformer\WordPress\Runtime;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\IframeEmbedRuntime;

/**
 * Execute the generated theme's iframe embed runtime with an isolated
 * WordPress surface: render serialized blocks as the frontend would, and
 * answer the embed handler the editor preview uses.
 */
final class IframeEmbedRendererFixture
{
    /** @var array<string, callable> */
    public static array $filters = array();

    /** @var array<int, callable> */
    public static array $actions = array();

    /** @var array<string, array{regex: string, callback: callable}> */
    public static array $handlers = array();

    /** Load the runtime the theme bootstrap ships for these documents' markup. */
    public static function load(string $markup): bool
    {
        self::$filters = self::$actions = self::$handlers = array();
        $source = IframeEmbedRuntime::source(array( array( 'canonical_block_markup' => $markup ) ));
        if ('' === $source) return false;
        eval('namespace ' . __NAMESPACE__ . '\\IframeEmbedScope;' . $source);
        foreach (self::$actions as $action) $action();
        return true;
    }

    public static function render(string $markup): string
    {
        self::load($markup);
        $render = static function (array $block) use (&$render): string {
            $content = '';
            $children = $block['innerBlocks'] ?? array();
            foreach ($block['innerContent'] ?? array($block['innerHTML'] ?? '') as $part) {
                $content .= null === $part ? $render(array_shift($children)) : $part;
            }
            $filter = self::$filters['render_block_' . ($block['blockName'] ?? '')] ?? null;
            return null === $filter ? $content : $filter($content, $block);
        };
        return implode('', array_map($render, (new Runtime())->parseBlocks($markup)));
    }

    /** The registered handler's HTML for a URL, or null when no handler matches. */
    public static function handlerHtml(string $url, array $attr = array( 'width' => 600, 'height' => 400 )): ?string
    {
        foreach (self::$handlers as $handler) {
            if (1 !== preg_match($handler['regex'], $url, $matches)) continue;
            $html = ($handler['callback'])($matches, $attr, $url);
            return is_string($html) ? $html : null;
        }
        return null;
    }

    /** The frame attributes a core/embed block carries for the theme renderer. */
    public static function frame(array $block): ?array
    {
        $frame = $block['attrs']['metadata'][VisualIframeBlockGenerator::FRAME_METADATA] ?? null;
        return 'core/embed' === ($block['blockName'] ?? null) && is_array($frame) ? $frame : null;
    }
}

}

namespace Automattic\BlocksEngine\PhpTransformer\Tests\Support\IframeEmbedScope {

use Automattic\BlocksEngine\PhpTransformer\Tests\Support\IframeEmbedRendererFixture;

function add_filter($name, $callback, ...$args) { IframeEmbedRendererFixture::$filters[$name] = $callback; }
function add_action($name, $callback, ...$args) { IframeEmbedRendererFixture::$actions[] = $callback; }
function wp_embed_register_handler($id, $regex, $callback, $priority = 10) { IframeEmbedRendererFixture::$handlers[$id] = array( 'regex' => $regex, 'callback' => $callback ); }
function wp_parse_url($url, $component = -1) { return parse_url($url, $component); }
function esc_attr($text) { return htmlspecialchars((string) $text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function esc_url($url) { return htmlspecialchars((string) $url, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
}
