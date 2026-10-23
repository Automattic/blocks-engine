<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Elements;

use Automattic\BlocksEngine\PhpTransformer\Contract\CaptureEvidence;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Generators\CapturedChoiceGroupBlockGenerator;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Generators\AuthoredControlState;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Support\NativeControlState;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Session\HtmlTransformerSession;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\ElementPresentationResolver;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Support\SourceDom;
use Closure;
use DOMElement;
use DOMDocument;
use DOMXPath;
use LogicException;

/** Materializes captured choice evidence as an editable companion container. */
final class CapturedChoiceGroupConverter implements ElementConverter
{
    /** Mirrors the runtime's `choices()` query so source and rendered indexes agree. */
    private const CHOICE_XPATH = './/*[self::button or @role="radio" or @role="option" or @role="tab"'
        . ' or (self::input and (translate(@type, "RADIOCHEKBX", "radiochekbx")="radio" or translate(@type, "RADIOCHEKBX", "radiochekbx")="checkbox"))]';

    /**
     * Per-state source clones stay referenced for the transform so cache keys
     * derived from object ids cannot be reused by later source elements.
     *
     * @var list<DOMElement>
     */
    private array $stateClones = array();

    /**
     * @param Closure(DOMElement, array<int, array<string, mixed>>&): array<int, array<string, mixed>> $convertChildren
     * @param Closure(string, string): void $registerRule Registers one stylesheet rule under a class name.
     * @param Closure(): void $sourceMarkupMutated Invalidates selector caches after a temporary source swap.
     */
    public function __construct(
        private readonly HtmlTransformerSession $session,
        private readonly Closure $convertChildren,
        private readonly ?ElementPresentationResolver $presentation = null,
        private readonly ?Closure $registerRule = null,
        private readonly ?Closure $sourceMarkupMutated = null
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
            if (!is_string($state['html'] ?? null) || !str_contains($state['html'], CaptureEvidence::NATIVE_CONTROL_STATE)) continue;
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
                $control->removeAttribute(CaptureEvidence::NATIVE_CONTROL_STATE);
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
        // The conversion below resolves each choice's presentation from the
        // source DOM, which is one captured state. Whatever differs between
        // states must instead follow the runtime's selection.
        $statePresentation = $this->statePresentationClass($element, $decoded['states']);
        $attrs = array_filter(array(
            'tagName' => 'div' === $tagName ? '' : $tagName,
            'anchor' => trim(SourceDom::attr($element, 'id')),
            'className' => trim(trim(SourceDom::attr($element, 'class')) . ' ' . $statePresentation['className']),
            'ariaLabel' => trim(SourceDom::attr($element, 'aria-label')),
            'config' => $config,
        ), static fn(mixed $value): bool => '' !== $value);
        $children = ($this->convertChildren)($element, $fallbacks);
        if (array() !== $statePresentation['tokens']) {
            $children = $this->withoutStateClassTokens($children, $statePresentation['tokens']);
        }
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

    /**
     * Resolve every choice's presentation in every captured state and carry
     * the declarations that differ between states through state-scoped rules
     * keyed on the runtime's selection attributes. The baseline conversion
     * bakes the source state's values into the choice blocks (inline styles,
     * subject-class rules), which would otherwise outrank the class bindings
     * the runtime swaps in.
     *
     * @param array<int|string, mixed> $states
     * @return array{className: string, tokens: list<string>}
     */
    private function statePresentationClass(DOMElement $element, array $states): array
    {
        $none = array('className' => '', 'tokens' => array());
        $parent = $element->parentNode;
        if (null === $this->presentation || null === $this->registerRule || null === $parent) {
            return $none;
        }
        $choiceCount = count($this->choiceNodes($element));
        if (0 === $choiceCount) {
            return $none;
        }

        /** @var array<int, array<int, array<string, string>>> $declarations choice => selectedIndex => declarations */
        $declarations = array();
        /** @var array<int, array<int, list<string>>> $classTokens choice => selectedIndex => bound class tokens */
        $classTokens = array();
        foreach ($states as $state) {
            if (! is_array($state) || ! is_int($state['selectedIndex'] ?? null) || ! is_array($state['bindings'] ?? null)) {
                return $none;
            }
            $selected = $state['selectedIndex'];
            $clone = $element->cloneNode(true);
            if (! $clone instanceof DOMElement) {
                return $none;
            }
            $this->stateClones[] = $clone;
            $choices = $this->choiceNodes($clone);
            foreach ($state['bindings'] as $binding) {
                $choice = $choices[(int) ($binding['choiceIndex'] ?? -1)] ?? null;
                if (! $choice instanceof DOMElement || ! is_array($binding['nodes'] ?? null)) {
                    continue;
                }
                foreach ($binding['nodes'] as $nodeBinding) {
                    $node = $choice;
                    foreach ((array) ($nodeBinding['path'] ?? array()) as $childIndex) {
                        $children = array();
                        foreach ($node->childNodes as $child) {
                            if ($child instanceof DOMElement) {
                                $children[] = $child;
                            }
                        }
                        $node = $children[(int) $childIndex] ?? null;
                        if (! $node instanceof DOMElement) {
                            break;
                        }
                    }
                    if (! $node instanceof DOMElement) {
                        continue;
                    }
                    foreach ((array) ($nodeBinding['attributes'] ?? array()) as $name => $value) {
                        if (! is_string($name) || 1 !== preg_match('/^[a-z][a-z0-9_:-]*$/i', $name)) {
                            continue;
                        }
                        if (null === $value) {
                            $node->removeAttribute($name);
                        } else {
                            $node->setAttribute($name, (string) $value);
                        }
                    }
                }
            }
            $parent->replaceChild($clone, $element);
            try {
                foreach ($choices as $index => $choice) {
                    $declarations[$index][$selected] = $this->safeDeclarations($this->presentation->presentationDeclarations($choice));
                    $classTokens[$index][$selected] = SourceDom::boundedClassTokens($choice->getAttribute('class'), 64);
                }
            } finally {
                $parent->replaceChild($element, $clone);
                if (null !== $this->sourceMarkupMutated) {
                    ($this->sourceMarkupMutated)();
                }
            }
        }

        $stateTokens = array();
        foreach ($classTokens as $byState) {
            $all = array_unique(array_merge(...array_values($byState)));
            $shared = array_intersect(...array_values($byState));
            foreach (array_diff($all, $shared) as $token) {
                $stateTokens[$token] = true;
            }
        }

        $rules = array();
        foreach ($declarations as $index => $byState) {
            if (count($byState) < 2) {
                continue;
            }
            $properties = array();
            foreach ($byState as $values) {
                $properties += array_fill_keys(array_keys($values), true);
            }
            $varying = array();
            foreach (array_keys($properties) as $property) {
                $seen = array();
                foreach ($byState as $values) {
                    $seen[$values[$property] ?? ''] = true;
                }
                if (count($seen) > 1) {
                    $varying[] = $property;
                }
            }
            if (array() === $varying) {
                continue;
            }
            $body = static function (array $values) use ($varying): string {
                $parts = array();
                foreach ($varying as $property) {
                    // A property the state does not declare falls back to the
                    // cascade below author styles, not to another state's value.
                    $parts[] = $property . ':' . ($values[$property] ?? 'revert') . '!important';
                }
                return implode(';', $parts);
            };
            $active = $byState[$index] ?? null;
            $inactive = array();
            foreach ($byState as $selected => $values) {
                if ($selected !== $index) {
                    $inactive[$body($values)] = true;
                }
            }
            $choice = '[data-blocks-engine-choice-index="' . $index . '"]';
            if (null !== $active && 1 === count($inactive)) {
                // Selected vs. unselected is the whole difference: key it on
                // the runtime's per-choice activation flag.
                $rules[] = '%1$s ' . $choice . '[data-blocks-engine-choice-active="true"]{' . $body($active) . '}';
                $rules[] = '%1$s ' . $choice . '[data-blocks-engine-choice-active="false"]{' . $body(array_values(array_filter($byState, static fn (int $selected): bool => $selected !== $index, ARRAY_FILTER_USE_KEY))[0]) . '}';
                continue;
            }
            foreach ($byState as $selected => $values) {
                $rules[] = '%1$s[data-blocks-engine-choice-selected-index="' . $selected . '"] ' . $choice . '{' . $body($values) . '}';
            }
        }
        if (array() === $rules) {
            return array('className' => '', 'tokens' => array_keys($stateTokens));
        }
        $className = 'blocks-engine-choice-state-' . substr(md5(implode("\n", $rules)), 0, 10);
        ($this->registerRule)($className, implode("\n", array_map(static fn (string $rule): string => sprintf($rule, '.' . $className), $rules)));

        return array('className' => $className, 'tokens' => array_keys($stateTokens));
    }

    /** @return list<DOMElement> */
    private function choiceNodes(DOMElement $root): array
    {
        $document = $root->ownerDocument;
        if (! $document instanceof DOMDocument) {
            return array();
        }
        $nodes = array();
        foreach ((new DOMXPath($document))->query(self::CHOICE_XPATH, $root) ?: array() as $node) {
            if ($node instanceof DOMElement) {
                $nodes[] = $node;
            }
        }

        return $nodes;
    }

    /**
     * @param array<string, string> $declarations
     * @return array<string, string>
     */
    private function safeDeclarations(array $declarations): array
    {
        $safe = array();
        foreach ($declarations as $property => $value) {
            $property = strtolower(trim((string) $property));
            $value = trim(preg_replace('/\s*!important\s*$/i', '', (string) $value) ?? '');
            if (1 !== preg_match('/^-?[a-z][a-z-]*$/', $property) || '' === $value || 1 === preg_match('/[{};<>\\\\]|expression\(|@import/i', $value)) {
                continue;
            }
            $safe[$property] = $value;
        }

        return $safe;
    }

    /**
     * Class tokens the runtime binds per state belong to that state. Leaving
     * the source state's tokens on the static block wrappers would keep
     * painting that state after the selection moves.
     *
     * @param array<int, array<string, mixed>> $blocks
     * @param list<string> $tokens
     * @return array<int, array<string, mixed>>
     */
    private function withoutStateClassTokens(array $blocks, array $tokens): array
    {
        $strip = static function (string $classes) use ($tokens): string {
            $kept = array_filter(preg_split('/\s+/', trim($classes)) ?: array(), static fn (string $token): bool => '' !== $token && ! in_array($token, $tokens, true));
            return implode(' ', $kept);
        };
        $markup = static fn (string $html): string => (string) preg_replace_callback(
            '/(<[a-zA-Z][^<>]*?\sclass=")([^"]*)(")/',
            static fn (array $match): string => $match[1] . $strip($match[2]) . $match[3],
            $html
        );
        foreach ($blocks as &$block) {
            if (! is_array($block)) {
                continue;
            }
            if (is_string($block['attrs']['className'] ?? null)) {
                $className = $strip($block['attrs']['className']);
                if ('' === $className) {
                    unset($block['attrs']['className']);
                } else {
                    $block['attrs']['className'] = $className;
                }
            }
            if (is_string($block['innerHTML'] ?? null)) {
                $block['innerHTML'] = $markup($block['innerHTML']);
            }
            foreach ((array) ($block['innerContent'] ?? array()) as $key => $piece) {
                if (is_string($piece)) {
                    $block['innerContent'][$key] = $markup($piece);
                }
            }
            if (is_array($block['innerBlocks'] ?? null)) {
                $block['innerBlocks'] = $this->withoutStateClassTokens($block['innerBlocks'], $tokens);
            }
        }
        unset($block);

        return $blocks;
    }
}
