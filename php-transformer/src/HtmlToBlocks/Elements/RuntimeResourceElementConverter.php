<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Elements;

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Session\HtmlTransformerSession;
use Closure;
use DOMElement;

/** Owns canvas, script, and template conversion and runtime evidence. */
final class RuntimeResourceElementConverter implements ElementConverter
{
    /**
     * @param Closure(DOMElement): array<string, mixed> $htmlPreservationBlock
     * @param Closure(string, array<string, mixed>, array<int, array<string, mixed>>, DOMElement): array<string, mixed> $createBlock
     */
    public function __construct(
        private readonly HtmlTransformerSession $session,
        private readonly Closure $htmlPreservationBlock,
        private readonly Closure $createBlock
    ) {
    }

    /** @param array<int, array<string, mixed>> $fallbacks */
    public function convert(DOMElement $element, string $tagName, array &$fallbacks): ConversionOutcome
    {
        return match ($tagName) {
            'canvas'   => ConversionOutcome::handled($this->convertCanvas($element)),
            'script'   => ConversionOutcome::handled($this->convertScript($element, $fallbacks)),
            'template' => ConversionOutcome::handled($this->convertTemplate($element, $fallbacks)),
            default    => ConversionOutcome::unhandled(),
        };
    }

    /** @return array<string, mixed>|null */
    private function convertCanvas(DOMElement $element): ?array
    {
        $session = $this->session;
        $emitter = $session->fallbackEmitter();
        if ( ! $emitter->isRuntimeCanvasTarget($element) ) {
            return null;
        }

        $emitter->recordRuntimeIsland(
            $element,
            'canvas',
            'canvas_requires_runtime',
            'canvas_element_and_client_script_execution',
            array(
                'script_dependency_hint' => 'Scripts may target this canvas and call canvas APIs such as getContext(); preserving the native element keeps the runtime addressable.',
                'required_scripts'        => $emitter->requiredScriptsForElement($element),
            ),
            $session->runtimeDomState()
        );

        return ($this->htmlPreservationBlock)($element);
    }

    /** @param array<int, array<string, mixed>> $fallbacks @return array<string, mixed>|null */
    private function convertScript(DOMElement $element, array &$fallbacks): ?array
    {
        $session = $this->session;
        $emitter = $session->fallbackEmitter();
        $metadata = $emitter->staticScriptMetadata($element);
        if ( null === $metadata ) {
            $emitter->captureScriptFallback($element, $fallbacks, $session->runtimeDomState());
            return null;
        }

        $session->runtimeBehaviorState()->recordScriptMetadata($metadata);
        if ( ! $this->isAddressableStaticJsonTarget($element, $metadata, $session) ) {
            return null;
        }

        $emitter->recordRuntimeIsland(
            $element,
            'static_script',
            'static_script_runtime_target',
            'client_script_configuration',
            array(
                'script_role'      => 'data',
                'required_scripts' => $emitter->requiredScriptsForElement($element),
            ),
            $session->runtimeDomState()
        );

        return $this->staticJsonTargetBlock($element, $metadata);
    }

    /** @param array<int, array<string, mixed>> $fallbacks */
    private function convertTemplate(DOMElement $element, array &$fallbacks): null
    {
        $session = $this->session;
        $session->fallbackEmitter()->captureTemplateFallback($element, $fallbacks, $session->runtimeDomState());

        return null;
    }

    /** @param array<string, mixed> $metadata */
    private function isAddressableStaticJsonTarget(DOMElement $element, array $metadata, HtmlTransformerSession $session): bool
    {
        $id = trim($element->getAttribute('id'));
        $type = strtolower(trim($element->getAttribute('type')));
        if ( '' === $id || ! in_array($type, array( 'application/json', 'application/ld+json' ), true) || ! $session->runtimeSelectorState()->hasDom('#' . $id) ) {
            return false;
        }

        if ( ! empty($metadata['body_truncated']) ) {
            return false;
        }

        return null !== json_decode((string) ($metadata['body'] ?? ''), true);
    }

    /** @param array<string, mixed> $metadata @return array<string, mixed> */
    private function staticJsonTargetBlock(DOMElement $element, array $metadata): array
    {
        $attributes = is_array($metadata['attributes'] ?? null) ? $metadata['attributes'] : array();
        ksort($attributes, SORT_STRING);
        $attributeHtml = '';
        foreach ( $attributes as $name => $value ) {
            if ( ! is_string($name) || ! is_string($value) ) {
                continue;
            }
            $attributeHtml .= ' ' . $name . '="' . htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '"';
        }
        $body = str_replace('</script', '<\/script', (string) ($metadata['body'] ?? ''));

        return ($this->createBlock)(
            'core/html',
            array( 'content' => '<script' . $attributeHtml . '>' . $body . '</script>' ),
            array(),
            $element
        );
    }
}
