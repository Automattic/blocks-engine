<?php

declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Support;

use DOMDocument;
use DOMElement;

/**
 * Fills empty counter targets with the final value a page builder's
 * PlatformElementSettings script would animate to.
 */
final class DeclarativeCounterMaterializer
{
    public static function materialize(DOMElement $body, string $declarativeStateHtml = ''): void
    {
        $document = $body->ownerDocument;
        if ( ! $document instanceof DOMDocument ) {
            return;
        }

        $scriptSources = array();
        foreach ( $body->getElementsByTagName('script') as $script ) {
            if ( $script instanceof DOMElement ) {
                $scriptSources[] = (string) $script->textContent;
            }
        }
        if ( '' !== $declarativeStateHtml && preg_match_all('@<script\b[^>]*>(.*?)</script>@is', $declarativeStateHtml, $scriptMatches) ) {
            $scriptSources = array_merge($scriptSources, $scriptMatches[1]);
        }

        foreach ( array_unique($scriptSources) as $source ) {
            if ( ! str_contains($source, 'PlatformElementSettings')
                || ! preg_match('/\.prototype\.element_id\s*=\s*(["\'])([a-z0-9-]+)\1/i', $source, $elementMatch)
            ) {
                continue;
            }

            $settingsStart = strpos($source, 'new PlatformElementSettings(');
            $settingsEnd = false !== $settingsStart ? strpos($source, ');', $settingsStart) : false;
            if ( false === $settingsStart || false === $settingsEnd || $settingsEnd - $settingsStart > 262144 ) {
                continue;
            }
            $settingsLiteral = substr($source, $settingsStart, $settingsEnd - $settingsStart);
            if ( ! preg_match('/["\']end["\']\s*:\s*(-?(?:0|[1-9]\d*)(?:\.\d+)?)\s*[,}]/', $settingsLiteral, $endMatch) ) {
                continue;
            }
            $end = str_contains($endMatch[1], '.') ? (float) $endMatch[1] : (int) $endMatch[1];

            $container = $document->getElementById('element-' . $elementMatch[2]);
            if ( ! $container instanceof DOMElement ) {
                continue;
            }
            foreach ( $container->getElementsByTagName('*') as $target ) {
                if ( ! $target instanceof DOMElement
                    || ! in_array('content-number-bold', preg_split('/\s+/', trim($target->getAttribute('class'))) ?: array(), true)
                    || '' !== trim((string) $target->textContent)
                ) {
                    continue;
                }
                $target->appendChild($document->createTextNode((string) $end));
                break;
            }
        }
    }
}
