<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Elements;

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Classification\FormControlClassifier;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Generators\AuthoredNativeFormBlockGenerator;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\SourceBlockCreator;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Support\SourceDom;
use Closure;
use DOMElement;

/** Lowers static native GET forms without assigning them a provider contract. */
final class NativeGetFormBlockBuilder
{
    /** @param Closure(DOMElement, array<int, array<string, mixed>>&): array<int, array<string, mixed>> $convertChildren */
    /** @param Closure(DOMElement): array<string, mixed> $presentationAttributes */
    /** @param Closure(string, array<string, mixed>): void $registerGeneratedBlock */
    public function __construct(private readonly Closure $convertChildren, private readonly Closure $presentationAttributes, private readonly SourceBlockCreator $createBlock, private readonly Closure $registerGeneratedBlock)
    {
    }

    /** @param array<int, array<string, mixed>> $fallbacks @return array<string, mixed>|null */
    public function build(DOMElement $form, array &$fallbacks): ?array
    {
        if ( ! $this->isSafeNativeGetForm($form) ) {
            return null;
        }
        $children = ($this->convertChildren)($form, $fallbacks);
        if ( array() === $children ) {
            return null;
        }
        $generator = new AuthoredNativeFormBlockGenerator();
        ($this->registerGeneratedBlock)(AuthoredNativeFormBlockGenerator::class, $generator->definition());
        $attrs = array_filter(array_merge(($this->presentationAttributes)($form), array(
            'action' => SourceDom::attr($form, 'action'),
            'method' => 'get',
            'methodDeclared' => $form->hasAttribute('method'),
            'id' => SourceDom::attr($form, 'id'),
            'name' => SourceDom::attr($form, 'name'),
            'ariaLabel' => SourceDom::attr($form, 'aria-label'),
            'target' => SourceDom::attr($form, 'target'),
            'autocomplete' => SourceDom::attr($form, 'autocomplete'),
            'noValidate' => $form->hasAttribute('novalidate'),
        )), static fn (mixed $value): bool => is_bool($value) || '' !== $value);
        $markup = $generator->markup($attrs);
        return array( 'blockName' => AuthoredNativeFormBlockGenerator::NAME, 'attrs' => $attrs, 'innerBlocks' => $children, 'innerHTML' => $markup['opening'] . $markup['closing'], 'innerContent' => array_merge(array($markup['opening']), array_fill(0, count($children), null), array($markup['closing'])) );
    }

    private function isSafeNativeGetForm(DOMElement $form): bool
    {
        if ('' !== trim(SourceDom::attr($form, 'data-blocks-engine-runtime-form-owner'))) {
            return false;
        }
        $method = strtolower(trim(SourceDom::attr($form, 'method')));
        if ( '' !== $method && 'get' !== $method || 0 < $form->getElementsByTagName('script')->length ) {
            return false;
        }
        $action = trim(SourceDom::attr($form, 'action'));
        if ( 1 === preg_match('/^\s*(?:javascript|data):/i', $action) ) {
            return false;
        }
        foreach ( FormControlClassifier::controlElements($form) as $control ) {
            if ( ! in_array(strtolower($control->tagName), array( 'input', 'select', 'button' ), true)
                || ! FormControlClassifier::isReadableControl($control)
                || $control->hasAttribute('formaction')
                || $control->hasAttribute('formmethod')
                || ( 'button' === strtolower($control->tagName) && $this->hasAnchorAncestor($control, $form) )
            ) {
                return false;
            }
            foreach ($control->attributes as $attribute) {
                if ( str_starts_with(strtolower($attribute->nodeName), 'on') ) {
                    return false;
                }
            }
        }
        foreach ($form->attributes as $attribute) {
            if ( str_starts_with(strtolower($attribute->nodeName), 'on') ) {
                return false;
            }
        }
        return true;
    }

    private function hasAnchorAncestor(DOMElement $element, DOMElement $boundary): bool
    {
        for ( $ancestor = $element->parentNode; $ancestor instanceof DOMElement && $ancestor !== $boundary; $ancestor = $ancestor->parentNode ) {
            if ( 'a' === strtolower($ancestor->tagName) ) {
                return true;
            }
        }

        return false;
    }
}
