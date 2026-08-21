<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks;

use Automattic\BlocksEngine\PhpTransformer\WordPress\GeneratedGutenbergClassPolicy;
use DOMElement;
use DOMNode;

final class ReusableComponentRecognizer
{
    private const MAX_CANDIDATES = 256;
    private const MAX_COMPONENTS = 32;
    private const MAX_OCCURRENCES = 8;
    private const MAX_NEAR_MATCHES = 16;
    private const MAX_NODES = 2048;
    private const MAX_DEPTH = 32;
    private const MAX_SIGNATURE_BYTES = 8192;
    private const MAX_SIGNATURE_WORK = 32768;

    /** @var array<string, true> */
    private const CANDIDATE_TAGS = array('article' => true, 'aside' => true, 'div' => true, 'figure' => true, 'footer' => true, 'form' => true, 'header' => true, 'li' => true, 'main' => true, 'nav' => true, 'ol' => true, 'section' => true, 'svg' => true, 'ul' => true);

    /** @return array<string, mixed> */
    public function recognize(DOMElement $root): array
    {
        $state = array('nodes' => 0, 'signature_work' => 0, 'omitted_candidates' => 0, 'truncated' => array());
        $candidates = array();
        $this->collect($root, 0, $candidates, $state);
        $exact = array();
        $shape = array();
        foreach ($candidates as $candidate) {
            $exact[$candidate['fingerprint']][] = $candidate;
            $shape[$candidate['shape_fingerprint']][] = $candidate;
        }
        $components = array();
        foreach ($exact as $fingerprint => $occurrences) {
            if (count($occurrences) < 2) continue;
            $components[] = $this->component($fingerprint, $occurrences);
        }
        usort($components, static fn(array $a, array $b): int => $b['occurrence_count'] <=> $a['occurrence_count'] ?: strcmp($a['fingerprint'], $b['fingerprint']));
        $nearMatches = array();
        foreach ($shape as $shapeFingerprint => $occurrences) {
            $variants = array_values(array_unique(array_column($occurrences, 'fingerprint')));
            if (count($variants) < 2) continue;
            sort($variants, SORT_STRING);
            $nearMatches[] = array('shape_fingerprint' => $shapeFingerprint, 'occurrence_count' => count($occurrences), 'rejected_reason' => 'style_or_semantic_attribute_variation', 'fingerprints' => array_slice($variants, 0, self::MAX_OCCURRENCES));
        }
        usort($nearMatches, static fn(array $a, array $b): int => $b['occurrence_count'] <=> $a['occurrence_count'] ?: strcmp($a['shape_fingerprint'], $b['shape_fingerprint']));
        $componentOmitted = max(0, count($components) - self::MAX_COMPONENTS);
        $nearMatchOmitted = max(0, count($nearMatches) - self::MAX_NEAR_MATCHES);
        if (0 < $componentOmitted) $state['truncated'][] = 'max_components';
        if (0 < $nearMatchOmitted) $state['truncated'][] = 'max_near_matches';
        if (array_filter($components, static fn(array $component): bool => !empty($component['incomplete']))) $state['truncated'][] = 'max_occurrences';
        $truncated = array_values(array_unique($state['truncated']));
        return array('schema' => 'blocks-engine/reusable-component-recognition/v1', 'scanned_node_count' => $state['nodes'], 'candidate_count' => count($candidates), 'omitted_candidate_count' => $state['omitted_candidates'], 'retained_component_count' => min(count($components), self::MAX_COMPONENTS), 'omitted_component_count' => $componentOmitted, 'components' => array_slice($components, 0, self::MAX_COMPONENTS), 'near_match_limit' => self::MAX_NEAR_MATCHES, 'retained_near_match_count' => min(count($nearMatches), self::MAX_NEAR_MATCHES), 'omitted_near_match_count' => $nearMatchOmitted, 'near_match_truncation_reason' => 0 < $nearMatchOmitted ? 'max_near_matches' : '', 'near_matches' => array_slice($nearMatches, 0, self::MAX_NEAR_MATCHES), 'candidates' => $candidates, 'limits' => array('max_candidates' => self::MAX_CANDIDATES, 'max_components' => self::MAX_COMPONENTS, 'max_near_matches' => self::MAX_NEAR_MATCHES, 'max_nodes' => self::MAX_NODES, 'max_depth' => self::MAX_DEPTH, 'max_signature_bytes' => self::MAX_SIGNATURE_BYTES, 'max_signature_work' => self::MAX_SIGNATURE_WORK), 'truncated' => $truncated, 'incomplete' => array() !== $truncated);
    }

    /** @param list<array<string, string>> $occurrences @return array<string, mixed> */
    private function component(string $fingerprint, array $occurrences): array
    {
        $first = $occurrences[0];
        $retained = min(count($occurrences), self::MAX_OCCURRENCES);
        $omitted = count($occurrences) - $retained;
        return array('fingerprint' => $fingerprint, 'tag' => $first['tag'], 'occurrence_count' => count($occurrences), 'mapping' => 'svg' === $first['tag'] ? 'pending_core_image_asset_verification' : 'capability_gap:no_safe_reusable_block_mapping', 'occurrence_limit' => self::MAX_OCCURRENCES, 'retained_occurrence_count' => $retained, 'omitted_occurrence_count' => $omitted, 'truncated' => 0 < $omitted, 'truncation_reason' => 0 < $omitted ? 'max_occurrences' : '', 'incomplete' => 0 < $omitted, 'occurrences' => array_slice(array_map(static fn(array $row): array => array('path' => $row['path'], 'tag' => $row['tag']), $occurrences), 0, self::MAX_OCCURRENCES));
    }

    /** @param list<array<string, string>> $candidates @param array<string, mixed> $state */
    private function collect(DOMElement $element, int $depth, array &$candidates, array &$state): void
    {
        if ($depth >= self::MAX_DEPTH) {
            $state['truncated'][] = 'max_depth';
            return;
        }
        foreach ($element->childNodes as $child) {
            if (!$child instanceof DOMElement) continue;
            if (++$state['nodes'] > self::MAX_NODES) {
                $state['truncated'][] = 'max_nodes';
                return;
            }
            if (isset(self::CANDIDATE_TAGS[strtolower($child->tagName)]) && $this->isSubtreeCandidate($child)) {
                if (count($candidates) >= self::MAX_CANDIDATES) {
                    ++$state['omitted_candidates'];
                    $state['truncated'][] = 'max_candidates';
                    $this->collect($child, $depth + 1, $candidates, $state);
                    continue;
                }
                $exact = $this->signature($child, false, $state);
                $shape = null === $exact ? null : $this->signature($child, true, $state);
                if (null !== $exact && null !== $shape) $candidates[] = array('tag' => strtolower($child->tagName), 'path' => $child->getNodePath(), 'fingerprint' => hash('sha256', $exact), 'shape_fingerprint' => hash('sha256', $shape));
                else ++$state['omitted_candidates'];
            }
            $this->collect($child, $depth + 1, $candidates, $state);
        }
    }

    private function isSubtreeCandidate(DOMElement $element): bool
    {
        if ('svg' === strtolower($element->tagName)) return true;
        foreach ($element->childNodes as $child) if ($child instanceof DOMElement) return true;
        return false;
    }

    /** @param array<string, mixed> $state */
    private function signature(DOMNode $node, bool $shapeOnly, array &$state): ?string
    {
        if (++$state['signature_work'] > self::MAX_SIGNATURE_WORK) {
            $state['truncated'][] = 'max_signature_work';
            return null;
        }
        if (XML_TEXT_NODE === $node->nodeType) return '' === trim((string) $node->textContent) ? '' : '#text';
        if (!$node instanceof DOMElement) return '';
        $attributes = array();
        foreach ($node->attributes as $attribute) {
            $name = strtolower($attribute->name);
            $value = $this->normalizedAttributeValue($name, $attribute->value);
            if (null === $value) continue;
            $attributes[] = $shapeOnly ? $name : ($this->isContentSlot($name) ? $name . '=#slot' : $name . '=' . $value);
        }
        sort($attributes, SORT_STRING);
        $signature = '<' . strtolower($node->tagName) . ' ' . implode(' ', $attributes) . '>';
        foreach ($node->childNodes as $child) {
            $childSignature = $this->signature($child, $shapeOnly, $state);
            if (null === $childSignature) return null;
            $signature .= $childSignature;
            if (strlen($signature) > self::MAX_SIGNATURE_BYTES) {
                $state['truncated'][] = 'max_signature_bytes';
                return null;
            }
        }
        return $signature . '</' . strtolower($node->tagName) . '>';
    }

    private function isContentSlot(string $name): bool
    {
        return in_array($name, array('action', 'alt', 'content', 'href', 'placeholder', 'src', 'title', 'value'), true) || str_starts_with($name, 'aria-');
    }

    private function normalizedAttributeValue(string $name, string $value): ?string
    {
        if ('class' !== $name) return trim($value);
        $classes = array();
        foreach (preg_split('/\s+/', trim($value)) ?: array() as $class) {
            // These names are emitted by WordPress core, unlike CSS-module-like
            // source classes whose styling provenance cannot be proven here.
            if (!GeneratedGutenbergClassPolicy::isGeneratedClassName($class)) $classes[] = $class;
        }
        sort($classes, SORT_STRING);
        return array() === $classes ? null : implode(' ', array_unique($classes));
    }
}
