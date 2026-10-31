<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Elements;

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\CssValueInspector;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Support\SourceDom;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Support\SvgMaterializer;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Classification\FormControlClassifier;
use DOMElement;

/** Converts static search controls into native core/search blocks. */
final class SearchBlockConverter
{
    public function __construct(
        private readonly SearchBlockConversionContext $context,
        private readonly FormControlMetadataBuilder $formControlMetadataBuilder,
        private readonly PseudoFormAnalyzer $pseudoFormAnalyzer
    ) {
    }

    /** @return array<string, mixed>|null */
    public function searchBlockFromForm(DOMElement $form): ?array
    {
        $method = strtolower(trim(SourceDom::attr($form, 'method')));
        if ( '' !== $method && 'get' !== $method ) {
            return null;
        }

        if ( 0 < $form->getElementsByTagName('script')->length || array() !== SourceDom::eventMetadata($form) ) {
            return null;
        }

        $textInput = null;
        $submitControl = null;
        foreach ( FormControlClassifier::controlElements($form) as $control ) {
            if ( array() !== SourceDom::eventMetadata($control) ) {
                return null;
            }

            $tagName = strtolower($control->tagName);
            $type = FormControlClassifier::controlType($control);
            if ( 'input' === $tagName && in_array($type, array( 'text', 'search' ), true) ) {
                if ( null !== $textInput ) {
                    return null;
                }
                $textInput = $control;
                continue;
            }

            if ( ( 'button' === $tagName || 'input' === $tagName ) && 'submit' === $type ) {
                if ( null !== $submitControl ) {
                    return null;
                }
                $submitControl = $control;
                continue;
            }

            return null;
        }

        if ( ! $textInput instanceof DOMElement || ! $this->context->hasSearchFormSignal($form, $textInput) ) {
            return null;
        }

        // An empty sibling whose only paint is a background image is the
        // form's visible submit affordance; text-bearing or unpainted
        // decorations are not promoted to a button.
        $paintedSibling = null;
        $paintedIcon = null;
        if ( null === $submitControl ) {
            $candidate = $this->nextElementSibling($textInput);
            if ( $candidate instanceof DOMElement && 'span' === strtolower($candidate->tagName)
                && '' === trim($candidate->textContent) && array() === SourceDom::eventMetadata($candidate) ) {
                foreach ( array_merge(array( $candidate ), iterator_to_array($candidate->getElementsByTagName('*'))) as $part ) {
                    $declarations = $this->context->presentationDeclarations($part);
                    if ( 1 === preg_match('/url\(/i', ($declarations['background'] ?? '') . ($declarations['background-image'] ?? '')) ) {
                        $paintedSibling = $candidate;
                        $paintedIcon = $part;
                        $submitControl = $candidate;
                        break;
                    }
                }
            }
        }

        $label = $this->formControlMetadataBuilder->label($textInput);
        $showLabel = $this->hasVisibleLabel($textInput);
        if ( '' === $label ) {
            $label = trim(SourceDom::attr($form, 'aria-label'));
        }
        if ( '' === $label ) {
            $label = trim(SourceDom::attr($textInput, 'placeholder'));
        }

        $attrs = array_merge($this->context->presentationAttributes($form), array(
            'label'       => '' !== $label ? $label : 'Search',
            'showLabel'   => $showLabel,
            'placeholder' => SourceDom::attr($textInput, 'placeholder'),
        ));
        if ( $submitControl instanceof DOMElement ) {
            $attrs['buttonPosition'] = 'button-outside';
            $attrs['buttonText'] = $this->formControlMetadataBuilder->submitText($submitControl, 'Search');
            if ( $this->isIconOnlySearchControl($submitControl) ) {
                $attrs['buttonUseIcon'] = true;
            }
        } elseif ( null !== ($searchTrigger = $this->adjacentSearchTrigger($form)) ) {
            $attrs['buttonPosition'] = 'button-only';
            $attrs['buttonUseIcon'] = true;
            $attrs['style']['color']['text'] = '#000000';
            $attrs['style']['color']['background'] = 'transparent';
            $attrs['style']['border']['width'] = '0px';
            $triggerAttrs = $this->context->presentationAttributes($searchTrigger);
            $attrs['className'] = trim(implode(' ', array_filter(array(
                (string) ($attrs['className'] ?? ''),
                (string) ($triggerAttrs['className'] ?? ''),
                $this->registerNativeSearchTriggerCss($searchTrigger),
            ))));
        } else {
            $attrs['buttonPosition'] = 'no-button';
        }

        $attrs['className'] = trim((string) ($attrs['className'] ?? '') . ' ' . $this->registerSearchControlPresentation($textInput, $submitControl));

        // core/search cannot store source classes on its input or button, so
        // source rules addressing them are re-targeted onto the native parts.
        if ( '' !== trim(SourceDom::attr($textInput, 'class')) || $paintedSibling instanceof DOMElement ) {
            $marker = 'blocks-engine-search-subject-' . substr(hash('sha256', $textInput->getNodePath() ?? ''), 0, 12);
            $attrs['className'] = trim($attrs['className'] . ' ' . $marker);
            $target = ':where(.wp-block-search.' . $marker . ')';
            $this->context->registerInnerSubject($textInput, $target . ' :where(.wp-block-search__input)');
            if ( $paintedSibling instanceof DOMElement && $paintedIcon instanceof DOMElement ) {
                $attrs['buttonUseIcon'] = true;
                $button = $target . ' :where(.wp-block-search__button)';
                $this->context->registerInnerSubject($paintedSibling, $button);
                // The source icon paints the button in place of core's SVG.
                $css = $target . ' .wp-block-search__button>svg{display:none}';
                if ( $paintedIcon !== $paintedSibling ) {
                    $this->context->registerInnerSubject($paintedIcon, $button, '::before');
                    $css .= $target . ' .wp-block-search__button::before{content:"";display:inline-block}';
                }
                $this->context->generatedSupportStyles()->registerNativeSearchTrigger($marker, $css);
            }
        }

        return $this->context->createBlock('core/search', $attrs, array(), $form);
    }

    /** Accessible names and rendered label boxes are independent source facts. */
    private function hasVisibleLabel(DOMElement $control): bool
    {
        $label = $this->formControlMetadataBuilder->labelElement($control);
        if ( ! $label instanceof DOMElement || '' === trim($this->formControlMetadataBuilder->labelText($label)) ) {
            return false;
        }

        for ( $node = $label; $node instanceof DOMElement; $node = $node->parentNode ) {
            if ( $node->hasAttribute('hidden')
                || 1 === preg_match('/(?:^|\s)(?:sr-only|screen-reader-text|screen-reader-only|visually-hidden)(?:\s|$)/i', SourceDom::attr($node, 'class')) ) {
                return false;
            }
            $declarations = $this->context->presentationDeclarations($node);
            $display = strtolower(trim(preg_replace('/\s*!important\s*$/i', '', $declarations['display'] ?? '') ?? ''));
            $visibility = strtolower(trim(preg_replace('/\s*!important\s*$/i', '', $declarations['visibility'] ?? '') ?? ''));
            if ( 'none' === $display || in_array($visibility, array( 'hidden', 'collapse' ), true)
                || CssValueInspector::isVisuallyClippedBox($declarations) ) {
                return false;
            }
        }

        return true;
    }

    /** Bridge replaced source controls through the existing owned search stylesheet. */
    private function registerSearchControlPresentation(DOMElement $input, ?DOMElement $button): string
    {
        $inputCss = $this->sourceControlPadding($input, '1px 2px');
        $buttonCss = '';
        if ( $button instanceof DOMElement ) {
            $buttonCss = $this->sourceControlPadding($button, '1px 6px');
            if ( $this->isIconOnlySearchControl($button) ) {
                // Core replaces the source glyph with an SVG using currentColor.
                // Resolve on the glyph first, then its ancestors (including the
                // button), so a removed icon-font selector still paints it.
                $icon = $button;
                foreach ( $button->getElementsByTagName('*') as $descendant ) {
                    if ( in_array(strtolower($descendant->tagName), array( 'svg', 'i', 'span' ), true) ) {
                        $icon = $descendant;
                        break;
                    }
                }
                for ( $carrier = $icon; $carrier instanceof DOMElement; $carrier = $carrier->parentNode ) {
                    $declarations = $this->context->presentationDeclarations($carrier);
                    $color = trim((string) ($declarations['color'] ?? ''));
                    if ( '' !== $color && ! in_array(strtolower($color), array( 'inherit', 'unset', 'currentcolor' ), true) ) {
                        $buttonCss .= 'color:' . preg_replace('/\s*!important\s*$/i', '', $color) . '!important;';
                        break;
                    }
                }
            }
        }
        $className = 'blocks-engine-source-search-control-' . substr(hash('sha256', $inputCss . '|' . $buttonCss), 0, 12);
        $selector = '.wp-block-search.' . $className;
        $this->context->generatedSupportStyles()->registerNativeSearchTrigger($className,
            $selector . ' .wp-block-search__input{' . $inputCss . '}'
            . ('' !== $buttonCss ? $selector . ' .wp-block-search__button{' . $buttonCss . '}' : '')
        );
        return $className;
    }

    private function sourceControlPadding(DOMElement $control, string $uaPadding): string
    {
        $declarations = $this->context->presentationDeclarations($control);
        // A UA baseline is necessary even when only one author longhand is
        // present: core/theme defaults must not fill the remaining sides.
        $padding = array( 'padding' => $uaPadding );
        foreach ( $declarations as $property => $value ) {
            if ( 'padding' === $property || str_starts_with($property, 'padding-') ) {
                $padding[$property] = preg_replace('/\s*!important\s*$/i', '', trim($value));
            }
        }
        $css = '';
        foreach ( $padding as $property => $value ) {
            $css .= $property . ':' . $value . '!important;';
        }
        return $css;
    }

    private function hasAdjacentSearchTrigger(DOMElement $form): bool
    {
        return null !== $this->adjacentSearchTrigger($form);
    }

    private function adjacentSearchTrigger(DOMElement $form): ?DOMElement
    {
        $containers = array( $form );
        if ( $form->parentNode instanceof DOMElement ) {
            $containers[] = $form->parentNode;
        }

        foreach ( $containers as $container ) {
            $sibling = $this->nextElementSibling($container);
            if ( $sibling instanceof DOMElement && $this->isAdjacentSearchTriggerControl($sibling) ) {
                return $sibling;
            }
        }

        return null;
    }

    private function registerNativeSearchTriggerCss(DOMElement $trigger): string
    {
        $svg = $trigger->getElementsByTagName('svg')->item(0);
        if ( ! $svg instanceof DOMElement ) {
            return '';
        }

        $svgDeclarations = $this->context->presentationDeclarations($svg);
        $width = $this->cssPixelLength((string) ($svgDeclarations['width'] ?? '')) ?? $this->cssPixelLength(SourceDom::attr($svg, 'width'));
        $height = $this->cssPixelLength((string) ($svgDeclarations['height'] ?? '')) ?? $this->cssPixelLength(SourceDom::attr($svg, 'height'));
        if ( null === $width || null === $height ) {
            $viewBox = preg_split('/[\s,]+/', trim(SourceDom::attr($svg, 'viewbox'))) ?: array();
            if ( 4 === count($viewBox) && is_numeric($viewBox[2]) && is_numeric($viewBox[3]) ) {
                $width ??= (float) $viewBox[2];
                $height ??= (float) $viewBox[3];
            }
        }
        if ( null === $width || null === $height || 0 >= $width || 0 >= $height ) {
            return '';
        }

        $svgMarkup = SvgMaterializer::ensureSvgImageNamespace($this->context->restoreSvgCasing(SourceDom::outerHtml($svg)));
        $className = 'blocks-engine-source-search-icon-' . substr(hash('sha256', $svgMarkup), 0, 12);
        if ( $this->context->generatedSupportStyles()->hasNativeSearchTrigger($className) ) {
            return $className;
        }

        $declarations = $this->context->presentationDeclarations($trigger);
        $triggerHeight = isset($declarations['height']) && '' !== trim($declarations['height'])
            ? 'height:' . trim($declarations['height']) . '!important;'
            : '';
        $triggerWidth = $this->cssPixelLength((string) ($declarations['width'] ?? ''));
        $iconWidth = $this->cssNumber($width);
        $iconHeight = $this->cssNumber($height);
        $buttonWidth = $this->cssNumber($triggerWidth ?? ($width + 12));
        $dataUri = 'data:image/svg+xml,' . rawurlencode($svgMarkup);
        $selector = '.wp-block-search.' . $className;
        $this->context->generatedSupportStyles()->registerNativeSearchTrigger($className, $selector . '{display:block!important;box-sizing:border-box!important;flex:0 0 ' . $buttonWidth . 'px!important;width:' . $buttonWidth . 'px!important;' . $triggerHeight . '}'
            . $selector . ' .wp-block-search__inside-wrapper{' . $triggerHeight . 'box-sizing:border-box!important;width:100%!important}'
            . $selector . ' .wp-block-search__button{display:block!important;box-sizing:border-box!important;width:100%!important;height:100%!important;min-width:0!important;margin:0!important;padding:1px 6px!important;font:400 13.3333px Arial!important;line-height:normal!important;text-align:center!important;color:#000!important;background:none!important;border:0!important;border-radius:0!important}'
            . $selector . '.wp-block-search__icon-button .wp-block-search__button.has-icon>svg.search-icon{display:none!important}'
            . $selector . ' .wp-block-search__button:before{content:"";display:inline-block;width:' . $iconWidth . 'px;height:' . $iconHeight . 'px;background:url("' . $dataUri . '") center/contain no-repeat}');

        return $className;
    }

    private function cssPixelLength(string $value): ?float
    {
        return preg_match('/^([0-9]+(?:\.[0-9]+)?)(?:px)?$/i', trim($value), $match)
            ? (float) $match[1]
            : null;
    }

    private function cssNumber(float $value): string
    {
        return rtrim(rtrim(number_format($value, 4, '.', ''), '0'), '.');
    }

    /** @return array<string, mixed>|null */
    public function searchBlockFromWrapper(DOMElement $element): ?array
    {
        if ( 1 !== SourceDom::childElementCount($element) ) {
            return null;
        }

        $form = null;
        foreach ( $element->childNodes as $child ) {
            if ( $child instanceof DOMElement && 'form' === strtolower($child->tagName) ) {
                $form = $child;
                break;
            }
        }

        if ( ! $form instanceof DOMElement || ! $this->hasAdjacentSearchTrigger($form) ) {
            return null;
        }
        foreach ( $element->childNodes as $child ) {
            if ( XML_TEXT_NODE === $child->nodeType && '' !== trim($child->textContent ?? '') ) {
                return null;
            }
        }

        return $this->searchBlockFromForm($form);
    }

    /** @return array<string, mixed>|null */
    public function searchBlockFromStandaloneTrigger(DOMElement $trigger): ?array
    {
        if ( ! $this->isStandaloneSearchTrigger($trigger) ) {
            return null;
        }

        $input = $this->standaloneInputForTrigger($trigger);
        if ( ! $input instanceof DOMElement ) {
            return null;
        }

        $label = trim(SourceDom::attr($input, 'aria-label'));
        if ( '' === $label ) {
            $label = trim(SourceDom::attr($input, 'placeholder'));
        }
        $triggerAttrs = $this->context->presentationAttributes($trigger);
        $attrs = array_merge($triggerAttrs, array(
            'label'          => '' !== $label ? $label : 'Search',
            'showLabel'      => false,
            'placeholder'    => SourceDom::attr($input, 'placeholder'),
            'buttonPosition' => 'button-only',
            'buttonUseIcon'  => true,
        ));
        $attrs['className'] = trim(implode(' ', array_filter(array(
            (string) ($triggerAttrs['className'] ?? ''),
            $this->registerNativeSearchTriggerCss($trigger),
        ))));

        return $this->context->createBlock('core/search', $attrs, array(), $trigger);
    }

    public function isReplacedSearchClusterControl(DOMElement $control): bool
    {
        if ( $this->isAdjacentSearchTriggerControl($control) ) {
            $formContainer = $this->previousElementSibling($control);
            return $formContainer instanceof DOMElement && $this->containsNativeSearchForm($formContainer);
        }

        if ( ! $this->isSearchCloseControl($control) ) {
            return false;
        }

        $trigger = $this->previousElementSibling($control);
        $formContainer = $trigger instanceof DOMElement ? $this->previousElementSibling($trigger) : null;
        return $trigger instanceof DOMElement
            && $this->isAdjacentSearchTriggerControl($trigger)
            && $formContainer instanceof DOMElement
            && $this->containsNativeSearchForm($formContainer);
    }

    private function containsNativeSearchForm(DOMElement $element): bool
    {
        $forms = 'form' === strtolower($element->tagName)
            ? array( $element )
            : iterator_to_array($element->getElementsByTagName('form'));
        return 1 === count($forms) && $forms[0] instanceof DOMElement && $this->isNativeSearchForm($forms[0]);
    }

    private function nextElementSibling(DOMElement $element): ?DOMElement
    {
        for ( $sibling = $element->nextSibling; null !== $sibling; $sibling = $sibling->nextSibling ) {
            if ( $sibling instanceof DOMElement ) {
                return $sibling;
            }
        }

        return null;
    }

    private function previousElementSibling(DOMElement $element): ?DOMElement
    {
        for ( $sibling = $element->previousSibling; null !== $sibling; $sibling = $sibling->previousSibling ) {
            if ( $sibling instanceof DOMElement ) {
                return $sibling;
            }
        }

        return null;
    }

    private function isSearchCloseControl(DOMElement $control): bool
    {
        $haystack = strtolower(implode(' ', array(
            SourceDom::attr($control, 'class'),
            SourceDom::attr($control, 'id'),
            SourceDom::attr($control, 'aria-label'),
            SourceDom::attr($control, 'title'),
        )));
        return str_contains($haystack, 'search') && str_contains($haystack, 'close');
    }

    private function isNativeSearchForm(DOMElement $form): bool
    {
        $method = strtolower(trim(SourceDom::attr($form, 'method')));
        if ( '' !== $method && 'get' !== $method ) {
            return false;
        }
        if ( 0 < $form->getElementsByTagName('script')->length || array() !== SourceDom::eventMetadata($form) ) {
            return false;
        }

        $textInput = null;
        $submitControl = null;
        foreach ( FormControlClassifier::controlElements($form) as $control ) {
            if ( array() !== SourceDom::eventMetadata($control) ) {
                return false;
            }
            $tagName = strtolower($control->tagName);
            $type = FormControlClassifier::controlType($control);
            if ( 'input' === $tagName && in_array($type, array( 'text', 'search' ), true) ) {
                if ( null !== $textInput ) {
                    return false;
                }
                $textInput = $control;
                continue;
            }
            if ( ( 'button' === $tagName || 'input' === $tagName ) && 'submit' === $type ) {
                if ( null !== $submitControl ) {
                    return false;
                }
                $submitControl = $control;
                continue;
            }
            return false;
        }

        return $textInput instanceof DOMElement && $this->context->hasSearchFormSignal($form, $textInput);
    }

    private function isIconOnlySearchControl(DOMElement $control): bool
    {
        if ( ! $this->context->hasSearchIconSignal($control) ) {
            return false;
        }

        $text = trim(preg_replace('/\s+/', ' ', $control->textContent ?? '') ?? '');
        return '' === $text || 0 < $control->getElementsByTagName('svg')->length;
    }

    private function isAdjacentSearchTriggerControl(DOMElement $control): bool
    {
        if ( ! $this->isIconOnlySearchControl($control) ) {
            return false;
        }

        $identity = strtolower(trim(SourceDom::attr($control, 'class') . ' ' . SourceDom::attr($control, 'id')));
        foreach ( preg_split('/\s+/', $identity) ?: array() as $token ) {
            if ( in_array($token, array( 'search-icon', 'search-toggle', 'search-trigger', 'open-search' ), true) ) {
                return true;
            }
        }

        $accessibleName = strtolower(trim(SourceDom::attr($control, 'aria-label') . ' ' . SourceDom::attr($control, 'title')));
        return in_array($accessibleName, array( 'search', 'open search', 'expand search', 'toggle search' ), true);
    }

    /** @return array<string, mixed>|null */
    public function searchBlockFromStandaloneControl(DOMElement $element): ?array
    {
        if ( 0 < $element->getElementsByTagName('form')->length || 0 < $element->getElementsByTagName('script')->length || array() !== SourceDom::eventMetadata($element) || $this->context->isRuntimeDomTarget($element) ) {
            return null;
        }

        $inputs = array();
        foreach ( $element->getElementsByTagName('input') as $input ) {
            if ( $input instanceof DOMElement && $input->parentNode === $element && 'search' === FormControlClassifier::controlType($input) ) {
                $inputs[] = $input;
            }
        }
        if ( 1 !== count($inputs) || $this->context->isRuntimeDomTarget($inputs[0]) ) {
            return null;
        }
        $controls = FormControlClassifier::controlElements($element);
        if ( 1 !== count($controls) ) {
            return null;
        }

        $searchInput = $inputs[0];
        if ( ! $this->pseudoFormAnalyzer->hasStandaloneSearchSignal($element, $searchInput) ) {
            return null;
        }

        if ( $this->hasStandaloneSearchTrigger($searchInput) ) {
            return null;
        }

        $label = $this->formControlMetadataBuilder->label($searchInput);
        if ( '' === $label ) {
            $label = SourceDom::attr($searchInput, 'aria-label');
        }
        if ( '' === $label ) {
            $label = SourceDom::attr($searchInput, 'placeholder');
        }

        if ( '' !== SourceDom::attr($searchInput, 'id') || ! in_array(SourceDom::attr($searchInput, 'name'), array( '', 's' ), true) ) {
            return $this->context->htmlPreservationBlock($element);
        }
        if ( ! $this->hasOnlyDecorativeSearchSiblings($element, $searchInput) ) {
            return null;
        }

        $placeholder = SourceDom::attr($searchInput, 'placeholder');
        $attrs = array_merge($this->context->presentationAttributes($element), array(
            'label'          => '' !== $label ? $label : 'Search',
            'showLabel'      => false,
            'placeholder'    => $placeholder,
            'buttonPosition' => 'no-button',
        ));
        if ( array() !== SourceDom::eventMetadata($searchInput) ) {
            // A runtime-owned search input needs a replacement activation control.
            $attrs['buttonPosition'] = 'button-inside';
            $attrs['buttonUseIcon'] = true;
        }

        return $this->context->createBlock('core/search', $attrs, array(), $element);
    }

    private function hasStandaloneSearchTrigger(DOMElement $element): bool
    {
        $inputs = $this->standaloneSearchInputs($element);
        $triggers = $this->standaloneSearchTriggers($element);
        return count($inputs) === count($triggers) && 0 < count($inputs) && in_array($element, $inputs, true);
    }

    private function standaloneInputForTrigger(DOMElement $trigger): ?DOMElement
    {
        $inputs = $this->standaloneSearchInputs($trigger);
        $triggers = $this->standaloneSearchTriggers($trigger);
        if ( count($inputs) !== count($triggers) || 0 === count($inputs) ) {
            return null;
        }

        $index = array_search($trigger, $triggers, true);
        return false === $index ? null : $inputs[$index];
    }

    /** @return array<int, DOMElement> */
    private function standaloneSearchInputs(DOMElement $element): array
    {
        $inputs = array();
        foreach ( $element->ownerDocument?->getElementsByTagName('input') ?? array() as $input ) {
            if ( ! $input instanceof DOMElement
                || 'search' !== FormControlClassifier::controlType($input)
                || FormControlClassifier::hasFormAncestor($input)
                || '' !== SourceDom::attr($input, 'id')
                || '' !== SourceDom::attr($input, 'name')
                || ! $this->pseudoFormAnalyzer->hasStandaloneSearchSignal($input->parentNode instanceof DOMElement ? $input->parentNode : $input, $input) ) {
                continue;
            }
            $inputs[] = $input;
        }

        return $inputs;
    }

    /** @return array<int, DOMElement> */
    private function standaloneSearchTriggers(DOMElement $element): array
    {
        $triggers = array();
        foreach ( $element->ownerDocument?->getElementsByTagName('*') ?? array() as $candidate ) {
            if ( $candidate instanceof DOMElement && $this->isStandaloneSearchTrigger($candidate) ) {
                $triggers[] = $candidate;
            }
        }

        return $triggers;
    }

    private function isStandaloneSearchTrigger(DOMElement $element): bool
    {
        if ( 'button' !== strtolower($element->tagName) && 'button' !== strtolower(SourceDom::attr($element, 'role')) ) {
            return false;
        }
        if ( ! $this->isIconOnlySearchControl($element) ) {
            return false;
        }

        $label = strtolower(trim(SourceDom::attr($element, 'aria-label') . ' ' . SourceDom::attr($element, 'title')));
        return 1 === preg_match('/^(?:open|expand|toggle)\s+(?:the\s+)?search(?:\s+(?:bar|field))?$/', $label);
    }

    private function hasOnlyDecorativeSearchSiblings(DOMElement $element, DOMElement $searchInput): bool
    {
        foreach ( $element->childNodes as $child ) {
            if ( $child === $searchInput || XML_TEXT_NODE === $child->nodeType && '' === trim($child->textContent ?? '') ) {
                continue;
            }
            if ( ! $child instanceof DOMElement
                || 'true' !== strtolower(SourceDom::attr($child, 'aria-hidden'))
                || FormControlClassifier::isControlElement($child)
                || '' !== SourceDom::attr($child, 'role')
                || '' !== SourceDom::attr($child, 'tabindex')
                || array() !== SourceDom::eventMetadata($child) ) {
                return false;
            }
        }

        return true;
    }
}
