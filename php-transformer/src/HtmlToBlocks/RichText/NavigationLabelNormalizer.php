<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\RichText;

use Automattic\BlocksEngine\PhpTransformer\Css\CssValueSplitter;
use DOMDocument;
use DOMElement;

/** Compact presentation-only label chains after source markers have been carried. */
final class NavigationLabelNormalizer
{
    public static function normalize(string $html): string
    {
        if (!str_contains($html, '<span')) return $html;

        $document = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        try {
            $loaded = $document->loadHTML('<?xml encoding="utf-8" ?><body>' . $html . '</body>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
        $body = $document->getElementsByTagName('body')->item(0);
        if (!$loaded || !$body instanceof DOMElement) return $html;
        self::compact($body);
        $result = '';
        foreach ($body->childNodes as $child) $result .= $document->saveHTML($child);
        return $result;
    }

    private static function compact(DOMElement $element): void
    {
        // Work outside-in: class order and declaration precedence follow source
        // ancestry, not the order in which descendants happen to be visited.
        if (self::isPresentationSpan($element)) {
            while (1 === $element->childNodes->length) {
                $child = $element->firstChild;
                if (!$child instanceof DOMElement || !self::isPresentationSpan($child) || !self::folds($element, $child)) break;
                $outerClasses = self::classes($element);
                $classes = array() === $outerClasses ? self::classes($child) : $outerClasses;
                if (array() !== $classes) $element->setAttribute('class', implode(' ', $classes));
                $declarations = array();
                foreach (array($element, $child) as $carrier) {
                    foreach (CssValueSplitter::splitTopLevel($carrier->getAttribute('style'), array(';')) as $declaration) {
                        $pair = explode(':', $declaration, 2);
                        if (2 !== count($pair)) continue;
                        $property = trim($pair[0]);
                        if (!str_starts_with($property, '--')) $property = strtolower($property);
                        // Inner specified values override inherited outer values,
                        // including an outer !important declaration.
                        unset($declarations[$property]);
                        $declarations[$property] = trim($pair[1]);
                    }
                }
                if (array() !== $declarations) {
                    $style = '';
                    foreach ($declarations as $property => $value) $style .= $property . ':' . $value . ';';
                    $element->setAttribute('style', $style);
                }
                while ($child->firstChild) $element->insertBefore($child->firstChild, $child);
                $element->removeChild($child);
            }
        }
        foreach (iterator_to_array($element->childNodes) as $child) {
            if ($child instanceof DOMElement) self::compact($child);
        }
        if (self::isPresentationSpan($element) && '' === trim($element->getAttribute('class')) && '' === trim($element->getAttribute('style'))) {
            while ($element->firstChild) $element->parentNode->insertBefore($element->firstChild, $element);
            $element->parentNode->removeChild($element);
        }
    }

    /**
     * A child folds into its parent only when nothing observable changes:
     * its classes add nothing a selector could tell apart (so `.a .b` and
     * `.a > .b` still match as before), and both carry inherited typography
     * only, so no nested box (padding, background, border) is lost.
     */
    private static function folds(DOMElement $outer, DOMElement $inner): bool
    {
        $outerClasses = self::classes($outer);
        if (array() !== $outerClasses && array() !== array_diff(self::classes($inner), $outerClasses)) return false;
        foreach (array($outer, $inner) as $carrier) {
            foreach (CssValueSplitter::splitTopLevel($carrier->getAttribute('style'), array(';')) as $declaration) {
                $property = strtolower(trim(explode(':', $declaration, 2)[0]));
                if ('' !== $property && !in_array($property, self::INHERITED_TYPOGRAPHY, true)) return false;
            }
        }
        return true;
    }

    private const INHERITED_TYPOGRAPHY = array('color', 'font', 'font-family', 'font-size', 'font-style', 'font-weight', 'font-variant', 'letter-spacing', 'line-height', 'text-transform', 'word-spacing', 'white-space', 'text-shadow');

    /** @return list<string> */
    private static function classes(DOMElement $element): array
    {
        return array_values(array_unique(array_filter(preg_split('/\s+/', trim($element->getAttribute('class'))) ?: array(), static fn (string $value): bool => '' !== $value)));
    }

    private static function isPresentationSpan(DOMElement $element): bool
    {
        if ('span' !== strtolower($element->tagName)) return false;
        // Identity, language, accessibility and runtime attributes are boundaries,
        // not disposable styling. Projected presentation markers live in class.
        foreach ($element->attributes as $attribute) {
            if (!in_array($attribute->name, array('class', 'style'), true)) return false;
        }
        return true;
    }
}
