<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Elements;

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Session\HtmlTransformerSession;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Support\SourceDom;
use Closure;
use DOMElement;
use DOMNode;

/** Discovers form success panels and builds provider-neutral metadata. */
final class FormSuccessPanelMetadataBuilder
{
    /** @param Closure(DOMElement): string $innerHtml */
    public function __construct(
        private readonly HtmlTransformerSession $session,
        private readonly Closure $innerHtml
    ) {
    }

    /** @return array<string, mixed> */
    public function build(DOMElement $form): array
    {
        if ( 'form' !== strtolower($form->tagName) ) {
            foreach ( $form->getElementsByTagName('*') as $descendant ) {
                if ( $descendant instanceof DOMElement && $this->hasSignal($descendant) ) {
                    return $this->metadata($descendant);
                }
            }

            return array();
        }

        for ( $sibling = $form->nextSibling; $sibling instanceof DOMNode; $sibling = $sibling->nextSibling ) {
            if ( XML_TEXT_NODE === $sibling->nodeType && '' === trim($sibling->textContent ?? '') ) {
                continue;
            }

            if ( ! $sibling instanceof DOMElement || ! $this->hasSignal($sibling) ) {
                return array();
            }

            return $this->metadata($sibling);
        }

        return array();
    }

    /** @return array<string, mixed> */
    private function metadata(DOMElement $element): array
    {
        $boundedHtml = SourceDom::boundedFallbackHtml(SourceDom::safeFallbackHtml($element, $this->session->authorSelectorProjectionState()->tagMarkers()));
        return array_filter(array(
            'selector'       => SourceDom::elementSelector($element),
            'id'             => SourceDom::attr($element, 'id'),
            'class'          => SourceDom::attr($element, 'class'),
            'role'           => SourceDom::attr($element, 'role'),
            'aria_live'      => SourceDom::attr($element, 'aria-live'),
            'text'           => $this->normalizedText($element),
            'html'           => $boundedHtml['html'],
            'html_bytes'     => $boundedHtml['bytes'],
            'html_truncated' => $boundedHtml['truncated'],
        ), static fn (mixed $value): bool => is_bool($value) || is_int($value) || '' !== trim((string) $value));
    }

    private function normalizedText(DOMElement $element): string
    {
        $html = preg_replace('/<\/?[a-z][a-z0-9]*\b[^>]*>/i', ' ', ($this->innerHtml)($element)) ?? $element->textContent ?? '';
        return trim(preg_replace('/\s+/', ' ', html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8')) ?? '');
    }

    private function hasSignal(DOMElement $element): bool
    {
        $role = strtolower(SourceDom::attr($element, 'role'));
        if ( in_array($role, array( 'status', 'alert' ), true) ) {
            return true;
        }

        $tokens = strtolower(trim(SourceDom::attr($element, 'id') . ' ' . SourceDom::attr($element, 'class') . ' ' . SourceDom::attr($element, 'aria-live')));
        return (bool) preg_match('/(?:^|[^a-z0-9])(?:success|sent|submitted|thank|thanks|confirmation|confirmed)(?:[^a-z0-9]|$)/', $tokens);
    }
}
