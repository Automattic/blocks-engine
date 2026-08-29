<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Session;

final class TransformationEvidenceState
{
    /** @var list<string> */
    private array $formControlEchoTexts = array();

    /** @var list<array<string, mixed>> */
    private array $frozenHiddenStateFindings = array();

    /** @var list<array<string, mixed>> */
    private array $droppedLinkWrapperFindings = array();

    /** @var list<array<string, mixed>> */
    private array $gutenbergIncompatibilities = array();

    /** @var array<string, array{selector: string, min_width: string}> */
    private array $responsiveGeometryAmbiguities = array();

    /** @var array<string, array{selector: string, height: string}> */
    private array $responsiveHeightAmbiguities = array();

    /** @var list<array{selector: string, direct_child_count: int, block_child_count: int, source_tags: list<string>, block_tags: list<string>}> */
    private array $authorLayoutTopologies = array();

    /** @var list<array<string, mixed>> */
    private array $responsiveImageFallbacks = array();

    /** @var array<string, true> */
    private array $responsiveImageFallbackSelectors = array();

    public function recordFormControlEcho(string $text): void
    {
        $text = trim($text);
        if ( '' !== $text ) {
            $this->formControlEchoTexts[] = $text;
        }
    }

    /** @return list<string> */
    public function formControlEchoTexts(): array
    {
        return $this->formControlEchoTexts;
    }

    /** @param array<string, mixed> $finding */
    public function recordFrozenHiddenState(array $finding): void
    {
        $this->frozenHiddenStateFindings[] = $finding;
    }

    /** @return list<array<string, mixed>> */
    public function frozenHiddenStateFindings(): array
    {
        return $this->frozenHiddenStateFindings;
    }

    /** @param array<string, mixed> $finding */
    public function recordDroppedLinkWrapper(array $finding): void
    {
        $this->droppedLinkWrapperFindings[] = $finding;
    }

    /** @return list<array<string, mixed>> */
    public function droppedLinkWrapperFindings(): array
    {
        return $this->droppedLinkWrapperFindings;
    }

    /** @param array<string, mixed> $finding */
    public function recordGutenbergIncompatibility(array $finding): void
    {
        $this->gutenbergIncompatibilities[] = $finding;
    }

    /** @return list<array<string, mixed>> */
    public function gutenbergIncompatibilities(): array
    {
        return $this->gutenbergIncompatibilities;
    }

    public function recordResponsiveGeometryAmbiguity(string $selector, string $minimumWidth): void
    {
        $this->responsiveGeometryAmbiguities[$selector . "\0" . $minimumWidth] = array(
            'selector' => $selector,
            'min_width' => $minimumWidth,
        );
    }

    /** @return list<array{selector: string, min_width: string}> */
    public function responsiveGeometryAmbiguities(): array
    {
        return array_values($this->responsiveGeometryAmbiguities);
    }

    public function recordResponsiveHeightAmbiguity(string $selector, string $height): void
    {
        $this->responsiveHeightAmbiguities[$selector . "\0" . $height] = array(
            'selector' => $selector,
            'height' => $height,
        );
    }

    /** @return list<array{selector: string, height: string}> */
    public function responsiveHeightAmbiguities(): array
    {
        return array_values($this->responsiveHeightAmbiguities);
    }

    /**
     * @param list<string> $sourceTags
     * @param list<string> $blockTags
     */
    public function recordAuthorLayoutTopology(string $selector, int $sourceChildCount, int $blockChildCount, array $sourceTags, array $blockTags): void
    {
        $this->authorLayoutTopologies[] = array(
            'selector' => $selector,
            'direct_child_count' => $sourceChildCount,
            'block_child_count' => $blockChildCount,
            'source_tags' => $sourceTags,
            'block_tags' => $blockTags,
        );
    }

    /** @return list<array{selector: string, source_child_count: int, block_child_count: int, source_tags: list<string>, block_tags: list<string>}> */
    public function authorLayoutTopologyFindings(): array
    {
        $findings = array();
        foreach ( $this->authorLayoutTopologies as $layout ) {
            if ( 0 === $layout['direct_child_count'] ) {
                continue;
            }
            if ( $layout['direct_child_count'] === $layout['block_child_count'] && $layout['source_tags'] === $layout['block_tags'] ) {
                continue;
            }
            $findings[] = array(
                'selector' => $layout['selector'],
                'source_child_count' => $layout['direct_child_count'],
                'block_child_count' => $layout['block_child_count'],
                'source_tags' => $layout['source_tags'],
                'block_tags' => $layout['block_tags'],
            );
        }

        return array_slice($findings, 0, 20);
    }

    /** @param array<string, mixed> $fallback */
    public function recordResponsiveImageFallback(string $selector, array $fallback): void
    {
        if ( isset($this->responsiveImageFallbackSelectors[$selector]) ) {
            return;
        }

        $this->responsiveImageFallbackSelectors[$selector] = true;
        $this->responsiveImageFallbacks[] = $fallback;
    }

    public function hasResponsiveImageFallback(string $selector): bool
    {
        return isset($this->responsiveImageFallbackSelectors[$selector]);
    }

    /** @return list<array<string, mixed>> */
    public function responsiveImageFallbacks(): array
    {
        return $this->responsiveImageFallbacks;
    }
}
