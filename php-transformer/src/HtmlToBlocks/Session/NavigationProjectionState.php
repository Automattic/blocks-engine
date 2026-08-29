<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Session;

use DOMElement;

/**
 * Per-transform navigation projection relationships.
 *
 * Records which source control projects which navigation, which source nodes
 * the projection suppresses, and which controls are implicit dialog triggers.
 *
 * Keys are `DOMElement::getNodePath()` values such as
 * `/html/body/div[1]/button[1]`, which are only meaningful within the document
 * they came from and collide freely across documents. Holding this state for
 * the duration of a single transform is therefore a correctness requirement,
 * not a housekeeping preference.
 */
final class NavigationProjectionState
{
    /** @var array<string, DOMElement> */
    private array $targetsByControlPath = array();

    /** @var array<string, true> */
    private array $suppressedPaths = array();

    /** @var array<string, true> */
    private array $implicitDialogControlPaths = array();

    public function hasTargetForControl(DOMElement $control): bool
    {
        return isset($this->targetsByControlPath[$control->getNodePath()]);
    }

    public function targetForControl(DOMElement $control): ?DOMElement
    {
        return $this->targetsByControlPath[$control->getNodePath()] ?? null;
    }

    public function projectTarget(DOMElement $control, DOMElement $navigation): void
    {
        $this->targetsByControlPath[$control->getNodePath()] = $navigation;
    }

    public function suppress(DOMElement $element): void
    {
        $this->suppressedPaths[$element->getNodePath()] = true;
    }

    public function isSuppressed(DOMElement $element): bool
    {
        return isset($this->suppressedPaths[$element->getNodePath()]);
    }

    public function markImplicitDialogControl(DOMElement $control): void
    {
        $this->implicitDialogControlPaths[$control->getNodePath()] = true;
    }

    public function isImplicitDialogControl(DOMElement $control): bool
    {
        return isset($this->implicitDialogControlPaths[$control->getNodePath()]);
    }
}
