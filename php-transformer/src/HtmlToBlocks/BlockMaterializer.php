<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks;

use Automattic\BlocksEngine\PhpTransformer\Contract\CaptureEvidence;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Elements\RuntimeIslandAnalyzer;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Session\TransformationProvenanceState;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Support\SourceDom;
use Automattic\BlocksEngine\PhpTransformer\Support\EngineMarker;

/** Records source ownership and builds the final canonical block representation. */
final class BlockMaterializer
{
    /** Block metadata key holding source hooks a dynamic block renders for scripts. */
    public const RUNTIME_ATTRIBUTES_KEY = 'blocksEngineRuntimeAttributes';

    public function __construct(
        private readonly BlockFactory $blockFactory,
        private readonly RuntimeIslandAnalyzer $runtimeIslands
    ) {}

    private bool $selectedDocumentVisibility = false;

    public function configureDeviceVisibility(string $sourceHtml): void
    {
        $this->selectedDocumentVisibility = EngineMarker::usesSelectedDocumentVisibility($sourceHtml);
    }

    /**
     * @param array<string, mixed>              $attrs
     * @param array<int, array<string, mixed>> $innerBlocks
     * @return array<string, mixed>
     */
    public function materialize(
        string $name,
        array $attrs,
        array $innerBlocks,
        ?SourceBlockMaterializationFacts $sourceFacts,
        TransformationProvenanceState $provenance
    ): array {
        $provenanceId = null;
        $runtimeOwned = false;
        if ( $sourceFacts instanceof SourceBlockMaterializationFacts ) {
            $this->recordPresentationSignal($name, $attrs, $sourceFacts, $provenance);
            $this->recordStructureSignal($name, $sourceFacts, $provenance);
            $runtimeOwned = $this->runtimeIslands->recordBlockRuntimeDomContract($sourceFacts->sourceElement, $name);
            $runtimeAttributes = $this->runtimeIslands->runtimeAttributesCarriedBy($sourceFacts->sourceElement, $name);
            // Capture presentation hooks survive independently of author runtime
            // targeting. The render filter restores them on native block roots.
            foreach (array(CaptureEvidence::DEVICE_INLINE, CaptureEvidence::VIEWPORT_ENTRANCE, CaptureEvidence::RESPONSIVE_FRAGMENT) as $evidenceAttribute) {
                if ($sourceFacts->sourceElement->hasAttribute($evidenceAttribute)) {
                    $runtimeAttributes[$evidenceAttribute] = $sourceFacts->sourceElement->getAttribute($evidenceAttribute);
                }
            }
            if ( array() !== $runtimeAttributes ) {
                $attrs['metadata'][self::RUNTIME_ATTRIBUTES_KEY] = $runtimeAttributes;
            }
            if (str_starts_with($name, 'core/') && 'core/html' !== $name) {
                $viewports = EngineMarker::deviceViewportVisibility($sourceFacts->sourceElement, $this->selectedDocumentVisibility);
                if (array() !== $viewports) {
                    $attrs['metadata']['blockVisibility']['viewport'] = $viewports;
                }
                $deviceClasses = EngineMarker::deviceVisibilityClasses($sourceFacts->sourceElement, $this->selectedDocumentVisibility);
                if (array() !== $deviceClasses) {
                    $attrs['className'] = SourceDom::mergeClassNames((string) ($attrs['className'] ?? ''), implode(' ', $deviceClasses));
                }
            }
            $provenanceId = $provenance->registerSource($sourceFacts->sourceEntry, $sourceFacts->startsHidden, $sourceFacts->wrapperHiddenAtCollapsedViewport);
            $provenance->recordSourceElement($provenanceId, $sourceFacts->sourceElement);
        }

        $block = $this->blockFactory->create($name, $attrs, $innerBlocks);
        if ( null !== $provenanceId ) {
            $block['_source_provenance_id'] = $provenanceId;
        }
        if ( $runtimeOwned ) {
            $block['_editability_runtime_owned'] = true;
        }
        if ( null !== $provenanceId
            && $sourceFacts instanceof SourceBlockMaterializationFacts
            && array() !== $sourceFacts->visualTopologyEvidence
        ) {
            $block['_editability_visual_owned'] = true;
            $provenance->addSourceEvidence($provenanceId, array( 'visual_topology_evidence' => $sourceFacts->visualTopologyEvidence ));
        }

        return $block;
    }

    /** @param array<string, mixed> $attrs */
    private function recordPresentationSignal(
        string $blockName,
        array $attrs,
        SourceBlockMaterializationFacts $facts,
        TransformationProvenanceState $provenance
    ): void {
        $signals = array_intersect_key($attrs, array_flip(array( 'className', 'style', 'layout' )));
        $signals = array_filter($signals, static fn ($value): bool => is_array($value) ? array() !== $value : '' !== trim((string) $value));
        if ( array() === $signals ) {
            return;
        }

        $provenance->recordPresentationSignal(array(
            'block_name'        => $blockName,
            'tag'               => $facts->sourceTag,
            'selector'          => $facts->sourceSelector,
            'signals'           => $signals,
            'source_attributes' => array_intersect_key($facts->sourceAttributes, array_flip(array( 'class', 'style', 'data-layout', 'data-wp-layout' ))),
        ));
    }

    private function recordStructureSignal(
        string $blockName,
        SourceBlockMaterializationFacts $facts,
        TransformationProvenanceState $provenance
    ): void {
        if ( array() === $facts->structureSignals ) {
            return;
        }

        $provenance->recordStructureSignal(array(
            'block_name'        => $blockName,
            'tag'               => $facts->sourceTag,
            'selector'          => $facts->sourceSelector,
            'signals'           => $facts->structureSignals,
            'source_attributes' => array_intersect_key($facts->sourceAttributes, array_flip(array( 'class', 'id', 'role', 'style', 'data-layout', 'data-wp-layout' ))),
        ));
    }
}
