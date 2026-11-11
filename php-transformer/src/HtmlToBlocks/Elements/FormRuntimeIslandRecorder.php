<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Elements;

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Support\SourceDom;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Session\HtmlTransformerSession;
use Closure;
use DOMElement;

/** Records the runtime contracts required by forms and standalone controls. */
final class FormRuntimeIslandRecorder
{
    /**
     * @param Closure(DOMElement, string, string, string, array<string, mixed>): void $recordRuntimeIsland
     */
    public function __construct(
        private readonly FormControlMetadataBuilder $metadataBuilder,
        private readonly Closure $recordRuntimeIsland,
        private readonly HtmlTransformerSession $session
    ) {
    }

    /** @param array<string, mixed>|null $readableFormBlock */
    public function recordForm(DOMElement $element, ?array $readableFormBlock): void
    {
        $controls = $this->metadataBuilder->controls($element);
        ($this->recordRuntimeIsland)($element, 'form', 'form_requires_runtime', 'server_or_client_form_handler', array(
            'form'             => $this->metadataBuilder->form($element),
            'form_intent'      => FormRuntimeRequirementAnalyzer::formIntent($element),
            'controls'         => $controls,
            'control_count'    => count($controls),
            'events'           => SourceDom::eventMetadata($element),
            'readable_blocks'  => null !== $readableFormBlock ? array( $readableFormBlock ) : array(),
            'required_scripts' => $this->session->fallbackEmitter()->requiredScriptsForElement($element),
        ));
    }

    public function recordControl(DOMElement $element): void
    {
        ($this->recordRuntimeIsland)($element, 'control', 'runtime_dom_target', 'client_script_execution', $this->controlMetadata($element));
    }

    /**
     * Preserve a standalone control whose behavior depends on a client runtime
     * rather than emitting an unsupported-element loss.
     */
    public function preserveStandaloneControl(DOMElement $element): bool
    {
        if ( ! in_array(strtolower($element->tagName), array( 'input', 'select', 'textarea' ), true) ) {
            return false;
        }

        ($this->recordRuntimeIsland)($element, 'control', 'form_control_requires_runtime', 'client_form_control_runtime', $this->controlMetadata($element));
        return true;
    }

    /** @return array<string, mixed> */
    private function controlMetadata(DOMElement $element): array
    {
        return array(
            'control'          => $this->metadataBuilder->control($element),
            'events'           => SourceDom::eventMetadata($element),
            'required_scripts' => $this->session->fallbackEmitter()->requiredScriptsForElement($element),
        );
    }
}
