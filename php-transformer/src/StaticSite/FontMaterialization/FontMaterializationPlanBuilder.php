<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\StaticSite\FontMaterialization;

final class FontMaterializationPlanBuilder
{
    public const SCHEMA = 'blocks-engine/php-transformer/font-materialization-plan/v1';

    /**
     * @param array<int,array<string,mixed>> $fontUsage
     * @param array<string,string> $roles
     * @return array<string,mixed>
     */
    public function googleFonts(array $fontUsage, array $roles = array()): array
    {
        $fonts = $this->normalizeFontUsage($fontUsage);
        $css = $this->googleFontsCss($fonts);
        $roles = $this->filterRoles($roles, $fonts);

        return array_filter(array(
            'schema' => self::SCHEMA,
            'provider' => 'google_fonts',
            'fonts' => $fonts,
            'roles' => $roles,
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
     * Build a materialization plan from raw web-font sources.
     *
     * Detects linked web-font stylesheets (e.g. Google Fonts `css2`/`css`
     * `<link>` tags) in the supplied HTML and `font-family` declarations in the
     * supplied CSS, preserving the discovered typefaces and their heading/body
     * roles so that materialized output keeps the source typography.
     *
     * @return array<string,mixed>
     */
    public function fromWebFontSources(string $html = '', string $css = ''): array
    {
        $fontUsage = array_merge(
            $this->fontUsageFromLinkedStylesheets($html),
            $this->fontUsageFromCssDeclarations($css)
        );
        $roles = $this->fontRolesFromCss($css);

        // The base/body `font-family` is the document's foundational typography
        // and must survive into the materialized output even when it is declared
        // only in an inline `<style>` block (no external stylesheet, no linked
        // web-font). Carry that base font into the plan so the generated base
        // typography keeps the source's body face. Heading-only inline fonts are
        // deliberately NOT materialized here: a custom heading face with no
        // loaded web-font cannot render, so it stays a reported drop.
        $inlineBody = (string) ($this->fontRolesFromCss($this->styleBlockCss($html))['body'] ?? '');
        if ( '' !== $inlineBody ) {
            $fontUsage[] = array('family' => $inlineBody, 'weights' => array(400));
            if ( '' === (string) ($roles['body'] ?? '') ) {
                $roles['body'] = $inlineBody;
            }
        }

        return $this->googleFonts($fontUsage, $roles);
    }

    /**
     * Concatenate the CSS inside every `<style>` block of an HTML document.
     */
    private function styleBlockCss(string $html): string
    {
        if ( '' === trim($html) || ! preg_match_all('/<style\b[^>]*>(.*?)<\/style>/is', $html, $matches) ) {
            return '';
        }

        return implode("\n", $matches[1]);
    }

    /**
     * Parse linked web-font stylesheets out of HTML and return the discovered
     * families with their requested weights.
     *
     * @return array<int,array{family:string,weights:array<int,int>}>
     */
    public function fontUsageFromLinkedStylesheets(string $html): array
    {
        if ( '' === trim($html) || ! preg_match_all('/<link\b[^>]*>/i', $html, $matches) ) {
            return array();
        }

        $usage = array();
        foreach ( $matches[0] as $tag ) {
            $href = $this->htmlAttributeValue((string) $tag, 'href');
            if ( '' === $href ) {
                continue;
            }
            foreach ( $this->fontUsageFromFontHref($href) as $font ) {
                $usage[] = $font;
            }
        }

        return $usage;
    }

    /**
     * Parse the `family=` query parameters of a Google Fonts `css2`/`css`
     * stylesheet URL. Handles repeated `&family=` parameters, `|`-separated
     * families, and `:wght@…` (and legacy `:400,700`) axis suffixes.
     *
     * @return array<int,array{family:string,weights:array<int,int>}>
     */
    public function fontUsageFromFontHref(string $href): array
    {
        $href = html_entity_decode($href, ENT_QUOTES | ENT_HTML5);
        $host = strtolower((string) (parse_url($href, PHP_URL_HOST) ?: ''));
        $path = strtolower((string) (parse_url($href, PHP_URL_PATH) ?: ''));
        if ( 'fonts.googleapis.com' !== $host || ! in_array($path, array('/css', '/css2'), true) ) {
            return array();
        }

        $query = (string) (parse_url($href, PHP_URL_QUERY) ?: '');
        if ( '' === $query ) {
            return array();
        }

        $usage = array();
        foreach ( explode('&', $query) as $param ) {
            if ( ! preg_match('/^family=(.*)$/i', $param, $match) ) {
                continue;
            }

            // `+` encodes a space in family names; decode percent-escapes too.
            $value = urldecode((string) $match[1]);
            foreach ( explode('|', $value) as $spec ) {
                $spec = trim($spec);
                if ( '' === $spec ) {
                    continue;
                }
                $parts = explode(':', $spec, 2);
                $family = $this->normalizeFamily($parts[0]);
                if ( '' === $family || $this->isWebSafeFontFamily($family) ) {
                    continue;
                }
                $usage[] = array(
                    'family' => $family,
                    'weights' => $this->parseFontWeights($parts[1] ?? ''),
                );
            }
        }

        return $usage;
    }

    /**
     * Collect every typeface referenced by `font-family` declarations in CSS.
     *
     * @return array<int,array{family:string,weights:array<int,int>}>
     */
    public function fontUsageFromCssDeclarations(string $css): array
    {
        $usage = array();
        if ( ! preg_match_all('/font-family\s*:\s*([^;{}]+)/i', $css, $matches) ) {
            return $usage;
        }

        foreach ( $matches[1] as $declaration ) {
            $family = $this->primaryFamily((string) $declaration);
            if ( '' !== $family ) {
                $usage[] = array('family' => $family, 'weights' => array(400));
            }
        }

        return $usage;
    }

    /**
     * Map `font-family` declarations to heading/body roles based on selectors.
     *
     * @return array<string,string>
     */
    public function fontRolesFromCss(string $css): array
    {
        if ( '' === trim($css) || ! preg_match_all('/([^{}]+)\{([^{}]*)\}/s', $css, $rules, PREG_SET_ORDER) ) {
            return array();
        }

        $heading = '';
        $body = '';
        foreach ( $rules as $rule ) {
            if ( ! preg_match('/font-family\s*:\s*([^;{}]+)/i', (string) $rule[2], $declaration) ) {
                continue;
            }
            $family = $this->primaryFamily((string) $declaration[1]);
            if ( '' === $family ) {
                continue;
            }

            $selectors = array_map('trim', explode(',', (string) $rule[1]));
            foreach ( $selectors as $selector ) {
                if ( '' === $heading && preg_match('/(^|[\s>+~])h[1-6]\b/i', $selector) ) {
                    $heading = $family;
                }
                if ( '' === $body && preg_match('/(^|[\s>+~])(body|html|:root|\*)\b/i', $selector) ) {
                    $body = $family;
                }
            }
        }

        return array_filter(array('heading' => $heading, 'body' => $body), static fn (string $value): bool => '' !== $value);
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

    /**
     * Return the first non web-safe typeface from a `font-family` value list.
     */
    private function primaryFamily(string $declaration): string
    {
        foreach ( explode(',', $declaration) as $candidate ) {
            $family = $this->normalizeFamily($candidate);
            if ( '' !== $family && ! $this->isWebSafeFontFamily($family) ) {
                return $family;
            }
        }

        return '';
    }

    /**
     * Extract integer weights from a Google Fonts axis suffix.
     *
     * Supports `css2` axis tuples (`wght@400;700`, `ital,wght@0,400;1,700`)
     * and the legacy `css` weight list (`400,700`). Defaults to `[400]`.
     *
     * @return array<int,int>
     */
    private function parseFontWeights(string $axes): array
    {
        $axes = trim($axes);
        if ( '' === $axes ) {
            return array(400);
        }

        $weights = array();
        if ( str_contains($axes, '@') ) {
            [$axisNames, $tuples] = explode('@', $axes, 2);
            $names = array_map(static fn (string $name): string => strtolower(trim($name)), explode(',', $axisNames));
            $wghtIndex = array_search('wght', $names, true);
            foreach ( explode(';', $tuples) as $tuple ) {
                $values = explode(',', $tuple);
                $value = false === $wghtIndex ? end($values) : ($values[$wghtIndex] ?? null);
                if ( is_numeric($value) ) {
                    $weights[] = (int) $value;
                }
            }
        } else {
            foreach ( explode(',', $axes) as $token ) {
                if ( preg_match('/(\d{2,4})/', $token, $match) ) {
                    $weights[] = (int) $match[1];
                }
            }
        }

        return array() === $weights ? array(400) : $weights;
    }

    /**
     * Drop role assignments whose family was filtered out of the plan.
     *
     * @param array<string,string> $roles
     * @param array<int,array{family:string,weights:array<int,int>}> $fonts
     * @return array<string,string>
     */
    private function filterRoles(array $roles, array $fonts): array
    {
        if ( array() === $roles ) {
            return array();
        }

        $families = array_map(static fn (array $font): string => (string) $font['family'], $fonts);
        return array_filter($roles, static fn (string $family): bool => in_array($family, $families, true));
    }

    private function htmlAttributeValue(string $tag, string $name): string
    {
        if ( preg_match('/(?:^|\s)' . preg_quote($name, '/') . '\s*=\s*(["\'])(.*?)\1/is', $tag, $match) ) {
            return html_entity_decode((string) $match[2], ENT_QUOTES | ENT_HTML5);
        }

        if ( preg_match('/(?:^|\s)' . preg_quote($name, '/') . '\s*=\s*([^\s"\'>]+)/is', $tag, $match) ) {
            return html_entity_decode((string) $match[1], ENT_QUOTES | ENT_HTML5);
        }

        return '';
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
