<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\StaticSite\FontMaterialization;

final class FontMaterializationPlanBuilder
{
    public const SCHEMA = 'blocks-engine/php-transformer/font-materialization-plan/v1';

    /**
     * @param array<int,array<string,mixed>> $fontUsage
     * @return array<string,mixed>
     */
    public function googleFonts(array $fontUsage): array
    {
        $fonts = $this->normalizeFontUsage($fontUsage);
        $css = $this->googleFontsCss($fonts);

        return array_filter(array(
            'schema' => self::SCHEMA,
            'provider' => 'google_fonts',
            'fonts' => $fonts,
            'css' => $css,
            'stylesheets' => '' === $css ? array() : array(
                array(
                    'path' => 'assets/css/fonts.css',
                    'role' => 'stylesheet',
                    'mime_type' => 'text/css',
                    'content' => $css . "\n",
                ),
            ),
        ), static fn (mixed $value): bool => array() !== $value && '' !== $value);
    }

    /**
     * @param array<int,array<string,mixed>> $fontUsage
     * @return array<int,array{family:string,weights:array<int,int>}>
     */
    private function normalizeFontUsage(array $fontUsage): array
    {
        $weightsByFamily = array();
        foreach ( $fontUsage as $font ) {
            if ( ! is_array($font) ) {
                continue;
            }

            $family = $this->normalizeFamily((string) ($font['family'] ?? $font['font_family'] ?? ''));
            if ( '' === $family || $this->isWebSafeFontFamily($family) ) {
                continue;
            }

            $weights = $font['weights'] ?? $font['font_weights'] ?? $font['weight'] ?? $font['font_weight'] ?? array(400);
            $weights = is_array($weights) ? $weights : array($weights);
            foreach ( $weights as $weight ) {
                $weight = is_numeric($weight) ? (int) $weight : 400;
                if ( $weight > 0 ) {
                    $weightsByFamily[$family][] = $weight;
                }
            }
        }

        ksort($weightsByFamily);
        $fonts = array();
        foreach ( $weightsByFamily as $family => $weights ) {
            $weights = array_values(array_unique($weights));
            sort($weights);
            $fonts[] = array(
                'family' => $family,
                'weights' => $weights,
            );
        }

        return $fonts;
    }

    /**
     * @param array<int,array{family:string,weights:array<int,int>}> $fonts
     */
    private function googleFontsCss(array $fonts): string
    {
        $families = array();
        foreach ( $fonts as $font ) {
            $weights = empty($font['weights']) ? array(400) : $font['weights'];
            $families[] = 'family=' . str_replace('%20', '+', rawurlencode($font['family'])) . ':wght@' . implode(';', $weights);
        }

        if ( empty($families) ) {
            return '';
        }

        return '@import url("https://fonts.googleapis.com/css2?' . implode('&', $families) . '&display=swap");';
    }

    private function normalizeFamily(string $family): string
    {
        return trim($family, " \t\n\r\0\x0B\"'");
    }

    private function isWebSafeFontFamily(string $family): bool
    {
        return in_array(strtolower($family), array('arial', 'courier new', 'georgia', 'helvetica', 'monospace', 'sans-serif', 'serif', 'system-ui', 'times new roman', 'verdana'), true);
    }
}
