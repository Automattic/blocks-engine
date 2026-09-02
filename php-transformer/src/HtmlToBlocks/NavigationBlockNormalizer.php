<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks;

use DOMElement;

/**
 * Reconciles responsive source-navigation variants before and after conversion.
 */
final class NavigationBlockNormalizer
{
    /** @var callable(string): string */
    private $normalizeLabel;

    /** @param callable(string): string $normalizeLabel */
    public function __construct(callable $normalizeLabel)
    {
        $this->normalizeLabel = $normalizeLabel;
    }

    public function hydrateDuplicateSubmenus(DOMElement $body): void
    {
        $variants = array();
        foreach ( $body->getElementsByTagName('li') as $item ) {
            if ( ! $item instanceof DOMElement ) {
                continue;
            }

            $id = trim($item->getAttribute('id'));
            $anchor = $this->directItemAnchor($item);
            if ( '' === $id || ! $anchor instanceof DOMElement ) {
                continue;
            }

            $label = ($this->normalizeLabel)($anchor->textContent ?? '');
            if ( '' === $label ) {
                continue;
            }

            $variants[$id . '|' . $label][] = $item;
        }

        foreach ( $variants as $items ) {
            if ( 2 > count($items) ) {
                continue;
            }

            $sourceCarriers = array();
            $sourceLinkCount = 0;
            foreach ( $items as $item ) {
                $carriers = $this->directSubmenuCarriers($item);
                $linkCount = 0;
                foreach ( $carriers as $carrier ) {
                    $linkCount += $carrier->getElementsByTagName('a')->length;
                }
                if ( $linkCount > $sourceLinkCount ) {
                    $sourceCarriers = $carriers;
                    $sourceLinkCount = $linkCount;
                }
            }

            if ( 0 === $sourceLinkCount ) {
                continue;
            }

            foreach ( $items as $item ) {
                if ( array() !== $this->directSubmenuCarriers($item) ) {
                    continue;
                }
                foreach ( $sourceCarriers as $carrier ) {
                    $item->appendChild($carrier->cloneNode(true));
                }
            }
        }
    }

    /**
     * @param array<int, array<string, mixed>> $blocks
     * @param array<int, array<string, mixed>> $sourceProvenance
     * @param array<int, bool> $sourceBaseHiddenStates
     * @return array<int, array<string, mixed>>
     */
    public function normalize(array $blocks, array $sourceProvenance, array $sourceBaseHiddenStates): array
    {
        $seen = array();
        return $this->normalizeRecursive($blocks, $seen, $sourceProvenance, $sourceBaseHiddenStates);
    }

    private function directItemAnchor(DOMElement $item): ?DOMElement
    {
        foreach ( $item->childNodes as $child ) {
            if ( $child instanceof DOMElement && 'a' === strtolower($child->tagName) ) {
                return $child;
            }
        }

        return null;
    }

    /** @return array<int, DOMElement> */
    private function directSubmenuCarriers(DOMElement $item): array
    {
        $carriers = array();
        foreach ( $item->childNodes as $child ) {
            if ( ! $child instanceof DOMElement || 'a' === strtolower($child->tagName) ) {
                continue;
            }
            if ( 0 < $child->getElementsByTagName('a')->length
                && ( 0 < $child->getElementsByTagName('ul')->length || 0 < $child->getElementsByTagName('ol')->length )
            ) {
                $carriers[] = $child;
            }
        }

        return $carriers;
    }

    /**
     * @param array<int, array<string, mixed>> $blocks
     * @param array<string, bool> $seen
     * @param array<int, array<string, mixed>> $sourceProvenance
     * @param array<int, bool> $sourceBaseHiddenStates
     * @return array<int, array<string, mixed>>
     */
    private function normalizeRecursive(array $blocks, array &$seen, array $sourceProvenance, array $sourceBaseHiddenStates): array
    {
        $blocks = $this->preferVisibleSiblings($blocks, $sourceBaseHiddenStates);
        $deduplicated = array();
        foreach ( $blocks as $block ) {
            if ( ! is_array($block) ) {
                continue;
            }

            if ( ! empty($block['innerBlocks']) && is_array($block['innerBlocks']) ) {
                $block['innerBlocks'] = $this->normalizeRecursive($block['innerBlocks'], $seen, $sourceProvenance, $sourceBaseHiddenStates);
                $block = $this->reconcileInnerContentChildPlaceholders($block);
            }

            if ( 'core/navigation' === ($block['blockName'] ?? '') ) {
                $signature = $this->signature($block);
                if ( '' !== $signature && isset($seen[$signature]) && $this->isMobileDuplicate($block, $sourceProvenance) ) {
                    continue;
                }
                if ( '' !== $signature ) {
                    $seen[$signature] = true;
                }
            }

            $deduplicated[] = $block;
        }

        return $deduplicated;
    }

    /**
     * @param array<int, array<string, mixed>> $blocks
     * @param array<int, bool> $sourceBaseHiddenStates
     * @return array<int, array<string, mixed>>
     */
    private function preferVisibleSiblings(array $blocks, array $sourceBaseHiddenStates): array
    {
        $preferred = array();
        $discarded = array();
        foreach ( $blocks as $index => $block ) {
            if ( ! is_array($block) || 'core/navigation' !== ($block['blockName'] ?? '') ) {
                continue;
            }

            $signature = $this->signature($block);
            if ( '' === $signature ) {
                continue;
            }

            if ( ! isset($preferred[$signature]) ) {
                $preferred[$signature] = $index;
                continue;
            }

            $previousIndex = $preferred[$signature];
            $previousHidden = $this->startsHidden($blocks[$previousIndex], $sourceBaseHiddenStates);
            $currentHidden = $this->startsHidden($block, $sourceBaseHiddenStates);
            if ( $previousHidden === $currentHidden ) {
                continue;
            }

            if ( $previousHidden ) {
                $discarded[$previousIndex] = true;
                $preferred[$signature] = $index;
            } else {
                $discarded[$index] = true;
            }
        }

        return array_values(array_filter($blocks, static fn (mixed $block, int $index): bool => ! isset($discarded[$index]), ARRAY_FILTER_USE_BOTH));
    }

    /** @param array<string, mixed> $block @param array<int, bool> $sourceBaseHiddenStates */
    private function startsHidden(array $block, array $sourceBaseHiddenStates): bool
    {
        $provenanceId = $block['_source_provenance_id'] ?? null;
        return is_int($provenanceId) && true === ($sourceBaseHiddenStates[$provenanceId] ?? false);
    }

    /** @param array<string, mixed> $block @param array<int, array<string, mixed>> $sourceProvenance */
    private function isMobileDuplicate(array $block, array $sourceProvenance): bool
    {
        $provenanceId = $block['_source_provenance_id'] ?? null;
        $source = is_int($provenanceId) ? ( $sourceProvenance[$provenanceId] ?? array() ) : array();
        $attributes = is_array($source['source_attributes'] ?? null) ? $source['source_attributes'] : array();
        $context = is_array($source['context'] ?? null) ? $source['context'] : array();
        $classNames = is_array($context['class_names'] ?? null) ? implode(' ', $context['class_names']) : '';
        $ancestorClassNames = is_array($context['ancestor_class_names'] ?? null) ? implode(' ', $context['ancestor_class_names']) : '';

        $haystack = strtolower(trim(implode(' ', array(
            (string) ($attributes['class'] ?? ''),
            (string) ($attributes['id'] ?? ''),
            $classNames,
            $ancestorClassNames,
        ))));

        return (bool) preg_match('/(?:^|[^a-z0-9])(?:mobile|drawer|offcanvas|overlay|collapsed|hamburger|menu-panel|nav-panel)(?:[^a-z0-9]|$)/', $haystack);
    }

    /** @param array<string, mixed> $block @return array<string, mixed> */
    private function reconcileInnerContentChildPlaceholders(array $block): array
    {
        $innerBlocks = is_array($block['innerBlocks'] ?? null) ? array_values($block['innerBlocks']) : array();
        $innerContent = is_array($block['innerContent'] ?? null) ? array_values($block['innerContent']) : null;
        if ( null === $innerContent ) {
            return $block;
        }

        $placeholderCount = 0;
        $firstPlaceholderIndex = null;
        $lastPlaceholderIndex = null;
        foreach ( $innerContent as $index => $part ) {
            if ( null !== $part ) {
                continue;
            }

            ++$placeholderCount;
            $firstPlaceholderIndex ??= $index;
            $lastPlaceholderIndex = $index;
        }

        if ( count($innerBlocks) === $placeholderCount || null === $firstPlaceholderIndex || null === $lastPlaceholderIndex ) {
            return $block;
        }

        $opening = array_slice($innerContent, 0, $firstPlaceholderIndex);
        $closing = array_slice($innerContent, $lastPlaceholderIndex + 1);
        $block['innerBlocks'] = $innerBlocks;
        $block['innerContent'] = array_merge($opening, array_fill(0, count($innerBlocks), null), $closing);
        $block['innerHTML'] = implode('', array_map(static fn ($part): string => null === $part ? '' : (string) $part, array_merge($opening, $closing)));

        return $block;
    }

    /** @param array<string, mixed> $block */
    private function signature(array $block): string
    {
        $links = array();
        $this->collectLinks($block, $links);
        return implode('|', $links);
    }

    /** @param array<string, mixed> $block @param array<int, string> $links */
    private function collectLinks(array $block, array &$links): void
    {
        if ( in_array($block['blockName'] ?? '', array( 'core/navigation-link', 'core/navigation-submenu' ), true) ) {
            $attrs = is_array($block['attrs'] ?? null) ? $block['attrs'] : array();
            $links[] = ($this->normalizeLabel)((string) ($attrs['label'] ?? '')) . '>' . trim((string) ($attrs['url'] ?? ''));
        }

        foreach ( is_array($block['innerBlocks'] ?? null) ? $block['innerBlocks'] : array() as $innerBlock ) {
            if ( is_array($innerBlock) ) {
                $this->collectLinks($innerBlock, $links);
            }
        }
    }
}
