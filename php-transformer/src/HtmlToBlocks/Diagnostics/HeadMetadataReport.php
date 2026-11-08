<?php

declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Diagnostics;

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Support\Utf8Text;
use DOMDocument;
use DOMElement;

final class HeadMetadataReport
{
    /**
     * Named head metadata (meta description, social property tags) has no
     * block-markup representation. Surface the entries so consumers can carry
     * them to the destination document deliberately instead of reading the
     * strip as a malformed design. Mechanical entries (charset, viewport)
     * belong to the destination document and are not reported.
     *
     * @return array<int, array<string, string>>
     */
    public static function fromHtml(string $html): array
    {
        if ( ! preg_match('/<meta[\s>]/i', $html) ) {
            return array();
        }

        $document = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $loaded = $document->loadHTML('<?xml encoding="utf-8" ?>' . $html);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        $head = $loaded ? $document->getElementsByTagName('head')->item(0) : null;
        if ( ! $head instanceof DOMElement ) {
            return array();
        }

        $entries = array();
        foreach ( $head->getElementsByTagName('meta') as $meta ) {
            if ( ! $meta instanceof DOMElement ) {
                continue;
            }
            $content = trim($meta->getAttribute('content'));
            $name = strtolower(trim($meta->getAttribute('name')));
            $property = strtolower(trim($meta->getAttribute('property')));
            if ( '' === $content || ( '' === $name && '' === $property ) || 'viewport' === $name ) {
                continue;
            }
            $entries[] = array_filter(array(
                'name'     => $name,
                'property' => $property,
                'content'  => Utf8Text::prefix($content, 500),
            ), static fn (string $value): bool => '' !== $value);
        }

        return array_slice($entries, 0, 20);
    }
}
