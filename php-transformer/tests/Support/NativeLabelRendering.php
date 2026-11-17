<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\Tests\Support;

use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\NativeLabelRuntime;

/** Publishes saved label paragraphs the way the theme's label renderer does. */
final class NativeLabelRendering
{
    public static function publish(string $markup): string
    {
        if (!function_exists('esc_attr')) {
            eval('function esc_attr($text) { return htmlspecialchars((string) $text, ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8"); }');
        }
        $render = eval('return ' . NativeLabelRuntime::callback() . ';');
        return (string) preg_replace_callback('/<!-- wp:paragraph (\{.*?\}) -->\s*(<p\b.*?<\/p>)\s*<!-- \/wp:paragraph -->/s', static function (array $match) use ($render): string {
            $attrs = json_decode($match[1], true);
            return is_array($attrs) && isset($attrs['metadata']['blocksEngineLabel'])
                ? $render($match[2], array('blockName' => 'core/paragraph', 'attrs' => $attrs))
                : $match[0];
        }, $markup);
    }
}
