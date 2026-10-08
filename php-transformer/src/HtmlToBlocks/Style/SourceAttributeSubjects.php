<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style;

use Automattic\BlocksEngine\PhpTransformer\Css\CssSelectorCompoundInspector;
use Automattic\BlocksEngine\PhpTransformer\Css\CssSelectorMatcher;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Classification\FormControlClassifier;
use Automattic\BlocksEngine\PhpTransformer\Support\SourceAttribute;

/** Proves when data predicates still have their actual source DOM subjects. */
final class SourceAttributeSubjects
{
    /** @param array<string, mixed> $parsed */
    public static function retainsDataPredicates(array $parsed, AuthorStyleAnalysis $authorStyles): bool
    {
        if (!($parsed['supported'] ?? false)) return false;
        $found = false;
        foreach ($parsed['compounds'] ?? array() as $compound) {
            $names = CssSelectorCompoundInspector::dataAttributeNames($compound);
            if (array() === $names) continue;
            // Alternative subjects require the existing general projection.
            if (array() !== ($compound['any'] ?? array())) return false;
            if (count($names) !== count(SourceAttribute::staticAttributes(array_fill_keys($names, '')))) return false;
            $found = true;
            // Enumerate prospective subjects independently of the captured data
            // value, including absent and negated states that can change later.
            $shape = $compound;
            $shape['attributes'] = array_values(array_filter($shape['attributes'] ?? array(), static fn (array $attribute): bool => !str_starts_with($attribute['name'], 'data-')));
            $shape['not'] = array();
            $subject = array_replace($parsed, array('compounds' => array($shape), 'combinators' => array()));
            $owners = 0;
            foreach ($authorStyles->selectorCandidates($subject) as $element) {
                if (!CssSelectorMatcher::matches($element, $subject)['matches']) continue;
                ++$owners;
                if (!in_array(strtolower($element->tagName), array('html', 'body'), true)
                    && !FormControlClassifier::isExternalAssociatedLabel($element)) return false;
            }
            if (0 === $owners) return false;
        }
        return $found;
    }
}
