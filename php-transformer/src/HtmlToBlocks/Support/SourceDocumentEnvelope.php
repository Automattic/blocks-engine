<?php

declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Support;

use Automattic\BlocksEngine\PhpTransformer\Support\HtmlSerialization;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\StyleResolver;
use DOMElement;

/**
 * Reads the source document envelope: the <body> fragment to convert, its
 * classes and the root color theme, with explicit plaintext normalized first.
 */
final class SourceDocumentEnvelope
{
    public function __construct(
        private readonly StyleResolver $styleResolver
    ) {
    }

    public function normalizeExplicitPlaintextElements(string $html): string
    {
        $html = preg_replace_callback(
            '/<plaintext\b([^>]*)>(.*?)<\/plaintext\s*>/is',
            static fn (array $matches): string => '<pre' . $matches[1] . '>' . str_replace('<', '&lt;', $matches[2]) . '</pre>',
            $html
        ) ?? $html;

        // A real (unclosed) plaintext element consumes everything to EOF,
        // including entities and apparent closing body/html tags. Normalize it
        // before serializing and reparsing the document body: libxml's saved
        // </plaintext> would otherwise become extra visible text on that pass.
        return preg_replace_callback(
            '/<plaintext\b([^>]*)>(.*)\z/is',
            static fn (array $matches): string => '<pre' . $matches[1] . '>' . htmlspecialchars($matches[2], ENT_NOQUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</pre>',
            $html
        ) ?? $html;
    }

    public function documentBodyHtml(string $html): string
    {
        if ( ! preg_match('/<(?:!doctype|html|head|body)\b/i', $html) ) {
            return $html;
        }

        $document = HtmlSerialization::parseDocument($html);

        $body = $document->getElementsByTagName('body')->item(0);
        if ( ! $body instanceof DOMElement ) {
            return $html;
        }

        return $this->styleResolver->innerHtml($body);
    }

    public function documentRootTheme(string $html): string
    {
        if ( ! preg_match('/<html\b[^>]*\bclass\s*=\s*(["\'])(.*?)\1/is', $html, $match) ) {
            return '';
        }
        $classes = SourceDom::tokens(html_entity_decode($match[2], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'));
        if ( in_array('dark', $classes, true) ) {
            return 'dark';
        }
        return in_array('light', $classes, true) ? 'light' : '';
    }

    /** @return list<string> */
    public function documentBodyClassNames(string $html): array
    {
        if ( ! preg_match('/<body\b/i', $html) ) {
            return array();
        }

        $document = HtmlSerialization::parseDocument($html);
        $body = $document->getElementsByTagName('body')->item(0);
        if ( ! $body instanceof DOMElement ) {
            return array();
        }

        return array_values(array_filter(array_unique(SourceDom::tokens(SourceDom::attr($body, 'class')))));
    }
}
