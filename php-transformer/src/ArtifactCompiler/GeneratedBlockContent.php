<?php

declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler;

/**
 * Markup a generated block renders from its per-instance `content` attribute.
 * The companion renderer emits that content in place of the self-closing
 * block reference, so selector evidence must see it there too.
 */
final class GeneratedBlockContent
{
    /**
     * Names of generated blocks that render their `content` attribute: a
     * static render plus a string `content` attribute, as the companion
     * payload consumer decides.
     *
     * @param array<int, mixed> $generatedBlocks
     * @return array<string, true>
     */
    public static function rendererNames(array $generatedBlocks): array
    {
        $names = array();
        foreach ( $generatedBlocks as $block ) {
            if ( ! is_array($block) || ! is_scalar($block['render'] ?? null) || 'string' !== ($block['block_json']['attributes']['content']['type'] ?? null) ) {
                continue;
            }
            $name = $block['block_json']['name'] ?? null;
            if ( is_string($name) && 1 === preg_match('/^[a-z][a-z0-9-]*\/[a-z][a-z0-9-]*$/', $name) ) {
                $names[$name] = true;
            }
        }

        return $names;
    }

    /**
     * Block markup with each reference to a named block replaced by the
     * markup it renders.
     *
     * @param array<string, true> $names
     */
    public static function rendered(string $markup, array $names): string
    {
        if ( array() === $names || ! str_contains($markup, '<!-- wp:') ) {
            return $markup;
        }

        // Serialized attributes escape `--`, so a delimiter ends at the next
        // `-->`; scanning keeps large content linear and never loses markup.
        $rendered = '';
        $offset = 0;
        while ( false !== ($start = strpos($markup, '<!-- wp:', $offset)) ) {
            $end = strpos($markup, '-->', $start);
            if ( false === $end ) {
                break;
            }
            $end += 3;
            $rendered .= substr($markup, $offset, $end - $offset);
            $offset = $end;
            $delimiter = substr($markup, $start + 8, $end - $start - 8);
            $space = strpos($delimiter, ' ');
            if ( false === $space || ! isset($names[substr($delimiter, 0, $space)]) || ! str_ends_with($delimiter, ' /-->') ) {
                continue;
            }
            $attributes = json_decode(substr($delimiter, $space + 1, -5), true);
            if ( is_array($attributes) && is_string($attributes['content'] ?? null) ) {
                $rendered .= self::emitted($attributes['content']);
            }
        }

        return $rendered . substr($markup, $offset);
    }

    /** The element structure the renderer emits for one instance's content. */
    public static function emitted(string $content): string
    {
        $content = preg_replace('#<\s*(?:script|style|iframe|object|embed|foreignobject|animate|animatemotion|animatetransform|set)\b[^>]*>.*?</\s*(?:script|style|iframe|object|embed|foreignobject|animate|animatemotion|animatetransform|set)\s*>#is', '', $content) ?? '';
        $content = preg_replace('#<\s*(?:script|style|iframe|object|embed|foreignobject|animate|animatemotion|animatetransform|set)\b[^>]*/?\s*>#is', '', $content) ?? '';
        $content = preg_replace('#<\s*(/?)\s*[a-z][a-z0-9]*-[a-z0-9-]+\b#i', '<$1div', $content) ?? '';
        $content = preg_replace('/\sdata-wp-[a-z0-9_-]*(?:\s*=\s*(?:"[^"]*"|\'[^\']*\'|[^\s>]+))?/i', '', $content) ?? '';

        return $content;
    }
}
