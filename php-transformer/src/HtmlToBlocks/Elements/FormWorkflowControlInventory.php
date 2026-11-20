<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Elements;

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Session\HtmlTransformerSession;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Support\SourceDom;
use DOMElement;

/** The provider and readable tree must enumerate the same surviving fields. */
final class FormWorkflowControlInventory
{
    /** @return list<DOMElement> */
    public static function elements(DOMElement $form, FormControlMetadataBuilder $metadata, HtmlTransformerSession $session): array
    {
        // The readable block and its finding enumerate the same form in one
        // dispatch; they share one inventory (#6752).
        return FormBuildScope::remember($form, 'workflow_control_inventory', array( $metadata, $session ), static fn (): array => self::enumerate($form, $metadata, $session));
    }

    /** @return list<DOMElement> */
    private static function enumerate(DOMElement $form, FormControlMetadataBuilder $metadata, HtmlTransformerSession $session): array
    {
        $controls = $metadata->controls($form, array(), null, $elements);
        if ('form' !== strtolower($form->tagName)) return $elements;

        $assets = $session->authorStyleAnalysis()->stylesheetAssets(true);
        $css = $session->sourceStyleResolutionState()->formLayoutCss();
        $layout = FormBuildScope::layoutGraph($form, null, $assets, $css);
        $steps = true === ($layout['truncated'] ?? true) ? null : FormBuildScope::stepTrack($form, $layout, $assets, $css);
        $intent = FormRuntimeRequirementAnalyzer::intentControls($form);
        if (null === $steps) {
            $panels = (new FormWorkflowPanelDetector())->controls($form, $intent);
            $selectors = array_column($controls, 'selector');
            $intent = array_values(array_filter($intent, static fn (DOMElement $control): bool => in_array($control, $panels, true) || in_array(SourceDom::elementSelector($control), $selectors, true)));
        }
        $selectors = array_column($controls, 'selector');
        if (array_filter($intent, static fn (DOMElement $control): bool => ! in_array(SourceDom::elementSelector($control), $selectors, true))) {
            $metadata->controls($form, array(), $intent, $elements);
        }
        return $elements;
    }
}
