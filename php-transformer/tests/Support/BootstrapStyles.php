<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\Tests\Support;

/** Inspect emitted sequence data, exposing route calls to older cascade assertions. */
final class BootstrapStyles
{
    public static function registry(string $bootstrap, string $name): array
    {
        if (!preg_match('/\\$' . $name . ' = (\\[[^\\n]*\\]);/', $bootstrap, $match)) throw new \RuntimeException('Missing bootstrap registry: ' . $name);
        $value = eval('return ' . $match[1] . ';');
        if (preg_match('/\\$' . $name . '_rows = (\\[[^\\n]*\\]);/', $bootstrap, $rowsMatch)) {
            $rows = eval('return ' . $rowsMatch[1] . ';');
            preg_match('/\\$' . $name . '_references = (\\[[^\\n]*\\]);/', $bootstrap, $referencesMatch);
            foreach (eval('return ' . $referencesMatch[1] . ';') as $key => $index) $value[$key] = $rows[$index];
        }
        if ('blocks_engine_presentation_replay' === $name) foreach ($value as &$entry) $entry += array(3 => '', 4 => false, 5 => array());
        return $value;
    }

    public static function expand(string $bootstrap): string
    {
        if (!preg_match('/\$blocks_engine_style_sequences = (\[[^\n]*\]);/s', $bootstrap, $match)) return $bootstrap;
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
