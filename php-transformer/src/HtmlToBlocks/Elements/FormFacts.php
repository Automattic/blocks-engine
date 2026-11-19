<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Elements;

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Classification\FormControlClassifier;
use Closure;
use DOMElement;

/**
 * Whole-form facts, each derived once per form and reused for every control.
 *
 * Every per-control decision inside one form (is this button its sole action,
 * does the form collect only choices) asks the same question about the whole
 * form. Asking it again per control makes form conversion quadratic in the
 * control count (#6707). A holder keeps one instance per form only across a
 * span in which it treats the form as fixed, such as one readable build that
 * has already snapshotted the form's control inventory, and drops it after.
 */
final class FormFacts
{
    /** @var list<DOMElement>|null */
    private ?array $controlElements = null;

    private ?bool $hasSoleActionControl = null;

    private ?string $intent = null;

    private ?bool $collectsOnlyChoices = null;

    private ?bool $collectsOnlyPurchaseChoices = null;

    /** @var Closure(DOMElement): bool */
    private Closure $soleActionControl;

    public function __construct(private readonly DOMElement $form)
    {
        $others = FormControlClassifier::soleActionControlMemo();
        $this->soleActionControl = fn (DOMElement $owner): bool => $owner === $this->form ? $this->hasSoleActionControl() : $others($owner);
    }

    /** @return list<DOMElement> The form's authored controls ({@see FormControlClassifier::controlElements()}). */
    public function controlElements(): array
    {
        return $this->controlElements ??= array_values(FormControlClassifier::controlElements($this->form));
    }

    /** {@see FormControlClassifier::hasSoleActionControl()} */
    public function hasSoleActionControl(): bool
    {
        return $this->hasSoleActionControl ??= FormControlClassifier::hasSoleActionControlAmong($this->controlElements());
    }

    /** {@see FormControlClassifier::isSubmitLikeControl()} for a control this form owns. */
    public function isSubmitLikeControl(DOMElement $control): bool
    {
        return FormControlClassifier::isSubmitLikeControl($control, $this->soleActionControl);
    }

    /** {@see FormRuntimeRequirementAnalyzer::formIntent()} */
    public function intent(): string
    {
        return $this->intent ??= FormRuntimeRequirementAnalyzer::formIntent($this->form, $this->soleActionControl);
    }

    /** {@see FormRuntimeRequirementAnalyzer::collectsOnlyChoices()} */
    public function collectsOnlyChoices(): bool
    {
        return $this->collectsOnlyChoices ??= FormRuntimeRequirementAnalyzer::isChoiceOnlyIntent($this->intent());
    }

    /** {@see FormRuntimeRequirementAnalyzer::collectsOnlyPurchaseChoices()} */
    public function collectsOnlyPurchaseChoices(): bool
    {
        return $this->collectsOnlyPurchaseChoices ??= FormRuntimeRequirementAnalyzer::collectsOnlyPurchaseChoicesWithIntent($this->form, $this->intent(), $this->soleActionControl);
    }
}
