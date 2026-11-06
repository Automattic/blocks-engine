<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\Tests\Support;

/** Inspect emitted sequence data, exposing route calls to older cascade assertions. */
final class BootstrapStyles
{
    public static function expand(string $bootstrap): string
    {
        if (!preg_match('/\$blocks_engine_style_sequences = (\(static function .*?\}\)\(\));/s', $bootstrap, $match)) return $bootstrap;
        $sequences = eval('return ' . $match[1] . ';');
        return preg_replace_callback('/^    if \( (.+) \) \$blocks_engine_style_routes\[\] = (\[[^\n]+\]);$/m', static function (array $match) use ($sequences): string {
            [$index, $handles] = eval('return ' . $match[2] . ';');
            $lines = array('    if ( ' . $match[1] . ' ) {', '        $blocks_engine_route_styles = true;');
            foreach ($sequences[$index] as $position => $style) $lines[] = '        wp_enqueue_style( ' . var_export($handles[$position], true) . ', get_theme_file_uri( ' . var_export($style[0], true) . ' ), array(), null, ' . var_export($style[1], true) . ' );';
            $lines[] = '    }';
            return implode("\n", $lines);
        }, $bootstrap) ?? $bootstrap;
    }
}
