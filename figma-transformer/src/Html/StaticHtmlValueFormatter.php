<?php

declare(strict_types=1);

namespace Automattic\BlocksEngine\FigmaTransformer\Html;

/**
 * Canonical scalar formatting for emitted HTML, CSS, and SVG values.
 */
final class StaticHtmlValueFormatter
{
    public function number(float $value): string
    {
        if ( ! is_finite($value) ) {
            return '0';
        }

        return rtrim(rtrim(sprintf('%.3F', $value), '0'), '.');
    }

    public function color(mixed $value, mixed $opacity = null): ?string
    {
        if ( is_string($value) && preg_match('/^#[0-9a-fA-F]{3}([0-9a-fA-F]{3})?$/', $value) ) {
            return strtolower($value);
        }

        if ( ! is_array($value) ) {
            return null;
        }

        $red = $this->colorChannel($value['r'] ?? $value['red'] ?? null);
        $green = $this->colorChannel($value['g'] ?? $value['green'] ?? null);
        $blue = $this->colorChannel($value['b'] ?? $value['blue'] ?? null);
        if ( null === $red || null === $green || null === $blue ) {
            return null;
        }

        $alpha = $opacity;
        if ( null === $alpha && isset($value['a']) ) {
            $alpha = $value['a'];
        }

        if ( is_numeric($alpha) && (float) $alpha < 1 ) {
            return sprintf('rgba(%d,%d,%d,%s)', $red, $green, $blue, $this->number(max(0, (float) $alpha)));
        }

        return sprintf('#%02x%02x%02x', $red, $green, $blue);
    }

    public function sanitizeAttribute(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    public function slug(string $value): string
    {
        $slug = strtolower(preg_replace('/[^a-zA-Z0-9]+/', '-', $value) ?? '');
        $slug = trim($slug, '-');

        return '' === $slug ? 'node' : $slug;
    }

    private function colorChannel(mixed $value): ?int
    {
        if ( ! is_numeric($value) ) {
            return null;
        }

        $channel = (float) $value;
        if ( $channel <= 1 ) {
            $channel *= 255;
        }

        return max(0, min(255, (int) round($channel)));
    }
}
