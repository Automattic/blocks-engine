<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Elements;

use DOMElement;
use DOMText;

/** Source-account checkout frames cannot be replayed by the destination store. */
final class PaymentProcessorWidgetPruner
{
    /** Exact hosts and bounded runtime paths, never source-platform class names. */
    private const RUNTIME_PATHS = array(
        'js.stripe.com' => '~^/(?:v3|[a-z]+/v3)/(?:elements[^/]*|checkout[^/]*)(?:/|[.-]|$)~i',
        'www.paypal.com' => '~^/(?:sdk|smart/payment)(?:/|$)~i',
        'www.paypalobjects.com' => '~^/(?:upstream/assets/)?(?:messaging|messages)(?:/|$)~i',
        'x.klarnacdn.net' => '~^/(?:osk|osm|onsite-messaging)(?:/|$)~i',
        'js.klarna.com' => '~^/web-sdk/(?:messaging|onsite-messaging)(?:/|$)~i',
        'static.afterpay.com' => '~^/(?:messaging|checkout)(?:/|$)~i',
    );

    /** @return list<array<string, mixed>> */
    public static function prune(DOMElement $body): array
    {
        $findings = array();
        // Snapshot: removing an ancestor must not skip the next live NodeList entry.
        foreach ( iterator_to_array($body->getElementsByTagName('iframe')) as $iframe ) {
            $url = parse_url(trim($iframe->getAttribute('src')));
            if ( ! is_array($url)
                || ! in_array(strtolower($url['scheme'] ?? 'https'), array( 'http', 'https' ), true)
                || ! isset(self::RUNTIME_PATHS[strtolower($url['host'] ?? '')])
                || 1 !== preg_match(self::RUNTIME_PATHS[strtolower($url['host'])], $url['path'] ?? '/') ) {
                continue;
            }

            // Do not copy merchant/session-bearing URLs into diagnostics either.
            $findings[] = array(
                'diagnostic_code' => 'html_payment_processor_widget_omitted',
                'severity' => 'info',
                'conversion_classification' => 'native_conversion',
                'preservation_strategy' => 'omitted_source_checkout_runtime',
                'runtime_requirement' => 'destination_payment_gateway',
                'reason' => 'Source payment widget omitted; configure payment messaging in the destination commerce runtime.',
                'processor_host' => strtolower($url['host']),
            );
            $parent = $iframe->parentNode;
            $parent?->removeChild($iframe);
            // Only ancestors emptied by this omission lose their authored geometry.
            while ( $parent instanceof DOMElement && $parent !== $body
                && (in_array(strtolower($parent->tagName), array( 'div', 'span', 'section', 'aside', 'p' ), true) || str_contains($parent->tagName, '-'))
                && self::isEmpty($parent) ) {
                $wrapper = $parent;
                $parent = $wrapper->parentNode;
                $parent?->removeChild($wrapper);
            }
        }
        return $findings;
    }

    private static function isEmpty(DOMElement $element): bool
    {
        foreach ( $element->childNodes as $child ) {
            if ( $child instanceof DOMElement || ($child instanceof DOMText && '' !== trim($child->textContent)) ) {
                return false;
            }
        }
        return true;
    }
}
