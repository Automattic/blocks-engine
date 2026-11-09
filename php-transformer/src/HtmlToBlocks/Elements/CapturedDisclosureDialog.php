<?php

declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Elements;

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\StyleResolver;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Support\SourceDom;
use DOMElement;

final class CapturedDisclosureDialog
{
    public function __construct(private readonly StyleResolver $styleResolver)
    {
    }

    /**
     * A details disclosure can only lower to a captured dialog when the source
     * provides the trigger linkage that makes the dialog operable at runtime.
     */
    public function fromDetails(DOMElement $element): ?DOMElement
    {
        $summary = null;
        $dialog = null;

        foreach ( $element->childNodes as $child ) {
            if ( XML_TEXT_NODE === $child->nodeType ) {
                if ( '' !== trim(str_replace("\xc2\xa0", ' ', $child->textContent ?? '')) ) {
                    return null;
                }
                continue;
            }

            if ( ! $child instanceof DOMElement ) {
                return null;
            }

            $tagName = strtolower($child->tagName);
            if ( 'summary' === $tagName && ! $summary instanceof DOMElement ) {
                $summary = $child;
                continue;
            }

            if ( 'dialog' === strtolower(SourceDom::attr($child, 'role')) && ! $dialog instanceof DOMElement ) {
                $dialog = $child;
                continue;
            }

            return null;
        }

        if ( ! $summary instanceof DOMElement || ! $dialog instanceof DOMElement ) {
            return null;
        }

        if ( '' === trim(SourceDom::attr($dialog, 'data-blocks-engine-triggers')) ) {
            return null;
        }

        $label = str_replace("\xc2\xa0", ' ', html_entity_decode($this->styleResolver->innerHtml($summary), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        if ( '' !== trim(strip_tags($label)) ) {
            return null;
        }

        foreach ( $dialog->getElementsByTagName('*') as $descendant ) {
            if ( $descendant instanceof DOMElement && in_array(strtolower($descendant->tagName), array( 'script', 'canvas', 'template', 'iframe', 'form' ), true) ) {
                return null;
            }
        }

        return $dialog;
    }
}
