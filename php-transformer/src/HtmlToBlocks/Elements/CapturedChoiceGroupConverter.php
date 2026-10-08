<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Elements;

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Generators\CapturedChoiceGroupBlockGenerator;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Generators\AuthoredControlState;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Support\NativeControlState;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Session\HtmlTransformerSession;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Support\SourceDom;
use Closure;
use DOMElement;
use DOMDocument;
use LogicException;

/** Materializes captured choice evidence as an editable companion container. */
final class CapturedChoiceGroupConverter implements ElementConverter
{
    /**
     * @param Closure(DOMElement, array<int, array<string, mixed>>&): array<int, array<string, mixed>> $convertChildren
     */
    public function __construct(
        private readonly HtmlTransformerSession $session,
        private readonly Closure $convertChildren
    ) {
    }

    /** @param array<int, array<string, mixed>> $fallbacks */
    public function convert(DOMElement $element, string $tagName, array &$fallbacks): ConversionOutcome
    {
        if ('true' !== SourceDom::attr($element, 'data-blocks-engine-choice-group')) {
            return ConversionOutcome::unhandled();
        }

        return ConversionOutcome::handled($this->block($element, $fallbacks));
    }

    /** @param array<int, array<string, mixed>> $fallbacks @return array<string, mixed> */
    private function block(DOMElement $element, array &$fallbacks): array
    {
        $registry = $this->session->generatedBlockRegistry()
            ?? throw new LogicException('Generated block registry has not been prepared for this transform.');
        $blockName = $registry->blockName(CapturedChoiceGroupBlockGenerator::LOCAL_NAME);
        $registry->register(CapturedChoiceGroupBlockGenerator::class, (new CapturedChoiceGroupBlockGenerator())->definition($blockName));

        $config = trim(SourceDom::attr($element, 'data-blocks-engine-choice-config'));
        $decoded = json_decode($config, true);
        if (! is_array($decoded) || ! is_array($decoded['states'] ?? null) || array() === $decoded['states']) {
            return array();
        }
        // Transition HTML uses the same native-property codec as the editable
        // baseline children. A state replacement must not fall back to attributes.
        $typed = false;
        foreach ($decoded['states'] as &$state) {
            if (!is_string($state['html'] ?? null) || !str_contains($state['html'], NativeControlState::ATTRIBUTE)) continue;
            $typed = true;
            $document = new DOMDocument();
            $previous = libxml_use_internal_errors(true);
            $document->loadHTML('<?xml encoding="UTF-8"><div data-control-state-fragment="true">' . $state['html'] . '</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD | LIBXML_NONET);
            libxml_clear_errors(); libxml_use_internal_errors($previous);
            $root = $document->getElementsByTagName('div')->item(0);
            if (!$root instanceof DOMElement) continue;
            foreach ($root->getElementsByTagName('*') as $control) {
                $attributes = NativeControlState::attributes($control);
                if (null === $attributes) continue;
                $kind = strtolower($control->tagName);
                $payload = AuthoredControlState::state($kind, array_merge(array('type' => $control->getAttribute('type')), $attributes));
                if (null !== $payload) $control->setAttribute(AuthoredControlState::ATTRIBUTE, json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
                $control->removeAttribute(NativeControlState::ATTRIBUTE);
            }
            $state['html'] = SourceDom::innerHtml($root);
        }
        unset($state);
        // Untyped configs keep their exact bytes; decoding would turn `{}` into `[]`.
        if ($typed) $config = json_encode($decoded, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $tagName = strtolower(trim($element->tagName));
        if (1 !== preg_match('/^[a-z][a-z0-9-]*$/', $tagName)) {
            $tagName = 'div';
        }
        $attrs = array_filter(array(
            'tagName' => 'div' === $tagName ? '' : $tagName,
            'anchor' => trim(SourceDom::attr($element, 'id')),
            'className' => trim(SourceDom::attr($element, 'class')),
            'ariaLabel' => trim(SourceDom::attr($element, 'aria-label')),
            'config' => $config,
        ), static fn(mixed $value): bool => '' !== $value);
        $children = ($this->convertChildren)($element, $fallbacks);
        $escape = static fn(string $value): string => htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $opening = '<' . $tagName;
        if ('' !== ($attrs['anchor'] ?? '')) $opening .= ' id="' . $escape((string) $attrs['anchor']) . '"';
        if ('' !== ($attrs['className'] ?? '')) $opening .= ' class="' . $escape((string) $attrs['className']) . '"';
        if ('' !== ($attrs['ariaLabel'] ?? '')) $opening .= ' aria-label="' . $escape((string) $attrs['ariaLabel']) . '"';
        $opening .= ' data-blocks-engine-choice-group="true" data-blocks-engine-choice-config="' . $escape($config) . '">';
        $innerContent = array($opening);
        foreach ($children as $_) $innerContent[] = null;
        $innerContent[] = '</' . $tagName . '>';

        return array(
            'blockName' => $blockName,
            'attrs' => $attrs,
            'innerBlocks' => $children,
            'innerHTML' => $opening . '</' . $tagName . '>',
            'innerContent' => $innerContent,
        );
    }
}
