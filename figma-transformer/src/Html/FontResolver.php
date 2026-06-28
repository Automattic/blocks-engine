<?php

declare(strict_types=1);

namespace Automattic\BlocksEngine\FigmaTransformer\Html;

/**
 * Resolves source font families to emittable font CSS.
 *
 * Two resolution sources, in priority order:
 *  1. Operator-supplied `font_css` passthrough (overrides everything).
 *  2. Well-known web fonts available from the Google Fonts CDN, emitted as a
 *     weight-aware `@import` that pulls real `@font-face` rules.
 *
 * Web-safe families need no CSS. Anything else is reported as unresolved so an
 * operator can supply the missing font, keeping the font-coverage diagnostic
 * actionable rather than a bare count.
 */
final class FontResolver
{
    /**
     * Well-known Google Fonts families. Lowercased lookup key => canonical
     * family name + generic CSS fallback class.
     *
     * @var array<string, array{family: string, generic: string}>
     */
    private const GOOGLE_FONTS = array(
        'archivo'           => array('family' => 'Archivo', 'generic' => 'sans-serif'),
        'barlow'            => array('family' => 'Barlow', 'generic' => 'sans-serif'),
        'bricolage grotesque' => array('family' => 'Bricolage Grotesque', 'generic' => 'sans-serif'),
        'cabin'             => array('family' => 'Cabin', 'generic' => 'sans-serif'),
        'dm sans'           => array('family' => 'DM Sans', 'generic' => 'sans-serif'),
        'dm serif display'  => array('family' => 'DM Serif Display', 'generic' => 'serif'),
        'figtree'           => array('family' => 'Figtree', 'generic' => 'sans-serif'),
        'fira sans'         => array('family' => 'Fira Sans', 'generic' => 'sans-serif'),
        'ibm plex mono'     => array('family' => 'IBM Plex Mono', 'generic' => 'monospace'),
        'ibm plex sans'     => array('family' => 'IBM Plex Sans', 'generic' => 'sans-serif'),
        'ibm plex serif'    => array('family' => 'IBM Plex Serif', 'generic' => 'serif'),
        'inter'             => array('family' => 'Inter', 'generic' => 'sans-serif'),
        'josefin sans'      => array('family' => 'Josefin Sans', 'generic' => 'sans-serif'),
        'jost'              => array('family' => 'Jost', 'generic' => 'sans-serif'),
        'karla'             => array('family' => 'Karla', 'generic' => 'sans-serif'),
        'lato'              => array('family' => 'Lato', 'generic' => 'sans-serif'),
        'libre baskerville' => array('family' => 'Libre Baskerville', 'generic' => 'serif'),
        'libre franklin'    => array('family' => 'Libre Franklin', 'generic' => 'sans-serif'),
        'lora'              => array('family' => 'Lora', 'generic' => 'serif'),
        'manrope'           => array('family' => 'Manrope', 'generic' => 'sans-serif'),
        'merriweather'      => array('family' => 'Merriweather', 'generic' => 'serif'),
        'montserrat'        => array('family' => 'Montserrat', 'generic' => 'sans-serif'),
        'mulish'            => array('family' => 'Mulish', 'generic' => 'sans-serif'),
        'noto sans'         => array('family' => 'Noto Sans', 'generic' => 'sans-serif'),
        'noto serif'        => array('family' => 'Noto Serif', 'generic' => 'serif'),
        'nunito'            => array('family' => 'Nunito', 'generic' => 'sans-serif'),
        'nunito sans'       => array('family' => 'Nunito Sans', 'generic' => 'sans-serif'),
        'open sans'         => array('family' => 'Open Sans', 'generic' => 'sans-serif'),
        'oswald'            => array('family' => 'Oswald', 'generic' => 'sans-serif'),
        'playfair display'  => array('family' => 'Playfair Display', 'generic' => 'serif'),
        'plus jakarta sans' => array('family' => 'Plus Jakarta Sans', 'generic' => 'sans-serif'),
        'poppins'           => array('family' => 'Poppins', 'generic' => 'sans-serif'),
        'pt sans'           => array('family' => 'PT Sans', 'generic' => 'sans-serif'),
        'pt serif'          => array('family' => 'PT Serif', 'generic' => 'serif'),
        'quicksand'         => array('family' => 'Quicksand', 'generic' => 'sans-serif'),
        'raleway'           => array('family' => 'Raleway', 'generic' => 'sans-serif'),
        'roboto'            => array('family' => 'Roboto', 'generic' => 'sans-serif'),
        'roboto mono'       => array('family' => 'Roboto Mono', 'generic' => 'monospace'),
        'roboto slab'       => array('family' => 'Roboto Slab', 'generic' => 'serif'),
        'rubik'             => array('family' => 'Rubik', 'generic' => 'sans-serif'),
        'source sans 3'     => array('family' => 'Source Sans 3', 'generic' => 'sans-serif'),
        'source sans pro'   => array('family' => 'Source Sans Pro', 'generic' => 'sans-serif'),
        'source serif 4'    => array('family' => 'Source Serif 4', 'generic' => 'serif'),
        'source serif pro'  => array('family' => 'Source Serif Pro', 'generic' => 'serif'),
        'space grotesk'     => array('family' => 'Space Grotesk', 'generic' => 'sans-serif'),
        'titillium web'     => array('family' => 'Titillium Web', 'generic' => 'sans-serif'),
        'work sans'         => array('family' => 'Work Sans', 'generic' => 'sans-serif'),
    );

    /**
     * Web-safe families that render correctly without any emitted font CSS.
     *
     * @var array<string, string>
     */
    private const WEB_SAFE = array(
        'arial'           => 'sans-serif',
        'helvetica'       => 'sans-serif',
        'tahoma'          => 'sans-serif',
        'trebuchet ms'    => 'sans-serif',
        'verdana'         => 'sans-serif',
        'courier'         => 'monospace',
        'courier new'     => 'monospace',
        'georgia'         => 'serif',
        'times'           => 'serif',
        'times new roman' => 'serif',
        'monospace'       => 'monospace',
        'sans-serif'      => 'sans-serif',
        'serif'           => 'serif',
    );

    /**
     * Resolve font usage into emittable font CSS plus an actionable coverage
     * report.
     *
     * @param array<int, array<string, mixed>> $fontUsage Font usage entries
     *        (family/weights/text_node_count), as produced by the emitter.
     * @param string $operatorFontCss Operator-supplied `font_css` override.
     * @return array{
     *     css: string,
     *     operator_supplied: bool,
     *     resolved_families: array<int, string>,
     *     unresolved_families: array<int, string>,
     *     cdn_families: array<int, string>,
     *     coverage: array<int, array<string, mixed>>
     * }
     */
    public function resolve(array $fontUsage, string $operatorFontCss = ''): array
    {
        $operatorSupplied = '' !== trim($operatorFontCss);
        $coverage = array();
        $resolved = array();
        $unresolved = array();
        $cdnFamilies = array();

        foreach ( $fontUsage as $usage ) {
            if ( ! is_array($usage) ) {
                continue;
            }

            $family = $this->primaryFamily((string) ($usage['family'] ?? ''));
            if ( '' === $family ) {
                continue;
            }

            $key = strtolower($family);
            $weights = $this->normalizeWeights(is_array($usage['weights'] ?? null) ? $usage['weights'] : array());

            if ( $operatorSupplied ) {
                $resolution = 'operator_supplied';
            } elseif ( isset(self::WEB_SAFE[$key]) ) {
                $resolution = 'web_safe';
            } elseif ( isset(self::GOOGLE_FONTS[$key]) ) {
                $resolution = 'cdn_google_fonts';
                $canonical = self::GOOGLE_FONTS[$key]['family'];
                $cdnFamilies[$canonical] = $this->normalizeWeights(array_merge($cdnFamilies[$canonical] ?? array(), $weights));
            } else {
                $resolution = 'unresolved';
            }

            $isResolved = 'unresolved' !== $resolution;
            if ( $isResolved ) {
                $resolved[] = $family;
            } else {
                $unresolved[] = $family;
            }

            $entry = array(
                'family'              => $family,
                'weights'             => $weights,
                'text_node_count'     => (int) ($usage['text_node_count'] ?? 0),
                'resolution'          => $resolution,
                'resolved'            => $isResolved,
                'needs_operator_font' => 'unresolved' === $resolution,
                'fallback_stack'      => $this->fallbackStack($family),
            );
            if ( 'cdn_google_fonts' === $resolution ) {
                $entry['source_url'] = $this->googleFontUrl(self::GOOGLE_FONTS[$key]['family'], $weights);
            }
            $coverage[] = $entry;
        }

        $css = $operatorSupplied ? trim($operatorFontCss) : $this->cdnImportCss($cdnFamilies);

        return array(
            'css'                 => $css,
            'operator_supplied'   => $operatorSupplied,
            'resolved_families'   => array_values(array_unique($resolved)),
            'unresolved_families' => array_values(array_unique($unresolved)),
            'cdn_families'        => array_keys($cdnFamilies),
            'coverage'            => $coverage,
        );
    }

    /**
     * Build a CSS font-family stack for a family name, with a sane generic
     * fallback so the browser degrades to the right generic family if the
     * resolved font fails to load.
     */
    public function fallbackStack(string $family): string
    {
        $family = $this->primaryFamily($family);
        if ( '' === $family ) {
            return 'sans-serif';
        }

        $generic = $this->genericFor($family);
        if ( strtolower($family) === $generic ) {
            return $generic;
        }

        return '"' . str_replace(array('\\', '"'), array('\\\\', '\\"'), $family) . '", ' . $generic;
    }

    private function genericFor(string $family): string
    {
        $key = strtolower($family);
        if ( isset(self::WEB_SAFE[$key]) ) {
            return self::WEB_SAFE[$key];
        }
        if ( isset(self::GOOGLE_FONTS[$key]) ) {
            return self::GOOGLE_FONTS[$key]['generic'];
        }
        if ( str_contains($key, 'mono') ) {
            return 'monospace';
        }
        if ( str_contains($key, 'serif') && ! str_contains($key, 'sans') ) {
            return 'serif';
        }

        return 'sans-serif';
    }

    /**
     * @param array<string, array<int, int>> $cdnFamilies Canonical family => weights.
     */
    private function cdnImportCss(array $cdnFamilies): string
    {
        if ( empty($cdnFamilies) ) {
            return '';
        }

        ksort($cdnFamilies, SORT_NATURAL | SORT_FLAG_CASE);
        $specs = array();
        foreach ( $cdnFamilies as $family => $weights ) {
            $specs[] = 'family=' . $this->googleFontSpec((string) $family, $weights);
        }

        $url = 'https://fonts.googleapis.com/css2?' . implode('&', $specs) . '&display=swap';

        return "/* Figma Transformer: source web fonts resolved via Google Fonts CDN */\n@import url('" . $url . "');";
    }

    /**
     * @param array<int, int> $weights
     */
    private function googleFontUrl(string $family, array $weights): string
    {
        return 'https://fonts.googleapis.com/css2?family=' . $this->googleFontSpec($family, $weights) . '&display=swap';
    }

    /**
     * @param array<int, int> $weights
     */
    private function googleFontSpec(string $family, array $weights): string
    {
        $spec = str_replace(' ', '+', $family);
        $weights = $this->normalizeWeights($weights);
        if ( ! empty($weights) ) {
            $spec .= ':wght@' . implode(';', $weights);
        }

        return $spec;
    }

    /**
     * Clamp weights to the standard 100-900 axis so Google Fonts css2 accepts
     * them, de-duplicated and sorted ascending.
     *
     * @param array<int, mixed> $weights
     * @return array<int, int>
     */
    private function normalizeWeights(array $weights): array
    {
        $normalized = array();
        foreach ( $weights as $weight ) {
            if ( ! is_numeric($weight) ) {
                continue;
            }
            $rounded = (int) ( round((float) $weight / 100.0) * 100 );
            $rounded = max(100, min(900, $rounded));
            $normalized[$rounded] = true;
        }

        $values = array_keys($normalized);
        sort($values, SORT_NUMERIC);

        return $values;
    }

    private function primaryFamily(string $value): string
    {
        $first = explode(',', $value, 2)[0];

        return trim($first, " \t\n\r\0\x0B\"'");
    }
}
