<?php

declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Support;

use DOMDocument;
use DOMElement;

/** Context-free handoff of self-contained SVG artwork to native media consumers. */
final class StandaloneSvgAsset
{
    /** @return array{status:string,width?:int,height?:int} */
    public static function inspect(string $bytes): array
    {
        if ( '' === trim($bytes) || strlen($bytes) > 2 * 1024 * 1024 || preg_match('/<!\s*(?:DOCTYPE|ENTITY)\b/i', $bytes) ) {
            return array( 'status' => 'invalid_svg' );
        }
        $document = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        try {
            $loaded = $document->loadXML($bytes, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_COMPACT);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
        $root = $document->documentElement;
        if ( ! $loaded || ! $root instanceof DOMElement || 'svg' !== $root->tagName || 'http://www.w3.org/2000/svg' !== $root->namespaceURI ) {
            return array( 'status' => 'invalid_svg' );
        }
        // Page materialization may resolve CSS before extracting an asset. A raw
        // standalone document has no such context: accept the existing passive
        // classification only when every element belongs to this SVG document.
        $nodes = array( $root );
        foreach ( $root->getElementsByTagName('*') as $child ) {
            $nodes[] = $child;
        }
        foreach ( $document->childNodes as $node ) {
            if ( XML_PI_NODE === $node->nodeType ) {
                return array( 'status' => 'unsupported_svg_context' );
            }
        }
        foreach ( $nodes as $node ) {
            if ( 'http://www.w3.org/2000/svg' !== $node->namespaceURI || in_array(strtolower($node->localName), array( 'style', 'link' ), true) || $node->hasAttribute('style') ) {
                return array( 'status' => 'unsupported_svg_context' );
            }
            foreach ( $node->childNodes as $child ) {
                if ( XML_PI_NODE === $child->nodeType ) {
                    return array( 'status' => 'unsupported_svg_context' );
                }
            }
        }
        if ( ! SvgMaterializer::isPassiveSvgMarkup($root) || ! SourceDom::svgHasDrawableContent($root) ) {
            return array( 'status' => 'unsupported_svg_content' );
        }
        $width = self::length($root->getAttribute('width'));
        $height = self::length($root->getAttribute('height'));
        $viewBox = preg_split('/[\s,]+/', trim($root->getAttribute('viewBox')));
        if ( is_array($viewBox) && 4 === count($viewBox) && count(array_filter($viewBox, 'is_numeric')) === 4 && (float) $viewBox[2] > 0 && (float) $viewBox[3] > 0 ) {
            $ratio = (float) $viewBox[2] / (float) $viewBox[3];
            if ( null === $width && null === $height ) {
                $width = (float) $viewBox[2];
                $height = (float) $viewBox[3];
            } elseif ( null === $width ) {
                $width = $height * $ratio;
            } elseif ( null === $height ) {
                $height = $width / $ratio;
            }
        }
        if ( null === $width || null === $height || ! is_finite($width) || ! is_finite($height) || $width <= 0 || $height <= 0 || $width > 100000 || $height > 100000 ) {
            return array( 'status' => 'unresolved_svg_dimensions' );
        }
        return array( 'status' => 'supported', 'width' => max(1, (int) round($width)), 'height' => max(1, (int) round($height)) );
    }

    private static function length(string $value): ?float
    {
        return preg_match('/^\s*(\d+(?:\.\d+)?|\.\d+)(?:px)?\s*$/iD', $value, $match) ? (float) $match[1] : null;
    }
}
