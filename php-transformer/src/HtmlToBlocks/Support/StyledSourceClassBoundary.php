<?php

declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Support;

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Session\HtmlTransformerSession;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\AuthorStyleAnalysis;
use DOMElement;

/**
 * Detects a wrapper whose source classes still own author CSS, either on
 * the wrapper itself or as an ancestor boundary in a descendant rule.
 */
final class StyledSourceClassBoundary
{
    /**
     * Classes each candidate selector names, per selector: in any compound
     * (`all`) and in its ancestor compounds only (`ancestors`).
     *
     * @var array<string, array{all: array<string, true>, ancestors: array<string, true>}>
     */
    private array $namedClasses = array();

    /** @var array{all: array<string, true>, ancestors: array<string, true>}|null */
    private ?array $boundaryClasses = null;

    private ?AuthorStyleAnalysis $boundaryAnalysis = null;

    public function __construct(
        private readonly HtmlTransformerSession $session
    ) {
    }

    /**
     * Presentation attributes omit class-owned CSS. A wrapper can therefore
     * look empty while still owning inherited declarations or the ancestor
     * boundary needed by a descendant rule.
     */
    public function hasStyledSourceClassBoundary(DOMElement $element): bool
    {
        $classes = SourceDom::classNames($element);
        if (array() === $classes) return false;

        // No author rule names any of the classes: nothing below can match.
        // When none names one in an ancestor compound, descendants cannot
        // either, so only the element itself is checked (#6789).
        $boundary = $this->boundaryClasses();
        $namedAnywhere = false;
        $namedAsAncestor = false;
        foreach ($classes as $class) {
            $namedAnywhere = $namedAnywhere || isset($boundary['all'][$class]);
            $namedAsAncestor = $namedAsAncestor || isset($boundary['ancestors'][$class]);
        }
        if (! $namedAnywhere) return false;

        $nodes = array($element);
        if ($namedAsAncestor) {
            foreach ($element->getElementsByTagName('*') as $descendant) {
                $nodes[] = $descendant;
            }
        }
        foreach ($nodes as $node) {
            // On descendants the source class must be an ancestor, not
            // merely another class on the selector's subject.
            $scope = $node === $element ? 'all' : 'ancestors';
            foreach ($this->session->authorStyleRuleCandidates($node) as $candidate) {
                if (array() === $candidate['declarations'] || ! ($candidate['parsed']['supported'] ?? false)) continue;
                $named = $this->namedClasses($candidate)[$scope];
                $namesClass = false;
                foreach ($classes as $class) {
                    if (isset($named[$class])) {
                        $namesClass = true;
                        break;
                    }
                }
                if ($namesClass && $this->session->sourceStyleResolutionState()->selectorMatchCache->matches($node, $candidate['selector'], $candidate['parsed'])['matches']) return true;
            }
        }
        return false;
    }

    /**
     * Classes any author rule with declarations names, over every candidate
     * the session can return: a superset of what one element's candidates
     * name.
     *
     * @return array{all: array<string, true>, ancestors: array<string, true>}
     */
    private function boundaryClasses(): array
    {
        $analysis = $this->session->authorStyleAnalysis();
        if (null !== $this->boundaryClasses && $this->boundaryAnalysis === $analysis) return $this->boundaryClasses;
        $this->boundaryAnalysis = $analysis;
        $index = $analysis->styleRuleCandidateIndex();
        $lists = array($index['universal']);
        foreach (array('ids', 'classes', 'tags', 'attributes') as $bucket) {
            foreach ($index[$bucket] as $entries) $lists[] = $entries;
        }
        $boundary = array('all' => array(), 'ancestors' => array());
        foreach ($lists as $entries) {
            foreach ($entries as $entry) {
                $candidate = $entry['rule'];
                if (array() === $candidate['declarations'] || ! ($candidate['parsed']['supported'] ?? false)) continue;
                $named = $this->namedClasses($candidate);
                $boundary['all'] += $named['all'];
                $boundary['ancestors'] += $named['ancestors'];
            }
        }
        return $this->boundaryClasses = $boundary;
    }

    /**
     * @param array<string, mixed> $candidate
     * @return array{all: array<string, true>, ancestors: array<string, true>}
     */
    private function namedClasses(array $candidate): array
    {
        $key = ($candidate['key'] ?? '') . "\0" . $candidate['selector'];
        if (isset($this->namedClasses[$key])) return $this->namedClasses[$key];
        $named = array('all' => array(), 'ancestors' => array());
        $compounds = array_values($candidate['parsed']['compounds']);
        $last = count($compounds) - 1;
        foreach ($compounds as $position => $compound) {
            foreach ($compound['classes'] as $class) {
                $named['all'][(string) $class] = true;
                if ($position < $last) $named['ancestors'][(string) $class] = true;
            }
        }
        return $this->namedClasses[$key] = $named;
    }
}
