<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Elements;

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Classification\FormControlClassifier;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Support\NativeControlState;
use DOMElement;

/** Finds sibling control partitions with a single currently rendered panel. */
final class FormWorkflowPanelDetector
{
    /** @param list<DOMElement> $controls
     * @return list<DOMElement>
     */
    public function controls(DOMElement $form, array $controls): array
    {
        $workflow = array();
        foreach ( $this->panels($form, $controls) as $panel ) {
            foreach ( $panel['controls'] as $control ) $workflow[(string) $control->getNodePath()] = $control;
        }
        return array_values($workflow);
    }

    /** @param list<DOMElement> $controls
     * @return list<array{element: DOMElement, controls: list<DOMElement>, hidden: bool}>
     */
    public function panels(DOMElement $form, array $controls): array
    {
        $workflow = array();
        foreach ( array($form, ...iterator_to_array($form->getElementsByTagName('*'), false)) as $parent ) {
            $groups = array();
            foreach ( $parent->childNodes as $panel ) {
                if ( ! $panel instanceof DOMElement || FormControlClassifier::isControlElement($panel) ) continue;
                // Step naming supports custom containers, but never replaces the
                // sibling partition and visibility evidence required below.
                $named = str_contains(strtolower($panel->getAttribute('class')), 'step');
                foreach ( $panel->attributes as $attribute ) {
                    $named = $named || (str_starts_with($attribute->nodeName, 'data-') && str_contains(strtolower($attribute->nodeName), 'step'));
                }
                if ( ! $named && ! in_array(strtolower($panel->tagName), array('section', 'div', 'fieldset', 'article'), true) ) continue;
                $members = array_values(array_filter($controls, static function (DOMElement $control) use ($panel): bool {
                    for ( $ancestor = $control->parentNode; $ancestor instanceof DOMElement; $ancestor = $ancestor->parentNode ) {
                        if ( $ancestor === $panel ) return true;
                    }
                    return false;
                }));
                if ( array() === $members ) continue;
                $hidden = $panel->hasAttribute('hidden');
                if ( ! $hidden ) {
                    // Buttons have no native capture state and cannot establish
                    // that an otherwise unrendered panel is visible.
                    $fields = array_filter($members, static fn (DOMElement $control): bool => ! in_array(FormControlClassifier::controlType($control), array('button', 'submit', 'reset', 'image'), true));
                    $hidden = array() !== $fields && array() === array_filter($fields, static fn (DOMElement $control): bool => false !== (NativeControlState::payload($control)['rendered'] ?? null));
                }
                // Like sibling containers form the partition; an unrelated
                // hidden div beside section panels is not another step.
                $key = strtolower($panel->tagName) . ($named ? ':step' : ':plain');
                $groups[$key][] = array('element' => $panel, 'controls' => $members, 'hidden' => $hidden);
            }
            foreach ( $groups as $panels ) {
                if ( count($panels) < 2 || 1 !== count(array_filter($panels, static fn (array $panel): bool => ! $panel['hidden'])) ) continue;
                // Conditional field wrappers are not a workflow when ordinary
                // fields remain outside the proposed sibling partition.
                $members = array_merge(...array_column($panels, 'controls'));
                if ( array_filter($controls, static fn (DOMElement $control): bool => ! in_array($control, $members, true) && false !== (NativeControlState::payload($control)['rendered'] ?? null) && ! in_array(FormControlClassifier::controlType($control), array('button', 'submit', 'reset', 'image'), true)) ) continue;
                foreach ( $panels as $panel ) {
                    $workflow[(string) $panel['element']->getNodePath()] = $panel;
                }
            }
        }
        return array_values($workflow);
    }
}
