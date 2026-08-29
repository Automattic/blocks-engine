<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Elements;

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
        $method = strtolower(trim($this->context->attr($form, 'method')));
        if ( '' !== $method && 'get' !== $method ) {
            return null;
        }

        if ( 0 < $form->getElementsByTagName('script')->length || array() !== $this->context->eventMetadata($form) ) {
            return null;
        }

        $textInput = null;
        $submitControl = null;
        foreach ( FormControlClassifier::controlElements($form) as $control ) {
            if ( array() !== $this->context->eventMetadata($control) ) {
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

        $label = $this->formControlMetadataBuilder->label($textInput);
        $showLabel = '' !== $label;
        if ( '' === $label ) {
            $label = trim($this->context->attr($form, 'aria-label'));
        }
        if ( '' === $label ) {
            $label = trim($this->context->attr($textInput, 'placeholder'));
        }

        $attrs = array_merge($this->context->presentationAttributes($form), array(
            'label'       => '' !== $label ? $label : 'Search',
            'showLabel'   => $showLabel,
            'placeholder' => $this->context->attr($textInput, 'placeholder'),
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

        return $this->context->createBlock('core/search', $attrs, array(), $form);
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
        $width = $this->cssPixelLength((string) ($svgDeclarations['width'] ?? '')) ?? $this->cssPixelLength($this->context->attr($svg, 'width'));
        $height = $this->cssPixelLength((string) ($svgDeclarations['height'] ?? '')) ?? $this->cssPixelLength($this->context->attr($svg, 'height'));
        if ( null === $width || null === $height ) {
            $viewBox = preg_split('/[\s,]+/', trim($this->context->attr($svg, 'viewbox'))) ?: array();
            if ( 4 === count($viewBox) && is_numeric($viewBox[2]) && is_numeric($viewBox[3]) ) {
                $width ??= (float) $viewBox[2];
                $height ??= (float) $viewBox[3];
            }
        }
        if ( null === $width || null === $height || 0 >= $width || 0 >= $height ) {
            return '';
        }

        $svgMarkup = $this->context->restoreSvgCasing($this->context->outerHtml($svg));
        if ( ! preg_match('/<svg\b[^>]*\bxmlns=/i', $svgMarkup) ) {
            $svgMarkup = preg_replace('/<svg\b/i', '<svg xmlns="http://www.w3.org/2000/svg"', $svgMarkup, 1) ?? $svgMarkup;
        }
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
        if ( 1 !== $this->context->childElementCount($element) ) {
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
            $this->context->attr($control, 'class'),
            $this->context->attr($control, 'id'),
            $this->context->attr($control, 'aria-label'),
            $this->context->attr($control, 'title'),
        )));
        return str_contains($haystack, 'search') && str_contains($haystack, 'close');
    }

    private function isNativeSearchForm(DOMElement $form): bool
    {
        $method = strtolower(trim($this->context->attr($form, 'method')));
        if ( '' !== $method && 'get' !== $method ) {
            return false;
        }
        if ( 0 < $form->getElementsByTagName('script')->length || array() !== $this->context->eventMetadata($form) ) {
            return false;
        }

        $textInput = null;
        $submitControl = null;
        foreach ( FormControlClassifier::controlElements($form) as $control ) {
            if ( array() !== $this->context->eventMetadata($control) ) {
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
        $haystack = strtolower(implode(' ', array(
            $this->context->attr($control, 'class'),
            $this->context->attr($control, 'id'),
            $this->context->attr($control, 'aria-label'),
            $this->context->attr($control, 'title'),
        )));
        if ( ! str_contains($haystack, 'search') || str_contains($haystack, 'close') ) {
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

        $identity = strtolower(trim($this->context->attr($control, 'class') . ' ' . $this->context->attr($control, 'id')));
        foreach ( preg_split('/\s+/', $identity) ?: array() as $token ) {
            if ( in_array($token, array( 'search-icon', 'search-toggle', 'search-trigger', 'open-search' ), true) ) {
                return true;
            }
        }

        $accessibleName = strtolower(trim($this->context->attr($control, 'aria-label') . ' ' . $this->context->attr($control, 'title')));
        return in_array($accessibleName, array( 'search', 'open search', 'expand search', 'toggle search' ), true);
    }

    /** @return array<string, mixed>|null */
    public function searchBlockFromStandaloneControl(DOMElement $element): ?array
    {
        if ( 0 < $element->getElementsByTagName('form')->length || 0 < $element->getElementsByTagName('script')->length || array() !== $this->context->eventMetadata($element) || $this->context->isRuntimeDomTarget($element) ) {
            return null;
        }

        $inputs = array();
        foreach ( $element->getElementsByTagName('input') as $input ) {
            if ( $input instanceof DOMElement && $input->parentNode === $element && 'search' === FormControlClassifier::controlType($input) ) {
                $inputs[] = $input;
            }
        }
        if ( 1 !== count($inputs) || array() !== $this->context->eventMetadata($inputs[0]) || $this->context->isRuntimeDomTarget($inputs[0]) ) {
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

        $label = $this->formControlMetadataBuilder->label($searchInput);
        if ( '' === $label ) {
            $label = $this->context->attr($searchInput, 'aria-label');
        }
        if ( '' === $label ) {
            $label = $this->context->attr($searchInput, 'placeholder');
        }

        if ( '' !== $this->context->attr($searchInput, 'id') || 's' !== $this->context->attr($searchInput, 'name') ) {
            return $this->context->htmlPreservationBlock($element);
        }
        if ( 1 !== $this->context->childElementCount($element) ) {
            return null;
        }

        $placeholder = $this->context->attr($searchInput, 'placeholder');
        return $this->context->createBlock('core/search', array_merge($this->context->presentationAttributes($element), array(
            'label'          => '' !== $label ? $label : 'Search',
            'showLabel'      => false,
            'placeholder'    => $placeholder,
            'buttonPosition' => 'no-button',
        )), array(), $element);
    }
}
