<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Support;

use Automattic\BlocksEngine\PhpTransformer\WordPress\GeneratedGutenbergClassPolicy;
use DOMElement;

/**
 * Returns the engine's own saved block markup to the source shape it came from.
 *
 * Re-ingested transformer output is source like any other, but some save
 * shapes move source identity onto a wrapper the source never had: core/button
 * saves its className, anchor and geometry on the outer `.wp-block-button`
 * div, and core/buttons adds a `.wp-block-buttons` container. Read naively, the
 * wrapper looks like an authored container, so a second transform nests a new
 * group around the button and drops the class from the control. Inverting the
 * save shape first lets conversion re-derive exactly what the first transform
 * produced, which keeps transforming the engine's own output a fixed point.
 */
final class OwnSaveShapeInverter
{
    private const BUTTONS_CLASS = 'wp-block-buttons';
    private const BUTTON_CLASS = 'wp-block-button';
    private const BUTTON_LINK_CLASS = 'wp-block-button__link';
    private const ACCORDION_HEADING_CLASS = 'wp-block-accordion-heading';
    private const ACCORDION_TOGGLE_CLASS = 'wp-block-accordion-heading__toggle';
    private const ACCORDION_TOGGLE_TITLE_CLASS = 'wp-block-accordion-heading__toggle-title';
    private const ACCORDION_TOGGLE_ICON_CLASS = 'wp-block-accordion-heading__toggle-icon';

    public static function invert(DOMElement $root): void
    {
        self::invertAccordionHeadings($root);
        $containers = array();
        foreach ( $root->getElementsByTagName('div') as $element ) {
            if ( SourceDom::hasClass($element, self::BUTTONS_CLASS) ) {
                $containers[] = $element;
            }
        }
        foreach ( $containers as $container ) {
            $controls = self::buttonControls($container);
            if ( null === $controls ) {
                continue;
            }
            foreach ( $controls as $control ) {
                self::liftButtonWrapper($control);
            }
            self::releaseButtonsContainer($container);
        }
    }

    /**
     * core/accordion-heading saves its title inside a generated
     * `__toggle-title` span beside a generated `+` icon span. Read as source,
     * the title span is authored label content and the heading converter
     * wraps it in a second title span. The toggle's own children are the
     * source label, so the generated spans are removed before conversion.
     */
    private static function invertAccordionHeadings(DOMElement $root): void
    {
        $toggles = array();
        foreach ( $root->getElementsByTagName('button') as $button ) {
            $heading = $button->parentNode;
            if ( SourceDom::hasClass($button, self::ACCORDION_TOGGLE_CLASS)
                && $heading instanceof DOMElement
                && SourceDom::hasClass($heading, self::ACCORDION_HEADING_CLASS)
            ) {
                $toggles[] = $button;
            }
        }
        foreach ( $toggles as $toggle ) {
            foreach ( iterator_to_array($toggle->childNodes) as $child ) {
                if ( ! $child instanceof DOMElement || 'span' !== strtolower($child->tagName) ) {
                    continue;
                }
                if ( SourceDom::hasClass($child, self::ACCORDION_TOGGLE_ICON_CLASS) ) {
                    $toggle->removeChild($child);
                } elseif ( SourceDom::hasClass($child, self::ACCORDION_TOGGLE_TITLE_CLASS) ) {
                    while ( null !== $child->firstChild ) {
                        $toggle->insertBefore($child->firstChild, $child);
                    }
                    $toggle->removeChild($child);
                }
            }
        }
    }

    /**
     * The controls of a container that holds nothing but core/button save
     * shapes, or null when anything else lives there.
     *
     * @return list<DOMElement>|null
     */
    private static function buttonControls(DOMElement $container): ?array
    {
        $controls = array();
        foreach ( $container->childNodes as $child ) {
            if ( $child instanceof \DOMText && '' === trim($child->textContent) ) {
                continue;
            }
            if ( $child instanceof \DOMComment ) {
                continue;
            }
            if ( ! $child instanceof DOMElement || 'div' !== strtolower($child->tagName) || ! SourceDom::hasClass($child, self::BUTTON_CLASS) ) {
                return null;
            }
            $control = self::onlyElementChild($child);
            if ( null === $control
                || ! in_array(strtolower($control->tagName), array( 'a', 'button' ), true)
                || ! SourceDom::hasClass($control, self::BUTTON_LINK_CLASS)
            ) {
                return null;
            }
            $controls[] = $control;
        }
        return array() === $controls ? null : $controls;
    }

    /** Moves the wrapper's block identity back onto the control and drops the wrapper. */
    private static function liftButtonWrapper(DOMElement $control): void
    {
        $wrapper = $control->parentNode;
        if ( ! $wrapper instanceof DOMElement ) {
            return;
        }
        $classes = array_values(array_filter(
            SourceDom::classNames($wrapper),
            static fn (string $class): bool => self::BUTTON_CLASS !== $class
        ));
        $controlClasses = array_values(array_filter(
            SourceDom::classNames($control),
            static fn (string $class): bool => ! in_array($class, array( self::BUTTON_LINK_CLASS, 'wp-element-button' ), true)
        ));
        $merged = SourceDom::mergeClassNames(implode(' ', $classes), implode(' ', $controlClasses));
        if ( '' !== $merged ) {
            $control->setAttribute('class', $merged);
        } else {
            $control->removeAttribute('class');
        }
        $id = SourceDom::attr($wrapper, 'id');
        if ( '' !== $id && '' === SourceDom::attr($control, 'id') ) {
            $control->setAttribute('id', $id);
        }
        $style = trim(trim(SourceDom::attr($wrapper, 'style'), ';') . ';' . trim(SourceDom::attr($control, 'style'), ';'), ';');
        if ( '' !== $style ) {
            $control->setAttribute('style', $style);
        }
        $wrapper->parentNode?->replaceChild($control, $wrapper);
    }

    /**
     * A container that carries only generated layout classes was synthesized
     * by the save shape and is unwrapped; one that also carries source identity
     * stays as that source container.
     */
    private static function releaseButtonsContainer(DOMElement $container): void
    {
        $authored = array_values(array_filter(
            SourceDom::classNames($container),
            static fn (string $class): bool => ! GeneratedGutenbergClassPolicy::isGeneratedClassName($class)
        ));
        if ( array() !== $authored || '' !== SourceDom::attr($container, 'id') || '' !== trim(SourceDom::attr($container, 'style')) ) {
            $container->setAttribute('class', implode(' ', $authored));
            if ( array() === $authored ) {
                $container->removeAttribute('class');
            }
            return;
        }
        $parent = $container->parentNode;
        if ( null === $parent ) {
            return;
        }
        while ( null !== $container->firstChild ) {
            $parent->insertBefore($container->firstChild, $container);
        }
        $parent->removeChild($container);
    }

    private static function onlyElementChild(DOMElement $element): ?DOMElement
    {
        $only = null;
        foreach ( $element->childNodes as $child ) {
            if ( $child instanceof \DOMText && '' === trim($child->textContent) ) {
                continue;
            }
            if ( ! $child instanceof DOMElement || null !== $only ) {
                return null;
            }
            $only = $child;
        }
        return $only;
    }
}
