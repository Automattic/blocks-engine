<?php

declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Elements;

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Classification\FormControlClassifier;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Generators\AuthoredButtonBlockGenerator;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Session\HtmlTransformerSession;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Support\SourceDom;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Support\SvgMaterializer;
use DOMElement;

/**
 * Lowers an inert icon-only <button> that carries authored state
 * (aria-pressed, or role=checkbox with aria-checked) to the generated
 * authored-button block.
 */
final class AuthoredStateButtonLowering
{
    public function __construct(
        private readonly HtmlTransformerSession $session,
        private readonly SvgMaterializer $svgMaterializer
    ) {
    }

    /** @return array<string, mixed>|null */
    public function fromElement(DOMElement $element): ?array
    {
        if ( 'button' !== strtolower($element->tagName) ) {
            return null;
        }
        if ( array() !== SourceDom::eventMetadata($element) ) {
            return null;
        }
        foreach ( array( 'aria-controls', 'aria-expanded', 'command', 'commandfor', 'form', 'formaction', 'popovertarget', 'popovertargetaction', 'data-action', 'jsaction' ) as $name ) {
            if ( $element->hasAttribute($name) ) {
                return null;
            }
        }
        $svg = null;
        foreach ( $element->childNodes as $child ) {
            if ( $child instanceof \DOMText && '' !== trim($child->textContent) ) {
                return null;
            }
            if ( ! $child instanceof DOMElement ) {
                continue;
            }
            if ( 'svg' !== strtolower($child->tagName) || $svg instanceof DOMElement ) {
                return null;
            }
            $svg = $child;
        }
        if ( ! $svg instanceof DOMElement ) {
            return null;
        }
        $icon = $this->svgMaterializer->inlineSvgMarkup($svg);
        if ( '' === $icon || ! SourceDom::isSafeSvgContent($icon) || ! SourceDom::isSafeInlineSvgMarkup($icon) ) {
            return null;
        }
        $pressed = strtolower(trim(SourceDom::attr($element, 'aria-pressed')));
        if ( '' !== $pressed && ! in_array($pressed, array( 'true', 'false', 'mixed' ), true) ) {
            return null;
        }
        $role = SourceDom::role($element);
        $checked = strtolower(trim(SourceDom::attr($element, 'aria-checked')));
        if ('' !== $role && 'checkbox' !== $role) return null;
        if ('checkbox' === $role && (!in_array($checked, array('true', 'false'), true) || '' !== $pressed || FormControlClassifier::hasFormAncestor($element))) return null;
        $sourceAttributes = array();
        foreach ( $element->attributes ?? array() as $attribute ) {
            $name = strtolower($attribute->nodeName);
            if ( 1 === preg_match('/^data-(?!wp-)[a-z0-9_.:-]+$/', $name) ) {
                $sourceAttributes[] = array( 'name' => $name, 'value' => $attribute->nodeValue ?? '' );
            }
        }
        $generator = new AuthoredButtonBlockGenerator();
        $registry = $this->session->requireGeneratedBlockRegistry();
        $registry->register(AuthoredButtonBlockGenerator::class, $generator->definition($registry->namespace()));
        $type = FormControlClassifier::controlType($element);
        $attrs = array_filter(array(
            'type' => 'checkbox' === $role ? 'button' : (in_array($type, array( 'button', 'reset', 'submit' ), true) ? $type : 'submit'),
            'id' => SourceDom::attr($element, 'id'),
            'name' => SourceDom::attr($element, 'name'),
            'ariaLabel' => SourceDom::attr($element, 'aria-label'),
            'ariaPressed' => $pressed,
            'role' => $role,
            'ariaChecked' => 'checkbox' === $role ? $checked : '',
            'title' => SourceDom::attr($element, 'title'),
            'tabIndex' => SourceDom::attr($element, 'tabindex'),
            'className' => SourceDom::attr($element, 'class'),
            'style' => SourceDom::attr($element, 'style'),
            'iconSvg' => $icon,
            'sourceAttributes' => $sourceAttributes,
            'disabled' => $element->hasAttribute('disabled'),
        ), static fn (mixed $value): bool => is_array($value) ? array() !== $value : (is_bool($value) ? $value : '' !== $value));
        $markup = $generator->markup($attrs);

        return array(
            'blockName' => $registry->blockName(AuthoredButtonBlockGenerator::LOCAL_NAME),
            'attrs' => $attrs,
            'innerBlocks' => array(),
            'innerHTML' => $markup,
            'innerContent' => array( $markup ),
        );
    }
}
