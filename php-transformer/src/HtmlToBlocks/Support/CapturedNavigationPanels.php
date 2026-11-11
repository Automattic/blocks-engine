<?php

declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Support;

use Automattic\BlocksEngine\PhpTransformer\Contract\CaptureEvidence;
use DOMElement;

/**
 * Undoes capture-time navigation panel projection on the source body before
 * classification.
 */
final class CapturedNavigationPanels
{
    /**
     * Capture wraps Core's shared desktop/mobile menu in a hidden dialog.
     * Restore the native tree before classification: core/navigation replaces
     * both controls and supplies its own accessible overlay at render time.
     * Other captured dialogs retain their existing runtime contract.
     */
    public static function restoreNative(DOMElement $body): void
    {
        foreach ( iterator_to_array($body->getElementsByTagName('nav')) as $nav ) {
            if ( ! SourceDom::hasClass($nav, 'wp-block-navigation') ) {
                continue;
            }
            foreach ( iterator_to_array($nav->childNodes) as $panel ) {
                if ( ! $panel instanceof DOMElement || ! $panel->hasAttribute(CaptureEvidence::EXISTING_PANEL) ) {
                    continue;
                }
                $id = $panel->getAttribute(CaptureEvidence::DIALOG_PANEL);
                // Include expansion and capture projection can leave either the
                // wrapper around Core's container or the container itself. The
                // latter already owns the native tree; do not unwrap its close
                // element as though it were another captured dialog wrapper.
                $snapshot = json_decode($panel->getAttribute(CaptureEvidence::EXISTING_PANEL), true);
                $restingClass = is_array($snapshot) ? ($snapshot['resting']['class'] ?? '') : '';
                $projectedWrapper = $panel->hasAttribute(CaptureEvidence::RESTING_PANEL)
                    && SourceDom::hasClass($panel, 'wp-block-navigation__responsive-container')
                    && is_string($restingClass)
                    && in_array('wp-block-navigation__responsive-container', SourceDom::tokens($restingClass), true);
                $native = $projectedWrapper ? $panel : $panel->firstElementChild;
                if ( '' === $id || ! $native instanceof DOMElement
                    || ($native !== $panel && 1 !== $panel->childElementCount)
                    || ! SourceDom::hasClass($native, 'wp-block-navigation__responsive-container') ) {
                    continue;
                }
                $opener = null;
                foreach ( $nav->childNodes as $control ) {
                    if ( $control instanceof DOMElement
                        && SourceDom::hasClass($control, 'wp-block-navigation__responsive-container-open')
                        && $id === $control->getAttribute(CaptureEvidence::DIALOG_TRIGGER) ) {
                        $opener = $control;
                    }
                }
                if ( ! $opener instanceof DOMElement ) {
                    continue;
                }
                foreach ( array(CaptureEvidence::DIALOG_TRIGGER, CaptureEvidence::DIALOG_PANEL_STATE, 'aria-controls', 'aria-expanded') as $attribute ) {
                    $opener->removeAttribute($attribute);
                }
                if ( $native === $panel ) {
                    foreach ( array(CaptureEvidence::EXISTING_PANEL, CaptureEvidence::DIALOG_PANEL, 'hidden') as $attribute ) {
                        $native->removeAttribute($attribute);
                    }
                } else {
                    $nav->replaceChild($native, $panel);
                }
                foreach ( iterator_to_array($nav->childNodes) as $control ) {
                    if ( $control instanceof DOMElement && $id === $control->getAttribute(CaptureEvidence::DIALOG_CLOSE) ) {
                        $nav->removeChild($control);
                    }
                }
            }
        }
    }

    public static function restoreRestingStates(DOMElement $body): void
    {
        foreach ($body->getElementsByTagName('*') as $element) {
            if (
                !$element instanceof DOMElement
                || 'nav' !== strtolower($element->tagName)
                || !SourceDom::hasClass($element, CaptureEvidence::DROPDOWN_CLASS)
                || !$element->hasAttribute(CaptureEvidence::EXISTING_PANEL)
                || !SourceDom::hasAncestorTag($element, array('nav'))
            ) {
                continue;
            }

            $snapshot = json_decode($element->getAttribute(CaptureEvidence::EXISTING_PANEL), true);
            $resting = is_array($snapshot) ? ($snapshot['resting'] ?? null) : null;
            if (!is_array($resting)) {
                continue;
            }

            foreach (array('style', 'class', 'hidden') as $attribute) {
                if (!array_key_exists($attribute, $resting)) {
                    continue;
                }
                $value = $resting[$attribute];
                if ('hidden' === $attribute) {
                    if (true === $value) $element->setAttribute('hidden', '');
                    elseif (false === $value) $element->removeAttribute('hidden');
                    continue;
                }
                if (null === $value) $element->removeAttribute($attribute);
                elseif (is_string($value)) $element->setAttribute($attribute, $value);
            }

            $element->removeAttribute(CaptureEvidence::EXISTING_PANEL);
        }
    }
}
