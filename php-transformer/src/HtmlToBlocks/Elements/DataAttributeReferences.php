<?php

declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Elements;

use Automattic\BlocksEngine\PhpTransformer\Css\CssValueSplitter;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Session\HtmlTransformerSession;
use Automattic\BlocksEngine\PhpTransformer\Support\RuntimeSelectorVocabulary;
use DOMDocument;
use DOMElement;

/**
 * Whether a source `data-*` attribute is addressed by anything on the page:
 * an author stylesheet selector or a document script (by attribute name or
 * `dataset` property). An unaddressed data attribute is inert, so a native
 * block that cannot carry it loses nothing by dropping it.
 */
final class DataAttributeReferences
{
    public static function isReferenced(HtmlTransformerSession $session, DOMElement $element, string $attribute): bool
    {
        $attribute = strtolower($attribute);
        foreach ( CssValueSplitter::ruleBlocks($session->authorStyleAnalysis()->combinedCss()) as $rule ) {
            foreach ( RuntimeSelectorVocabulary::dataAttributeSelectorsFromCssSelector($rule['selector']) as $selector ) {
                if ( preg_match('/\\[' . preg_quote($attribute, '/') . '(?:\\]|[\\s*=~|^$*])/', $selector) ) {
                    return true;
                }
            }
        }

        $document = $element->ownerDocument;
        if ( ! $document instanceof DOMDocument ) {
            return false;
        }
        $datasetName = preg_replace_callback('/-([a-z])/', static fn (array $match): string => strtoupper($match[1]), substr($attribute, 5));
        $references = '/\\b' . preg_quote($attribute, '/') . '\\b|\\bdataset\\s*\.\\s*' . preg_quote((string) $datasetName, '/') . '\\b/i';
        foreach ( $document->getElementsByTagName('script') as $script ) {
            if ( $script instanceof DOMElement && preg_match($references, (string) $script->textContent) ) {
                return true;
            }
        }

        return false;
    }
}
