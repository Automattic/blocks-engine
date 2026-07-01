<?php

declare(strict_types=1);

namespace Automattic\BlocksEngine\FigmaTransformer\Html;

/**
 * Assembles static HTML artifact wrappers and shared stylesheet scaffolding.
 */
final class HtmlArtifactAssembler
{
    /**
     * @param callable(string): string $attributeSanitizer
     */
    public function __construct(
        private readonly mixed $attributeSanitizer,
    ) {
    }

    /**
     * @return array<int, string>
     */
    public function baseCssRules(bool $renderTextGlyphPaths): array
    {
        $rules = array(
            'html{box-sizing:border-box}',
            '*,*::before,*::after{box-sizing:inherit}',
            'body{margin:0}',
            '.figma-root{position:relative;width:100%}',
            'p,h1,h2,h3,h4,h5,h6{margin:0}',
            'ul,ol{margin:0;padding:0;list-style:none}',
            'img{display:block;max-width:100%;height:auto}',
            'a.figma-link{display:contents;color:inherit;text-decoration:inherit}',
            '.figma-vector-asset{display:block;width:100%;height:100%;object-fit:fill}',
        );
        if ( $renderTextGlyphPaths ) {
            $rules[] = '.figma-text-glyphs{display:block;width:100%;height:100%;overflow:visible}';
        }

        return $rules;
    }

    /**
     * @param array<int, string> $cssRules
     * @param array<int, string> $mediaBlocks
     */
    public function stylesheet(string $fontCss, string $designSystemCss, array $cssRules, array $mediaBlocks = array(), bool $dedupeRules = false): string
    {
        if ( $dedupeRules ) {
            $cssRules = array_values(array_unique($cssRules));
        }

        $css = ('' !== $fontCss ? $fontCss . "\n" : '')
            . ('' !== $designSystemCss ? $designSystemCss : '')
            . implode("\n", $cssRules) . "\n";
        if ( ! empty($mediaBlocks) ) {
            // Responsive overrides cascade after the widest-first base rules so
            // narrower breakpoints win at their own viewport width.
            $css .= implode("\n", $mediaBlocks) . "\n";
        }

        return $css;
    }

    public function htmlDocument(string $title, string $stylesheetHref, string $body): string
    {
        return "<!doctype html>\n<html lang=\"en\">\n<head>\n<meta charset=\"utf-8\">\n<meta name=\"viewport\" content=\"width=device-width, initial-scale=1\">\n<title>" . $title . "</title>\n<link rel=\"stylesheet\" href=\"" . $this->sanitizeAttribute($stylesheetHref) . "\">\n</head>\n<body>\n<main class=\"figma-root\" data-figma-root=\"true\">\n" . $body . "</main>\n</body>\n</html>\n";
    }

    /**
     * @param array<int, array<string, mixed>> $files
     */
    public function htmlFilesContent(array $files): string
    {
        $html = '';
        foreach ( $files as $file ) {
            if ( is_array($file) && 'text/html' === ($file['mime_type'] ?? null) && isset($file['content']) && is_scalar($file['content']) ) {
                $html .= "\n" . (string) $file['content'];
            }
        }

        return $html;
    }

    private function sanitizeAttribute(string $value): string
    {
        $sanitizeAttribute = $this->attributeSanitizer;
        return $sanitizeAttribute($value);
    }
}
