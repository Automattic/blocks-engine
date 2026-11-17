<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\Tests\Support;

use Automattic\BlocksEngine\PhpTransformer\WordPress\Runtime;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\NativeSelectRuntime;

/** Execute the generated theme filter with an isolated WordPress escaping surface. */
final class NativeSelectRendererFixture
{
    public static mixed $filter = null;

    public static function render(string $markup): string
    {
        if (null === self::$filter) {
            eval('namespace ' . __NAMESPACE__ . ';' . NativeSelectRuntime::source());
        }
        $runtime = new Runtime();
        $render = static function (array $block) use (&$render): string {
            if (isset($block['attrs']['metadata']['blocksEngineSelect'])) {
                return (self::$filter)('', $block);
            }
            $content = '';
            $children = $block['innerBlocks'] ?? array();
            foreach ($block['innerContent'] ?? array($block['innerHTML'] ?? '') as $part) {
                $content .= null === $part ? $render(array_shift($children)) : $part;
            }
            return $content;
        };
        return implode('', array_map($render, $runtime->parseBlocks($markup)));
    }
}

function add_filter($name, $callback, ...$args) { NativeSelectRendererFixture::$filter = $callback; }
function esc_attr($text) { return htmlspecialchars((string) $text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function esc_html($text) { return esc_attr($text); }
function wp_strip_all_tags($text) { return strip_tags($text); }
function wp_json_encode($data, $flags = 0) { return json_encode($data, $flags); }
function wp_kses_post($text) { return $text; }
