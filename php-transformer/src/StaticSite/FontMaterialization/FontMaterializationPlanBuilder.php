<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\StaticSite\FontMaterialization;

final class FontMaterializationPlanBuilder
{
    public const SCHEMA = 'blocks-engine/php-transformer/font-materialization-plan/v1';
    private const CSS_WIDE_KEYWORDS = array('inherit', 'initial', 'revert', 'revert-layer', 'unset');

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
     * Detects web-font stylesheets (e.g. Google Fonts `css2`/`css` `<link>` and
     * CSS `@import` sources) plus `font-family` declarations, preserving the
     * discovered typefaces and their heading/body roles so that materialized
     * output keeps the source typography.
     *
     * @return array<string,mixed>
     */
    public function fromWebFontSources(string $html = '', string $css = '', array $cssSources = array()): array
    {
        // Source typography is frequently applied through CSS custom properties
        // (`body { font-family: var(--font-body) }` defined by
        // `:root { --font-body: 'Lora', serif }`). Resolve those references to
        // their concrete typefaces before parsing so the plan captures the real
        // family — never a literal `var(--font-body)` token, which would corrupt
        // the materialized Google Fonts request and the body role.
        $resolvedCss = $this->resolveCssVariables($css);

        $imports = $this->webFontImports($css, $cssSources);
        $fontUsage = array_merge(
            $this->fontUsageFromLinkedStylesheets($html),
            ...array_merge(array_column($imports, 'font_usage'), array($this->fontUsageFromCssDeclarations($resolvedCss)))
        );
        $roles = $this->fontRolesFromCss($resolvedCss);

        // The base/body `font-family` is the document's foundational typography
        // and must survive into the materialized output even when it is declared
        // only in an inline `<style>` block (no external stylesheet, no linked
        // web-font). Carry that base font into the plan so the generated base
        // typography keeps the source's body face. Heading-only inline fonts are
        // deliberately NOT materialized here: a custom heading face with no
        // loaded web-font cannot render, so it stays a reported drop.
        $inlineBody = (string) ($this->fontRolesFromCss($this->resolveCssVariables($this->styleBlockCss($html)))['body'] ?? '');
        if ( '' !== $inlineBody ) {
            $fontUsage[] = array('family' => $inlineBody, 'weights' => array(400));
            if ( '' === (string) ($roles['body'] ?? '') ) {
                $roles['body'] = $inlineBody;
            }
        }

        $plan = $this->googleFonts($fontUsage, $roles);
        $faces = array();
        $diagnostics = array();
        foreach ( $imports as $import ) {
            if ( array() === $import['faces'] ) {
                $diagnostics[] = array('code' => $import['supported'] ? 'webfont_import_unresolved' : 'webfont_import_unsupported_provider', 'severity' => 'warning', 'import_ref' => $import['id'], 'source_path' => $import['provenance']['source_path'], 'selector' => $import['provenance']['selector'], 'href_hash' => $import['href_hash']);
                continue;
            }
            foreach ( $import['faces'] as $face ) {
                $face['id'] = 'webfont-face-' . substr(hash('sha256', $import['id'] . "\n" . $face['id']), 0, 20);
                $faces[] = array_merge($face, array('import_ref' => $import['id']));
            }
        }
        usort($faces, static fn (array $left, array $right): int => strcmp($left['id'], $right['id']));
        if ( array() !== $imports ) $plan['imports'] = array_map(static fn (array $import): array => array('id' => $import['id'], 'href' => $import['href'], 'href_hash' => $import['href_hash'], 'provider' => $import['provider'], 'provenance' => $import['provenance']), $imports);
        $importCss = $this->cssFromImports($imports);
        if ( '' !== $importCss ) {
            $plan['css'] = $importCss;
            $plan['stylesheets'] = array(array('path' => 'assets/css/fonts.css', 'role' => 'stylesheet', 'mime_type' => 'text/css', 'content' => $importCss . "\n"));
        }
        if ( array() !== $faces ) {
            $plan['face_records'] = $faces;
            $plan['receipts'] = array_map(static fn (array $face): array => array('id' => 'webfont-receipt-' . substr(hash('sha256', $face['id']), 0, 20), 'face_ref' => $face['id'], 'import_ref' => $face['import_ref'], 'status' => 'pending_browser_readiness'), $faces);
            $plan['browser_readiness'] = array('schema' => 'blocks-engine/php-transformer/webfont-browser-readiness/v1', 'required' => true, 'face_records' => array_column($faces, 'id'), 'receipt_refs' => array_column($plan['receipts'], 'id'));
        }
        if ( array() !== $diagnostics ) $plan['diagnostics'] = $diagnostics;
        if ( isset($plan['stylesheets'][0]) ) {
            $plan['stylesheets'][0]['content_hash'] = hash('sha256', (string) $plan['stylesheets'][0]['content']);
            $plan['stylesheets'][0]['expected_content_hash'] = $plan['stylesheets'][0]['content_hash'];
        }
        return $plan;
    }

    /** @param array<int,array<string,mixed>> $imports */
    private function cssFromImports(array $imports): string
    {
        $urls = array();
        foreach ( $imports as $import ) if ( $import['supported'] ) $urls[] = '@import url("' . $import['href'] . '");';
        return implode("\n", $urls);
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
     * Parse web-font stylesheet URLs from CSS `@import` rules.
     *
     * @return array<int,array{family:string,weights:array<int,int>}>
     */
    private function fontUsageFromCssImports(string $css): array
    {
        $css = preg_replace('/\/\*.*?\*\//s', '', $css) ?? $css;
        if ( '' === trim($css) || ! preg_match_all('/@import\s+(?:url\(\s*)?(?:"([^"]+)"|\'([^\']+)\'|([^\s\)"\';]+))/i', $css, $matches, PREG_SET_ORDER) ) {
            return array();
        }

        $usage = array();
        foreach ( $matches as $match ) {
            $href = (string) (($match[1] ?? '') ?: ($match[2] ?? '') ?: ($match[3] ?? ''));
            foreach ( $this->fontUsageFromFontHref($href) as $font ) {
                $usage[] = $font;
            }
        }

        return $usage;
    }

    /**
     * Resolve CSS imports into stable source records and typed face declarations.
     *
     * @param array<int,array<string,mixed>> $cssSources
     * @return array<int,array<string,mixed>>
     */
    private function webFontImports(string $css, array $cssSources): array
    {
        $sources = array();
        foreach ( $cssSources as $source ) {
            if ( is_array($source) && is_string($source['content'] ?? null) ) $sources[] = array('content' => $source['content'], 'source_path' => (string) ($source['path'] ?? 'css:input'), 'source_hash' => (string) ($source['source_hash'] ?? hash('sha256', $source['content'])));
        }
        if ( array() === $sources && '' !== trim($css) ) $sources[] = array('content' => $css, 'source_path' => 'css:input', 'source_hash' => hash('sha256', $css));

        $imports = array();
        foreach ( $sources as $source ) {
            $content = preg_replace('/\/\*.*?\*\//s', '', $source['content']) ?? $source['content'];
            if ( ! preg_match_all('/@import\s+(?:url\(\s*)?(?:"([^"]+)"|\'([^\']+)\'|([^\s\)"\';]+))/i', $content, $matches, PREG_SET_ORDER) ) continue;
            foreach ( $matches as $index => $match ) {
                $href = html_entity_decode((string) (($match[1] ?? '') ?: ($match[2] ?? '') ?: ($match[3] ?? '')), ENT_QUOTES | ENT_HTML5);
                $supported = 'fonts.googleapis.com' === strtolower((string) parse_url($href, PHP_URL_HOST));
                $imports[] = array(
                    'id' => 'webfont-import-' . substr(hash('sha256', $source['source_path'] . "\n" . ($index + 1) . "\n" . $href), 0, 20),
                    'href' => $href,
                    'href_hash' => hash('sha256', $href),
                    'provider' => $supported ? 'google_fonts' : 'unsupported',
                    'supported' => $supported,
                    'font_usage' => $this->fontUsageFromFontHref($href),
                    'faces' => $supported ? $this->fontFacesFromFontHref($href) : array(),
                    'provenance' => array('source_kind' => 'css_import', 'source_path' => $source['source_path'], 'source_hash' => $source['source_hash'], 'selector' => 'css:@import(' . ($index + 1) . ')'),
                );
            }
        }
        usort($imports, static fn (array $left, array $right): int => strcmp($left['id'], $right['id']));
        return $imports;
    }

    /** @return array<int,array<string,mixed>> */
    private function fontFacesFromFontHref(string $href): array
    {
        $faces = array();
        foreach ( explode('&', (string) (parse_url($href, PHP_URL_QUERY) ?: '')) as $param ) {
            if ( ! preg_match('/^family=(.*)$/i', $param, $match) ) continue;
            foreach ( explode('|', urldecode((string) $match[1])) as $spec ) {
                [$familySpec, $axes] = array_pad(explode(':', trim($spec), 2), 2, '');
                $family = $this->normalizeFamily($familySpec);
                if ( '' === $family || $this->isWebSafeFontFamily($family) ) continue;
                foreach ( $this->typedFaces($family, $axes) as $face ) $faces[] = $face;
            }
        }
        usort($faces, static fn (array $left, array $right): int => strcmp($left['id'], $right['id']));
        return $faces;
    }

    /** @return array<int,array<string,mixed>> */
    private function typedFaces(string $family, string $axes): array
    {
        $declarations = array(array('style' => 'normal', 'weight' => array('kind' => 'static', 'value' => 400)));
        if ( str_contains($axes, '@') ) {
            [$names, $tuples] = explode('@', $axes, 2);
            $names = array_map(static fn (string $name): string => strtolower(trim($name)), explode(',', $names));
            $italIndex = array_search('ital', $names, true);
            $weightIndex = array_search('wght', $names, true);
            $declarations = array();
            foreach ( explode(';', $tuples) as $tuple ) {
                $values = explode(',', $tuple);
                $declarations[] = array('style' => '1' === trim((string) ($values[$italIndex] ?? '0')) ? 'italic' : 'normal', 'weight' => $this->typedWeight((string) ($values[$weightIndex] ?? end($values))));
            }
        } elseif ( '' !== $axes ) $declarations = array_map(fn (string $weight): array => array('style' => 'normal', 'weight' => $this->typedWeight($weight)), explode(',', $axes));
        $faces = array();
        foreach ( $declarations as $declaration ) {
            $style = $declaration['style'];
            $weight = $declaration['weight'];
            $weightKey = 'range' === $weight['kind'] ? $weight['min'] . '-' . $weight['max'] : (string) $weight['value'];
            $faces[] = array('id' => 'webfont-face-' . substr(hash('sha256', $family . "\n" . $style . "\n" . $weightKey), 0, 20), 'family' => $family, 'style' => $style, 'weight' => $weight);
        }
        return array_values(array_unique($faces, SORT_REGULAR));
    }

    /** @return array<string,int|string> */
    private function typedWeight(string $value): array
    {
        $value = trim($value);
        if ( preg_match('/^(\d{2,4})\.\.(\d{2,4})$/', $value, $range) ) return array('kind' => 'range', 'min' => (int) $range[1], 'max' => (int) $range[2]);
        return array('kind' => 'static', 'value' => is_numeric($value) ? (int) $value : 400);
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
            if ( '' === $family || $this->isWebSafeFontFamily($family) || $this->isInvalidFontFamily($family) ) {
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
            if ( '' !== $family && ! $this->isWebSafeFontFamily($family) && ! $this->isInvalidFontFamily($family) ) {
                return $family;
            }
        }

        return '';
    }

    /**
     * Expand `var(--name[, fallback])` references within a CSS string using the
     * custom-property definitions found in that same CSS. Bounded recursive
     * passes resolve variables whose values reference other variables. Leaves
     * unresolved references intact so they can be filtered as invalid families.
     */
    public function resolveCssVariables(string $css): string
    {
        if ( '' === trim($css) || ! str_contains($css, 'var(') ) {
            return $css;
        }

        $vars = $this->cssCustomProperties($css);
        if ( array() === $vars ) {
            return $css;
        }

        for ( $pass = 0; $pass < 5; $pass++ ) {
            $expanded = preg_replace_callback(
                '/var\(\s*(--[A-Za-z0-9_-]+)\s*(?:,\s*([^()]*))?\)/',
                static function (array $match) use ($vars): string {
                    $name = (string) $match[1];
                    if ( isset($vars[$name]) && '' !== $vars[$name] ) {
                        return $vars[$name];
                    }

                    return isset($match[2]) && '' !== trim((string) $match[2]) ? trim((string) $match[2]) : (string) $match[0];
                },
                $css
            );

            if ( ! is_string($expanded) || $expanded === $css ) {
                break;
            }
            $css = $expanded;
        }

        return $css;
    }

    /**
     * Collect `--name: value` custom-property declarations from CSS. Later
     * declarations win, mirroring the cascade for the common single-scope case.
     *
     * @return array<string,string>
     */
    private function cssCustomProperties(string $css): array
    {
        if ( ! preg_match_all('/(--[A-Za-z0-9_-]+)\s*:\s*([^;{}]+)/', $css, $matches, PREG_SET_ORDER) ) {
            return array();
        }

        $vars = array();
        foreach ( $matches as $match ) {
            $vars[(string) $match[1]] = trim((string) $match[2]);
        }

        return $vars;
    }

    /**
     * A real typeface name never contains CSS function syntax or starts with a
     * custom-property prefix. Such tokens (e.g. an unresolved `var(--font-body)`)
     * must never be emitted as a Google Fonts family — they corrupt the request.
     */
    private function isInvalidFontFamily(string $family): bool
    {
        return str_contains($family, '(')
            || str_contains($family, ')')
            || str_starts_with($family, '--')
            || in_array(strtolower($family), self::CSS_WIDE_KEYWORDS, true);
    }

    /**
     * Extract integer weights from a Google Fonts axis suffix.
     *
     * Supports `css2` axis tuples (`wght@400;700`, `ital,wght@0,400;1,700`),
     * Google Fonts ranges (`wght@300..900`), and the legacy `css` weight list
     * (`400,700`). Defaults to `[400]`.
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
                array_push($weights, ...$this->expandFontWeightToken((string) $value));
            }
        } else {
            foreach ( explode(',', $axes) as $token ) {
                array_push($weights, ...$this->expandFontWeightToken($token));
            }
        }

        return array() === $weights ? array(400) : $weights;
    }

    /**
     * @return array<int,int>
     */
    private function expandFontWeightToken(string $token): array
    {
        $token = trim($token);
        if ( preg_match('/^(\d{2,4})\.\.(\d{2,4})$/', $token, $range) ) {
            $start = max(1, min(1000, (int) $range[1]));
            $end = max(1, min(1000, (int) $range[2]));
            if ( $start > $end ) {
                [$start, $end] = array($end, $start);
            }

            $weights = array();
            for ( $weight = (int) (ceil($start / 100) * 100); $weight <= $end; $weight += 100 ) {
                $weights[] = $weight;
            }

            return array() === $weights ? array($start, $end) : $weights;
        }

        return is_numeric($token) ? array((int) $token) : array();
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
