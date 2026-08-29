<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Support;

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Generators\AuthoredInputBlockGenerator;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Generators\AuthoredSelectBlockGenerator;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Classification\FormControlClassifier;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Diagnostics\FallbackDiagnostic;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\FormLayoutGraphBuilder;
use DOMDocument;
use DOMElement;
use DOMNode;

trait FormDispatchTrait
{
    /** Bounds for the reported form topology, owned by the dispatch that reads them. */
    private const MAX_FORM_TOPOLOGY_DEPTH = 16;

    private const MAX_FORM_TOPOLOGY_NODES = 128;

    private const MAX_FORM_TOPOLOGY_CLASSES = 8;

    /** @var array<int, string> */
    private const FORM_TOPOLOGY_WRAPPER_TAGS = array(
        'article', 'aside', 'dd', 'div', 'dl', 'dt', 'fieldset', 'footer', 'header',
        'label', 'li', 'main', 'nav', 'ol', 'p', 'section', 'span', 'table', 'tbody',
        'td', 'tfoot', 'th', 'thead', 'tr', 'ul',
    );

    /**
     * @param array<int, array<string, mixed>> $fallbacks
     * @return array<string, mixed>|null
     */
    private function convertFormDispatchElement(DOMElement $element, array &$fallbacks): ?array
    {
        $searchBlock = $this->searchBlockFromForm($element);
        if ( null !== $searchBlock ) {
            return $searchBlock;
        }

        $readableFormBlock = $this->readableFormBlockFromForm($element);
        if ( null !== $readableFormBlock && ! $this->formRequiresRuntimePreservation($element) ) {
            if ( $this->formHasDataEntryControls($element) ) {
                $fallbacks[] = $this->formFallbackFinding($element, $readableFormBlock);
            }

            return $readableFormBlock;
        }

        if ( $this->formHasDataEntryControls($element) ) {
            $composition = $this->compositionalFormBlock($element, $fallbacks);
            if ( null !== $composition ) {
                $fallbacks[] = $this->formFallbackFinding($element, $composition['block'], $composition['slot']);
                $this->recordFormRuntimeIsland($element, $composition['block']);
                return $composition['block'];
            }
            $preservationBlock = $this->htmlPreservationBlock($element);
            $fallbacks[] = $this->formFallbackFinding($element, $readableFormBlock, $preservationBlock);
            $this->recordFormRuntimeIsland($element, $readableFormBlock);

            return $preservationBlock;
        }

        $readableFormBlock = $this->readableFormBlockFromForm($element, true);
        $this->recordFormRuntimeIsland($element, $readableFormBlock);

        // Surface a form fallback finding so a downstream consumer can map the
        // preserved control structure onto a working form provider.
        if ( null === $readableFormBlock || $this->formHasDataEntryControls($element) ) {
            $fallbacks[] = $this->formFallbackFinding($element, $readableFormBlock);
        }

        return $readableFormBlock;
    }

    /**
     * @param array<int, array<string, mixed>> $fallbacks
     */
    private function captureDivBasedPseudoFormFallback(DOMElement $element, array &$fallbacks): void
    {
        // Some signup/contact widgets pair data-entry controls with a submit-like
        // control inside a plain container. Emit the same finding as a real form.
        if ( $this->isDivBasedPseudoForm($element) ) {
            $fallbacks[] = $this->formFallbackFinding($element, $this->readableFormBlockFromForm($element, true));
        }
    }

    /**
     * @param array<string, mixed>|null $readableFormBlock
     */
    private function recordFormRuntimeIsland(DOMElement $element, ?array $readableFormBlock): void
    {
        $controls = $this->formControls($element);
        $this->runtimeIslands->recordRuntimeIsland($element, 'form', 'form_requires_runtime', 'server_or_client_form_handler', array(
            'form'             => $this->formMetadata($element),
            'controls'         => $controls,
            'control_count'    => count($controls),
            'events'           => $this->eventMetadata($element),
            'readable_blocks'  => null !== $readableFormBlock ? array( $readableFormBlock ) : array(),
            'required_scripts' => $this->requiredScriptsForElement($element),
        ));
    }

    private function formControls(DOMElement $form): array
    {
        $controls = array();
        $order = 0;
        foreach ( $this->formControlElements($form) as $control ) {
            $metadata = $this->formControlMetadata($control);
            if ( array() !== $metadata ) {
                $metadata['order'] = $order;
                $controls[] = $metadata;
                ++$order;
            }
        }

        return $controls;
    }

    /**
     * Preserve only control-bearing wrapper ancestry. The node table is bounded,
     * source ordered, and references the compatibility controls by flat index.
     *
     * @return array<string, mixed>
     */
    private function formControlTopology(DOMElement $form): array
    {
        $controlIndexes = array();
        $relevantElements = array();
        foreach ( $this->formControlElements($form) as $index => $control ) {
            $controlIndexes[$control->getNodePath()] = $index;
            for ( $ancestor = $control->parentNode; $ancestor instanceof DOMElement && $ancestor !== $form; $ancestor = $ancestor->parentNode ) {
                $relevantElements[$ancestor->getNodePath()] = true;
            }
        }

        $nodes = array();
        $wrapperIndex = 0;
        $truncated = false;
        $order = 0;
        foreach ( $form->childNodes as $child ) {
            if ( ! $child instanceof DOMElement ) continue;
            if ( $this->appendFormTopologyNode($child, null, $order, 0, $controlIndexes, $relevantElements, $nodes, $wrapperIndex, $truncated) ) ++$order;
        }

        return array(
            'schema'    => 'generic/form-control-topology/v1',
            'max_depth' => self::MAX_FORM_TOPOLOGY_DEPTH,
            'max_nodes' => self::MAX_FORM_TOPOLOGY_NODES,
            'nodes'     => $nodes,
            'truncated' => $truncated,
        );
    }

    /**
     * @param array<string, int> $controlIndexes
     * @param array<string, bool> $relevantElements
     * @param array<int, array<string, mixed>> $nodes
     */
    private function appendFormTopologyNode(DOMElement $element, ?string $parent, int $order, int $depth, array $controlIndexes, array $relevantElements, array &$nodes, int &$wrapperIndex, bool &$truncated): bool
    {
        $nodePath = $element->getNodePath();
        if ( ! isset($controlIndexes[$nodePath]) && ! isset($relevantElements[$nodePath]) ) return false;
        if ( $depth > self::MAX_FORM_TOPOLOGY_DEPTH || count($nodes) >= self::MAX_FORM_TOPOLOGY_NODES ) {
            $truncated = true;
            return false;
        }

        if ( isset($controlIndexes[$nodePath]) ) {
            $controlIndex = $controlIndexes[$nodePath];
            $nodes[] = array_filter(array(
                'id'      => 'control-' . $controlIndex,
                'kind'    => 'control',
                'parent'  => $parent,
                'order'   => $order,
                'depth'   => $depth,
                'control' => $controlIndex,
            ), static fn (mixed $value): bool => null !== $value);
            return true;
        }

        $id = 'wrapper-' . $wrapperIndex++;
        $nodes[] = array_filter(array_merge(array(
            'id'     => $id,
            'kind'   => 'wrapper',
            'parent' => $parent,
            'order'  => $order,
            'depth'  => $depth,
        ), $this->formTopologyPresentation($element)), static fn (mixed $value): bool => null !== $value && '' !== $value);

        $childOrder = 0;
        foreach ( $element->childNodes as $child ) {
            if ( ! $child instanceof DOMElement ) continue;
            if ( $this->appendFormTopologyNode($child, $id, $childOrder, $depth + 1, $controlIndexes, $relevantElements, $nodes, $wrapperIndex, $truncated) ) ++$childOrder;
        }

        return true;
    }

    /** @return array<string, string> */
    private function formTopologyPresentation(DOMElement $element): array
    {
        $tag = strtolower($element->tagName);
        $presentation = array();
        if ( in_array($tag, self::FORM_TOPOLOGY_WRAPPER_TAGS, true) ) $presentation['tag'] = $tag;

        $id = trim($this->attr($element, 'id'));
        if ( 1 === preg_match('/^[A-Za-z_][A-Za-z0-9_-]{0,79}$/D', $id) ) $presentation['source_id'] = $id;

        $classes = array();
        foreach ( preg_split('/\s+/', trim($this->attr($element, 'class'))) ?: array() as $class ) {
            if ( count($classes) >= self::MAX_FORM_TOPOLOGY_CLASSES ) break;
            if ( 1 === preg_match('/^[A-Za-z_][A-Za-z0-9_-]{0,79}$/D', $class) ) $classes[] = $class;
        }
        if ( array() !== $classes ) $presentation['class'] = implode(' ', $classes);

        if ( 'fieldset' === $tag ) {
            $semantics = 'plain_group';
            foreach ( $element->childNodes as $child ) {
                if ( $child instanceof DOMElement && 'legend' === strtolower($child->tagName) ) {
                    $semantics = 'labelled_group';
                    break;
                }
            }
            if ( $element->hasAttribute('disabled') ) {
                $semantics = 'disabled_group';
            } elseif ( '' !== trim($this->attr($element, 'name')) || '' !== trim($this->attr($element, 'form')) ) {
                $semantics = 'attributed_group';
            }
            $presentation['fieldset_semantics'] = $semantics;
        }

        return $presentation;
    }

    /**
     * @return array<string, mixed>
     */
    private function formMetadata(DOMElement $form): array
    {
        $metadata = array_filter(
            array(
                'id'         => $this->attr($form, 'id'),
                'name'       => $this->attr($form, 'name'),
                'class'      => $this->attr($form, 'class'),
                'aria_label' => $this->attr($form, 'aria-label'),
                'action'     => $this->attr($form, 'action'),
                'method'     => strtolower($this->attr($form, 'method')),
                'enctype'    => $this->attr($form, 'enctype'),
                'target'     => $this->attr($form, 'target'),
                'autocomplete' => $this->attr($form, 'autocomplete'),
            ),
            static fn (string $value): bool => '' !== $value
        );

        foreach ( array( 'novalidate' ) as $attribute ) {
            if ( $form->hasAttribute($attribute) ) {
                $metadata[$attribute] = true;
            }
        }

        return $metadata;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function searchBlockFromForm(DOMElement $form): ?array
    {
        $method = strtolower(trim($this->attr($form, 'method')));
        if ( '' !== $method && 'get' !== $method ) {
            return null;
        }

        if ( 0 < $form->getElementsByTagName('script')->length || array() !== $this->eventMetadata($form) ) {
            return null;
        }

        $textInput = null;
        $submitControl = null;
        foreach ( $this->formControlElements($form) as $control ) {
            if ( array() !== $this->eventMetadata($control) ) {
                return null;
            }

            $tagName = strtolower($control->tagName);
            $type = $this->formControlType($control);
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

        if ( ! $textInput instanceof DOMElement || ! $this->hasSearchFormSignal($form, $textInput) ) {
            return null;
        }

        $label = $this->formControlLabel($textInput);
        $showLabel = '' !== $label;
        if ( '' === $label ) {
            $label = trim($this->attr($form, 'aria-label'));
        }
        if ( '' === $label ) {
            $label = trim($this->attr($textInput, 'placeholder'));
        }

        $attrs = array_merge($this->styleResolver->presentationAttributes($form), array(
            'label'       => '' !== $label ? $label : 'Search',
            'showLabel'   => $showLabel,
            'placeholder' => $this->attr($textInput, 'placeholder'),
        ));
        if ( $submitControl instanceof DOMElement ) {
            $attrs['buttonPosition'] = 'button-outside';
            $attrs['buttonText'] = $this->submitButtonText($submitControl);
            if ( $this->isIconOnlySearchControl($submitControl) ) {
                $attrs['buttonUseIcon'] = true;
            }
        } elseif ( null !== ($searchTrigger = $this->adjacentSearchTrigger($form)) ) {
            $attrs['buttonPosition'] = 'button-only';
            $attrs['buttonUseIcon'] = true;
            $attrs['style']['color']['text'] = '#000000';
            $attrs['style']['color']['background'] = 'transparent';
            $attrs['style']['border']['width'] = '0px';
            $triggerAttrs = $this->styleResolver->presentationAttributes($searchTrigger);
            $attrs['className'] = trim(implode(' ', array_filter(array(
                (string) ($attrs['className'] ?? ''),
                (string) ($triggerAttrs['className'] ?? ''),
                $this->registerNativeSearchTriggerCss($searchTrigger),
            ))));
        } else {
            $attrs['buttonPosition'] = 'no-button';
        }

        return $this->createBlock('core/search', $attrs, array(), $form);
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

        $svgDeclarations = $this->styleResolver->presentationDeclarations($svg);
        $width = $this->cssPixelLength((string) ($svgDeclarations['width'] ?? '')) ?? $this->cssPixelLength($this->attr($svg, 'width'));
        $height = $this->cssPixelLength((string) ($svgDeclarations['height'] ?? '')) ?? $this->cssPixelLength($this->attr($svg, 'height'));
        if ( null === $width || null === $height ) {
            $viewBox = preg_split('/[\s,]+/', trim($this->attr($svg, 'viewbox'))) ?: array();
            if ( 4 === count($viewBox) && is_numeric($viewBox[2]) && is_numeric($viewBox[3]) ) {
                $width ??= (float) $viewBox[2];
                $height ??= (float) $viewBox[3];
            }
        }
        if ( null === $width || null === $height || 0 >= $width || 0 >= $height ) {
            return '';
        }

        $svgMarkup = $this->restoreSvgCasing($this->outerHtml($svg));
        if ( ! preg_match('/<svg\b[^>]*\bxmlns=/i', $svgMarkup) ) {
            $svgMarkup = preg_replace('/<svg\b/i', '<svg xmlns="http://www.w3.org/2000/svg"', $svgMarkup, 1) ?? $svgMarkup;
        }
        $className = 'blocks-engine-source-search-icon-' . substr(hash('sha256', $svgMarkup), 0, 12);
        if ( $this->generatedSupportStyles()->hasNativeSearchTrigger($className) ) {
            return $className;
        }

        $declarations = $this->styleResolver->presentationDeclarations($trigger);
        $triggerHeight = isset($declarations['height']) && '' !== trim($declarations['height'])
            ? 'height:' . trim($declarations['height']) . '!important;'
            : '';
        $triggerWidth = $this->cssPixelLength((string) ($declarations['width'] ?? ''));
        $iconWidth = $this->cssNumber($width);
        $iconHeight = $this->cssNumber($height);
        $buttonWidth = $this->cssNumber($triggerWidth ?? ($width + 12));
        $dataUri = 'data:image/svg+xml,' . rawurlencode($svgMarkup);
        $selector = '.wp-block-search.' . $className;
        $this->generatedSupportStyles()->registerNativeSearchTrigger($className, $selector . '{display:block!important;box-sizing:border-box!important;flex:0 0 ' . $buttonWidth . 'px!important;width:' . $buttonWidth . 'px!important;' . $triggerHeight . '}'
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

    /**
     * @return array<string, mixed>|null
     */
    private function searchBlockFromWrapper(DOMElement $element): ?array
    {
        if ( 1 !== $this->childElementCount($element) ) {
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

    private function isReplacedSearchClusterControl(DOMElement $control): bool
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
            $this->attr($control, 'class'),
            $this->attr($control, 'id'),
            $this->attr($control, 'aria-label'),
            $this->attr($control, 'title'),
        )));
        return str_contains($haystack, 'search') && str_contains($haystack, 'close');
    }

    private function isNativeSearchForm(DOMElement $form): bool
    {
        $method = strtolower(trim($this->attr($form, 'method')));
        if ( '' !== $method && 'get' !== $method ) {
            return false;
        }
        if ( 0 < $form->getElementsByTagName('script')->length || array() !== $this->eventMetadata($form) ) {
            return false;
        }

        $textInput = null;
        $submitControl = null;
        foreach ( $this->formControlElements($form) as $control ) {
            if ( array() !== $this->eventMetadata($control) ) {
                return false;
            }
            $tagName = strtolower($control->tagName);
            $type = $this->formControlType($control);
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

        return $textInput instanceof DOMElement && $this->hasSearchFormSignal($form, $textInput);
    }

    private function isIconOnlySearchControl(DOMElement $control): bool
    {
        $haystack = strtolower(implode(' ', array(
            $this->attr($control, 'class'),
            $this->attr($control, 'id'),
            $this->attr($control, 'aria-label'),
            $this->attr($control, 'title'),
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

        $identity = strtolower(trim($this->attr($control, 'class') . ' ' . $this->attr($control, 'id')));
        foreach ( preg_split('/\s+/', $identity) ?: array() as $token ) {
            if ( in_array($token, array( 'search-icon', 'search-toggle', 'search-trigger', 'open-search' ), true) ) {
                return true;
            }
        }

        $accessibleName = strtolower(trim($this->attr($control, 'aria-label') . ' ' . $this->attr($control, 'title')));
        return in_array($accessibleName, array( 'search', 'open search', 'expand search', 'toggle search' ), true);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function searchBlockFromStandaloneControl(DOMElement $element): ?array
    {
        if ( 0 < $element->getElementsByTagName('form')->length || 0 < $element->getElementsByTagName('script')->length || array() !== $this->eventMetadata($element) || $this->runtimeIslands->isRuntimeDomTarget($element) ) {
            return null;
        }

        $inputs = array();
        foreach ( $element->getElementsByTagName('input') as $input ) {
            if ( $input instanceof DOMElement && $input->parentNode === $element && 'search' === $this->formControlType($input) ) {
                $inputs[] = $input;
            }
        }
        if ( 1 !== count($inputs) || array() !== $this->eventMetadata($inputs[0]) || $this->runtimeIslands->isRuntimeDomTarget($inputs[0]) ) {
            return null;
        }
        $controls = $this->formControlElements($element);
        if ( 1 !== count($controls) ) {
            return null;
        }

        $searchInput = $inputs[0];
        if ( ! $this->hasStandaloneSearchSignal($element, $searchInput) ) {
            return null;
        }

        $label = $this->formControlLabel($searchInput);
        if ( '' === $label ) {
            $label = $this->attr($searchInput, 'aria-label');
        }
        if ( '' === $label ) {
            $label = $this->attr($searchInput, 'placeholder');
        }

        if ( '' !== $this->attr($searchInput, 'id') || 's' !== $this->attr($searchInput, 'name') ) {
            return $this->htmlPreservationBlock($element);
        }
        if ( 1 !== $this->childElementCount($element) ) {
            return null;
        }

        $placeholder = $this->attr($searchInput, 'placeholder');
        return $this->createBlock('core/search', array_merge($this->styleResolver->presentationAttributes($element), array(
            'label'          => '' !== $label ? $label : 'Search',
            'showLabel'      => false,
            'placeholder'    => $placeholder,
            'buttonPosition' => 'no-button',
        )), array(), $element);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function readableFormBlockFromForm(DOMElement $form, bool $allowFormEvents = false): ?array
    {
        if ( 0 < $form->getElementsByTagName('script')->length || ( ! $allowFormEvents && array() !== $this->eventMetadata($form) ) ) {
            return null;
        }

        $contentBlocks = array();
        $buttonBlocks = array();
        foreach ( $this->formControlElements($form) as $control ) {
            if ( array() !== $this->eventMetadata($control) || ! $this->isReadableFormControl($control) ) {
                return null;
            }

            if ( $this->isSubmitLikeControl($control) ) {
                $buttonBlocks[] = $this->createBlock('core/button', array_merge($this->styleResolver->presentationAttributes($control), array(
                    'text' => $this->runtime->escapeHtml($this->readableSubmitText($control)),
                )), array(), $control);
                continue;
            }

            if ( $this->runtimeIslands->isRuntimeDomTarget($control) ) {
                $this->runtimeIslands->recordRuntimeIsland($control, 'control', 'runtime_dom_target', 'client_script_execution', array(
                    'control'          => $this->formControlMetadata($control),
                    'events'           => $this->eventMetadata($control),
                    'required_scripts' => $this->requiredScriptsForElement($control),
                ));
            }

            $readableControlBlock = $this->readableFormControlBlockFromElement($control);
            if ( null === $readableControlBlock ) {
                continue;
            }

            $fieldBlocks = array();
            $associatedLabel = $this->associatedLabelElement($control);
            if ( $associatedLabel instanceof DOMElement && AuthoredInputBlockGenerator::NAME === ($readableControlBlock['blockName'] ?? '') ) {
                $labelBlock = $this->readableFormControlBlockFromElement($associatedLabel);
                if ( null !== $labelBlock ) {
                    $fieldBlocks[] = $labelBlock;
                }
            }
            $fieldBlocks[] = $readableControlBlock;
            $contentBlocks[] = ( 1 === count($fieldBlocks) && AuthoredInputBlockGenerator::NAME !== ($readableControlBlock['blockName'] ?? '') )
                ? $fieldBlocks[0]
                : $this->createBlock('core/group', array(), $fieldBlocks, $control);
        }

        if ( array() !== $buttonBlocks ) {
            $contentBlocks[] = $this->createBlock('core/buttons', array(), $buttonBlocks, $form);
        }

        if ( array() === $contentBlocks ) {
            return null;
        }

        return $this->createBlock('core/group', $this->styleResolver->presentationAttributes($form), $contentBlocks, $form);
    }

    /**
     * Preserve one unambiguous controls-only subtree as the provider binding
     * slot while converting the form's surrounding visual content normally.
     *
     * @param array<int,array<string,mixed>> $fallbacks
     * @return array{block:array<string,mixed>,slot:array<string,mixed>}|null
     */
    private function compositionalFormBlock(DOMElement $form, array &$fallbacks): ?array
    {
        $slot = $this->formControlSlotElement($form);
        if ( null === $slot ) return null;

        $path = $slot->getNodePath();
        $token = $this->transformationProvenance()->reserveFormControlSlot($path);
        try {
            $children = $this->convertChildren($form, $fallbacks, true);
        } finally {
            $this->transformationProvenance()->releaseFormControlSlot($path);
        }
        $slotBlock = $this->blockForBindingToken($children, $token);
        if ( array() === $children || null === $slotBlock ) return null;

        return array(
            'block' => $this->createBlock('core/group', $this->styleResolver->presentationAttributes($form), $children, $form),
            'slot'  => $slotBlock,
        );
    }

    /** @param array<int,array<string,mixed>> $blocks @return array<string,mixed>|null */
    private function blockForBindingToken(array $blocks, string $token): ?array
    {
        foreach ($blocks as $block) {
            if (!is_array($block)) continue;
            if ($token === ($block['_binding_token'] ?? null)) return $block;
            $nested = $this->blockForBindingToken(is_array($block['innerBlocks'] ?? null) ? $block['innerBlocks'] : array(), $token);
            if (null !== $nested) return $nested;
        }
        return null;
    }

    private function formControlSlotElement(DOMElement $form): ?DOMElement
    {
        $controls = $this->formControlElements($form);
        if ( array() === $controls ) return null;

        $formPath = $form->getNodePath();
        for ( $candidate = $controls[0]->parentNode; $candidate instanceof DOMElement && $candidate->getNodePath() !== $formPath; $candidate = $candidate->parentNode ) {
            if ( array_filter($controls, fn(DOMElement $control): bool => !$this->elementContains($candidate, $control)) ) continue;
            foreach ( $candidate->childNodes as $child ) {
                if ( XML_TEXT_NODE === $child->nodeType && '' !== trim($child->textContent ?? '') ) continue 2;
                if ( !$child instanceof DOMElement ) continue;
                if ( !array_filter($controls, fn(DOMElement $control): bool => $this->elementContains($child, $control)) ) continue 2;
            }
            return $candidate;
        }
        return null;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function readableFormControlBlockFromElement(DOMElement $element): ?array
    {
        $tagName = strtolower($element->tagName);
        if ( 'label' === $tagName ) {
            $controls = $this->formControlElements($element);
            if ( array() !== $controls ) {
                $blocks = array();
                foreach ( $controls as $control ) {
                    if ( ! $this->isReadableFormControl($control) || array() !== $this->eventMetadata($control) ) {
                        return null;
                    }

                    if ( $this->runtimeIslands->isRuntimeDomTarget($control) ) {
                        $this->recordRuntimeControlIsland($control);
                        return $this->htmlPreservationBlock($element);
                    }

                    $summary = $this->readableFormControlText($control);
                    if ( '' !== $summary ) {
                        $blocks[] = $this->createBlock('core/paragraph', array( 'content' => $summary ), array(), $control);
                    }
                }

                if ( 1 === count($blocks) ) {
                    return $blocks[0];
                }

                return array() !== $blocks ? $this->createBlock('core/group', $this->styleResolver->presentationAttributes($element), $blocks, $element) : null;
            }

            $label = $this->normalizedControlLabelText($element);
            if ( '' === $label ) {
                $label = trim(preg_replace('/\s+/', ' ', $element->textContent ?? '') ?? '');
            }

            return '' !== $label ? $this->createBlock('core/paragraph', array( 'content' => $this->runtime->escapeHtml($label) ), array(), $element) : null;
        }

        if ( ! $this->isFormControlElement($element) || ! $this->isReadableFormControl($element) || array() !== $this->eventMetadata($element) ) {
            return null;
        }

        if ( 'input' === $tagName && 'search' === $this->formControlType($element) ) {
            $label = $this->formControlLabel($element);
            if ( '' === $label ) {
                $label = $this->attr($element, 'aria-label');
            }
            if ( '' === $label ) {
                $label = 'Search';
            }

            return $this->htmlPreservationBlock($element);
        }

        if ( $this->runtimeIslands->isRuntimeDomTarget($element) ) {
            $this->recordRuntimeControlIsland($element);
            return $this->htmlPreservationBlock($element);
        }

        if ( 'select' === $tagName ) {
            $selectBlock = $this->readableSelectBlockFromElement($element);
            if ( null !== $selectBlock ) {
                return $selectBlock;
            }
        }

        if ( 'input' === $tagName ) {
            $inputBlock = $this->readableInputBlockFromElement($element);
            if ( null !== $inputBlock ) {
                return $inputBlock;
            }
        }

        $summary = $this->readableFormControlText($element);
        if ( '' === $summary ) {
            return null;
        }

        return $this->createBlock('core/paragraph', array_merge($this->styleResolver->presentationAttributes($element), array( 'content' => $summary )), array(), $element);
    }

    private function recordRuntimeControlIsland(DOMElement $element): void
    {
        $this->runtimeIslands->recordRuntimeIsland($element, 'control', 'runtime_dom_target', 'client_script_execution', array(
            'control'          => $this->formControlMetadata($element),
            'events'           => $this->eventMetadata($element),
            'required_scripts' => $this->requiredScriptsForElement($element),
        ));
    }

    /**
     * Preserve a standalone form control that has no faithful native block or
     * readable static approximation as a bounded runtime island instead of an
     * unsupported-element loss.
     *
     * Reached only after the readable-control and search paths decline, so the
     * control is one whose behavior depends on a client runtime: file/hidden/
     * color/date-style inputs core blocks cannot represent, or any control
     * carrying inline event handlers. The source markup is carried in the
     * island snippet so the behavior can be re-attached, and no misleading
     * static text is emitted for controls (often hidden) that have no visual
     * representation. This yields a `preserved_runtime_island` outcome rather
     * than an `unsupported_element_loss`.
     */
    private function preserveStandaloneFormControlAsRuntimeIsland(DOMElement $element): bool
    {
        $tagName = strtolower($element->tagName);
        if ( ! in_array($tagName, array( 'input', 'select', 'textarea' ), true) ) {
            return false;
        }

        $this->runtimeIslands->recordRuntimeIsland($element, 'control', 'form_control_requires_runtime', 'client_form_control_runtime', array(
            'control'          => $this->formControlMetadata($element),
            'events'           => $this->eventMetadata($element),
            'required_scripts' => $this->requiredScriptsForElement($element),
        ));

        return true;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function readableSelectBlockFromElement(DOMElement $select): ?array
    {
        $label = $this->readableFormControlLabel($select);
        $this->registerFormControlEcho($label);
        $options = $this->selectOptions($select);
        if ( array() === $options ) {
            return null;
        }
        // Form controls are below the general high-value style boundary, so use
        // the selector-resolved author cascade directly as the representation
        // gate. Class/id presence alone is never sufficient.
        if ( array() === $this->styleResolver->structuralPresentationDeclarations($select) ) {
            $optionBlocks = array();
            foreach ( $options as $option ) {
                $optionLabel = trim((string) ($option['label'] ?? ''));
                if ( '' === $optionLabel ) {
                    continue;
                }
                if ( true === ($option['selected'] ?? false) ) {
                    $optionLabel .= ' (selected)';
                }
                $this->registerFormControlEcho($optionLabel);
                $optionBlocks[] = $this->createBlock('core/list-item', array( 'content' => $this->runtime->escapeHtml($optionLabel) ));
            }

            return $this->createBlock('core/group', $this->styleResolver->presentationAttributes($select), array(
                $this->createBlock('core/paragraph', array( 'content' => $this->runtime->escapeHtml($label) ), array(), $select),
                $this->createBlock('core/list', array(), $optionBlocks, $select),
            ), $select);
        }
        $this->generatedBlocks()->register(AuthoredSelectBlockGenerator::class, ( new AuthoredSelectBlockGenerator() )->definition());
        $attrs = array_filter(array(
            'id' => $this->attr($select, 'id'),
            'name' => $this->attr($select, 'name'),
            'ariaLabel' => $this->attr($select, 'aria-label'),
            'placeholder' => $this->attr($select, 'placeholder'),
            'className' => $this->attr($select, 'class'),
            'style' => $this->attr($select, 'style'),
            'options' => $options,
            'selectedSummary' => $this->selectedOptionSummary($options),
        ), static fn (mixed $value): bool => is_array($value) ? array() !== $value : '' !== $value);
        $markup = ( new AuthoredSelectBlockGenerator() )->markup($attrs);
        $controlBlock = array(
            'blockName' => AuthoredSelectBlockGenerator::NAME,
            'attrs' => $attrs,
            'innerBlocks' => array(),
            'innerHTML' => $markup,
            'innerContent' => array( $markup ),
        );

        // Keep the long-standing group/anchor contract for callers that address
        // the converted field structurally. Source identity lives on the native
        // control, so authored select selectors never style this transparent shell.
        return $this->createBlock('core/group', array_filter(array(
            'anchor' => $this->safeAnchor($this->attr($select, 'id')),
            'className' => 'blocks-engine-authored-select-wrapper',
        )), array( $controlBlock ));
    }

    /**
     * Return a compact native input only when authored presentation is proven by
     * the resolved CSS cascade. The direct save shape preserves flex-child and
     * selector semantics that a readable paragraph cannot represent.
     *
     * @return array<string, mixed>|null
     */
    private function readableInputBlockFromElement(DOMElement $input): ?array
    {
        if ( array() === $this->styleResolver->structuralPresentationDeclarations($input) ) {
            return null;
        }
        $this->generatedBlocks()->register(AuthoredInputBlockGenerator::class, ( new AuthoredInputBlockGenerator() )->definition());
        $attrs = array_filter(array(
            'type' => $this->formControlType($input),
            'id' => $this->attr($input, 'id'),
            'name' => $this->attr($input, 'name'),
            'value' => $this->attr($input, 'value'),
            'placeholder' => $this->attr($input, 'placeholder'),
            'ariaLabel' => $this->attr($input, 'aria-label'),
            'className' => $this->attr($input, 'class'),
            'style' => $this->attr($input, 'style'),
            'min' => $this->attr($input, 'min'),
            'max' => $this->attr($input, 'max'),
            'step' => $this->attr($input, 'step'),
            'required' => $input->hasAttribute('required'),
            'disabled' => $input->hasAttribute('disabled'),
            'readOnly' => $input->hasAttribute('readonly'),
            'checked' => $input->hasAttribute('checked'),
        ), static fn (mixed $value): bool => is_bool($value) ? $value : '' !== $value);
        $markup = ( new AuthoredInputBlockGenerator() )->markup($attrs);

        return array(
            'blockName' => AuthoredInputBlockGenerator::NAME,
            'attrs' => $attrs,
            'innerBlocks' => array(),
            'innerHTML' => $markup,
            'innerContent' => array( $markup ),
        );
    }

    /**
     * @param array<int, array<string, mixed>> $options
     */
    private function selectedOptionSummary(array $options): string
    {
        $selected = array();
        foreach ( $options as $option ) {
            if ( ! empty($option['selected']) && '' !== trim((string) ($option['label'] ?? '')) ) {
                $selected[] = (string) $option['label'];
            }
        }

        return array() === $selected ? '' : implode(', ', $selected) . ' (selected)';
    }

    private function formRequiresRuntimePreservation(DOMElement $form): bool
    {
        return 0 < $form->getElementsByTagName('script')->length
            || array() !== $this->eventMetadata($form)
            || $this->formHasRuntimeSubmissionMetadata($form)
            || $this->formHasCommerceSubmissionSignal($form)
            || $this->formHasRuntimeDomTargets($form);
    }

    private function formHasRuntimeSubmissionMetadata(DOMElement $form): bool
    {
        $action = trim($this->attr($form, 'action'));
        if ( '' !== $action && '#' !== $action ) {
            return true;
        }

        if ( '' === $action && '' !== trim($this->attr($form, 'method')) ) {
            return true;
        }

        foreach ( array( 'enctype', 'target' ) as $attribute ) {
            if ( '' !== trim($this->attr($form, $attribute)) ) {
                return true;
            }
        }

        return false;
    }

    private function formHasCommerceSubmissionSignal(DOMElement $form): bool
    {
        foreach ( $this->formControlElements($form) as $control ) {
            if ( ! $this->isSubmitLikeControl($control) ) {
                continue;
            }

            $haystack = strtolower(implode(' ', array(
                $control->textContent ?? '',
                $this->attr($control, 'value'),
                $this->attr($control, 'class'),
                $this->attr($control, 'id'),
                $this->attr($control, 'name'),
                $this->attr($control, 'aria-label'),
                $this->attr($control, 'title'),
            )));

            if ( preg_match('/(?:^|[^a-z0-9])(?:add to cart|cart|checkout|payment|purchase|buy|order|register|registration|ticket)(?:[^a-z0-9]|$)/', $haystack) ) {
                return true;
            }
        }

        return false;
    }

    private function formHasRuntimeDomTargets(DOMElement $form): bool
    {
        if ( $this->runtimeIslands->isRuntimeDomTarget($form) || $this->hasRuntimeClassSignal($form) ) {
            return true;
        }

        foreach ( $this->formControlElements($form) as $control ) {
            if ( $this->runtimeIslands->isRuntimeDomTarget($control) || $this->hasRuntimeClassSignal($control) ) {
                return true;
            }
        }

        return false;
    }

    private function hasRuntimeClassSignal(DOMElement $element): bool
    {
        foreach ( preg_split('/\s+/', trim($this->attr($element, 'class'))) ?: array() as $class ) {
            if ( preg_match('/^js-[A-Za-z0-9_-]+$/', $class) ) {
                return true;
            }
        }

        return false;
    }

    /**
     * Build the shared html_form_fallback finding (issue #315) for an element that
     * behaves as a form. Both the real <form> path and the div-based pseudo-form
     * path emit through here so the downstream materializer receives an identical
     * shape (controls, form metadata, classification, bounded HTML) regardless of
     * whether the source markup used a <form> element.
     *
     * @param array<string, mixed>|null $readableFormBlock
     * @return array<string, mixed>
     */
    private function formFallbackFinding(DOMElement $element, ?array $readableFormBlock, ?array $bindingBlock = null): array
    {
        $controls = $this->formControls($element);
        $controlTopology = $this->formControlTopology($element);
        $layoutGraph = (new FormLayoutGraphBuilder())->build($element, $this->authorStyles()->stylesheetAssets(), $this->sourceStyles()->formLayoutCss());
        $boundedHtml = $this->boundedFallbackHtml($this->safeFallbackHtml($element));
        $replacesRuntimeIsland = null !== $bindingBlock;
        $bindingBlock ??= $readableFormBlock;
        $supersededRuntimeSelectors = $this->runtimeIslands->runtimeDomSelectorsForElement($element);
        if ( $replacesRuntimeIsland ) $supersededRuntimeSelectors[] = $this->runtimeIslandSelector($element);

        $finding = array(
            'type'            => 'html',
            'reason'          => 'form_requires_runtime',
            'diagnostic_code' => 'html_form_fallback',
            'message'         => 'Form intent and controls were extracted as provider-materializable metadata; the source form markup is preserved until a form provider materializes it.',
            'source_format'   => 'html',
            'tag'             => strtolower($element->tagName),
            'selector'        => $this->elementSelector($element),
            'attributes'      => $this->htmlAttributes($element),
            'form'            => $this->formMetadata($element),
            'success_panel'   => $this->formSuccessPanelMetadata($element),
            'context'         => $this->sourceContext($element),
            'classification'  => $this->fallbackEmitter()->classifyFallbackSubtree($element),
            'events'          => $this->eventMetadata($element),
            'readable_blocks' => null !== $readableFormBlock ? array( $readableFormBlock ) : array(),
            'binding'         => null !== $bindingBlock ? $this->blockBinding($bindingBlock, 'form', $supersededRuntimeSelectors) : array(),
            'controls'        => $controls,
            'control_topology' => $controlTopology,
            'layout_graph'     => $layoutGraph,
            'control_count'   => count($controls),
            'text_length'     => strlen(trim($element->textContent ?? '')),
            'child_count'     => $this->childElementCount($element),
            'html'            => $boundedHtml['html'],
            'html_bytes'      => $boundedHtml['bytes'],
            'html_truncated'  => $boundedHtml['truncated'],
        );
        if ( 'form' !== strtolower($element->tagName) ) {
            $finding['form_boundary'] = $this->pseudoFormBoundaryMetadata($element);
        }

        return FallbackDiagnostic::build($finding, $this->transformationProvenance()->fallback());
    }

    /**
     * @return array<string, mixed>
     */
    private function formSuccessPanelMetadata(DOMElement $form): array
    {
        if ( 'form' !== strtolower($form->tagName) ) {
            foreach ( $this->descendantElements($form) as $descendant ) {
                if ( $this->hasSuccessPanelSignal($descendant) ) {
                    return $this->successPanelMetadata($descendant);
                }
            }

            return array();
        }

        for ( $sibling = $form->nextSibling; $sibling instanceof DOMNode; $sibling = $sibling->nextSibling ) {
            if ( XML_TEXT_NODE === $sibling->nodeType && '' === trim($sibling->textContent ?? '') ) {
                continue;
            }

            if ( ! $sibling instanceof DOMElement ) {
                return array();
            }

            if ( ! $this->hasSuccessPanelSignal($sibling) ) {
                return array();
            }

            return $this->successPanelMetadata($sibling);
        }

        return array();
    }

    /** @return array<string, mixed> */
    private function successPanelMetadata(DOMElement $element): array
    {
        $boundedHtml = $this->boundedFallbackHtml($this->safeFallbackHtml($element));
        return array_filter(array(
            'selector'       => $this->elementSelector($element),
            'id'             => $this->attr($element, 'id'),
            'class'          => $this->attr($element, 'class'),
            'role'           => $this->attr($element, 'role'),
            'aria_live'      => $this->attr($element, 'aria-live'),
            'text'           => $this->normalizedSuccessPanelText($element),
            'html'           => $boundedHtml['html'],
            'html_bytes'     => $boundedHtml['bytes'],
            'html_truncated' => $boundedHtml['truncated'],
        ), static fn (mixed $value): bool => is_bool($value) || is_int($value) || '' !== trim((string) $value));
    }

    private function normalizedSuccessPanelText(DOMElement $element): string
    {
        $html = preg_replace('/<\/?[a-z][a-z0-9]*\b[^>]*>/i', ' ', $this->innerHtml($element)) ?? $element->textContent ?? '';
        return trim(preg_replace('/\s+/', ' ', html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8')) ?? '');
    }

    private function hasSuccessPanelSignal(DOMElement $element): bool
    {
        $role = strtolower($this->attr($element, 'role'));
        if ( in_array($role, array( 'status', 'alert' ), true) ) {
            return true;
        }

        $tokens = strtolower(trim($this->attr($element, 'id') . ' ' . $this->attr($element, 'class') . ' ' . $this->attr($element, 'aria-live')));
        return (bool) preg_match('/(?:^|[^a-z0-9])(?:success|sent|submitted|thank|thanks|confirmation|confirmed)(?:[^a-z0-9]|$)/', $tokens);
    }

    /**
     * Whether a non-<form> container behaves as a form: it is the tightest
     * container that pairs at least one data-entry control with a submit-like
     * control, and no real <form> owns the subtree.
     *
     * Structural only — the signal is "data-entry control + submit-like control in
     * one bounded container", never a fixture id/class/name. Conservative: a lone
     * search box or a stray input with no submit control never qualifies, and a
     * subtree owned by a real <form> (as ancestor or descendant) is left to the
     * <form> path so the finding is emitted exactly once.
     */
    private function isDivBasedPseudoForm(DOMElement $element): bool
    {
        if ( 'form' === strtolower($element->tagName) ) {
            return false;
        }

        // A real <form> ancestor or descendant owns the controls; let the <form>
        // path emit the finding so it is never double-counted.
        if ( $this->hasFormAncestor($element) ) {
            return false;
        }
        if ( 0 < $element->getElementsByTagName('form')->length ) {
            return false;
        }

        // A pseudo-form must be a local interaction region, never the page shell
        // that happens to contain navigation or editorial content plus controls.
        if ( $this->pseudoFormContainsUnrelatedLandmark($element) ) {
            return false;
        }

        if ( ! $this->containerPairsDataEntryWithSubmit($element) ) {
            return false;
        }

        // Bound the container to the tightest one: if a descendant container also
        // pairs the controls, defer to it so a wrapper does not swallow a nested
        // pseudo-form (and sibling pseudo-forms each emit their own finding).
        foreach ( $element->getElementsByTagName('*') as $descendant ) {
            if ( $descendant instanceof DOMElement
                && ! $this->isFormControlElement($descendant)
                && $this->containerPairsDataEntryWithSubmit($descendant) ) {
                return false;
            }
        }

        return true;
    }

    /**
     * Whether a container holds a local, labeled data-entry control and a submit
     * action. Unlike a real form, a div gives a plain button no submit ownership,
     * so its action must be explicit in type or semantics.
     */
    private function containerPairsDataEntryWithSubmit(DOMElement $element): bool
    {
        $hasDataEntry = false;
        $hasFieldLabel = false;
        $hasSubmit = false;
        $hasActionControl = false;
        $hasContainerAction = '' !== trim($this->attr($element, 'action')) || '' !== trim($this->attr($element, 'method')) || '' !== trim($this->attr($element, 'data-action'));

        foreach ( $this->formControlElements($element) as $control ) {
            if ( $this->isPseudoFormDataEntryControl($control) && ! $this->hasStandaloneSearchSignal($element, $control) ) {
                $hasDataEntry = true;
                $hasFieldLabel = $hasFieldLabel || '' !== trim($this->formControlLabel($control)) || '' !== trim($this->attr($control, 'aria-label')) || '' !== trim($this->attr($control, 'name'));
            } elseif ( 'button' === strtolower($control->tagName) || ( 'input' === strtolower($control->tagName) && ! in_array($this->formControlType($control), array( 'reset', 'button' ), true) ) ) {
                $hasActionControl = true;
                $hasSubmit = $hasSubmit || $this->isPseudoFormSubmitControl($control);
            }

            if ( $hasDataEntry && $hasFieldLabel && ( $hasSubmit || ( $hasContainerAction && $hasActionControl ) ) ) {
                return true;
            }
        }

        return false;
    }

    private function isPseudoFormSubmitControl(DOMElement $control): bool
    {
        $type = $this->formControlType($control);
        if ( in_array($type, array( 'submit', 'image' ), true) ) {
            return true;
        }

        return $this->hasSubmitSemantics($control);
    }

    private function pseudoFormContainsUnrelatedLandmark(DOMElement $element): bool
    {
        foreach ( $this->descendantElements($element) as $descendant ) {
            $tagName = strtolower($descendant->tagName);
            $role = strtolower($this->attr($descendant, 'role'));
            if ( in_array($tagName, array( 'article', 'nav', 'header', 'footer', 'main' ), true)
                || in_array($role, array( 'article', 'navigation', 'banner', 'contentinfo', 'main' ), true) ) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<string, mixed>
     */
    private function pseudoFormBoundaryMetadata(DOMElement $element): array
    {
        $rejectedAncestors = array();
        for ( $ancestor = $element->parentNode; $ancestor instanceof DOMElement && count($rejectedAncestors) < 4; $ancestor = $ancestor->parentNode ) {
            if ( ! $this->pseudoFormContainsUnrelatedLandmark($ancestor) && ! $this->containerPairsDataEntryWithSubmit($ancestor) ) {
                continue;
            }
            $rejectedAncestors[] = array(
                'selector' => $this->elementSelector($ancestor),
                'reason'   => $this->pseudoFormContainsUnrelatedLandmark($ancestor) ? 'contains_unrelated_landmark' : 'contains_nested_coherent_form',
            );
        }

        return array(
            'schema' => 'generic/form-boundary/v1',
            'selector' => $this->elementSelector($element),
            'selection_basis' => array( 'local_controls', 'associated_label', 'submit_semantics' ),
            'rejected_ancestors' => $rejectedAncestors,
        );
    }

    /**
     * A data-entry control that anchors a pseudo-form. Reuses #315's
     * isDataEntryControl and additionally excludes search inputs, which already
     * have dedicated standalone-search handling and should not be promoted into a
     * form fallback.
     */
    private function isPseudoFormDataEntryControl(DOMElement $control): bool
    {
        return $this->isDataEntryControl($control) && 'search' !== $this->formControlType($control);
    }

    /**
     * Whether a control submits a form: an explicit submit/image control, or a
     * button/input whose text/value/type/class/id/name/aria carries submit,
     * subscribe, sign-up, or send semantics. A plain <button> defaults to type
     * "submit" and qualifies directly; a type="reset" control never does.
     */
    private function isSubmitLikeControl(DOMElement $control): bool
    {
        $tagName = strtolower($control->tagName);
        if ( 'button' !== $tagName && 'input' !== $tagName ) {
            return false;
        }

        $type = $this->formControlType($control);
        if ( in_array($type, array( 'submit', 'image' ), true) ) {
            return true;
        }
        if ( 'reset' === $type ) {
            return false;
        }

        // Only generic clickable controls (button-typed) fall through to the
        // semantic check; data-entry input types are never submit controls.
        if ( 'input' === $tagName && 'button' !== $type ) {
            return false;
        }

        return $this->hasSubmitSemantics($control) || $this->isSoleFormActionControl($control);
    }

    /**
     * The only non-reset button-like control in the enclosing form is the form's
     * action, even when its type is `button` and its label is not a submit verb.
     */
    private function isSoleFormActionControl(DOMElement $control): bool
    {
        $form = null;
        for ( $parent = $control->parentNode; $parent instanceof DOMElement; $parent = $parent->parentNode ) {
            if ( 'form' === strtolower($parent->tagName) ) {
                $form = $parent;
                break;
            }
        }
        if ( ! $form instanceof DOMElement ) {
            return false;
        }

        $actions = 0;
        foreach ( $this->formControlElements($form) as $candidate ) {
            $tagName = strtolower($candidate->tagName);
            $type = $this->formControlType($candidate);
            if ( 'reset' === $type ) {
                continue;
            }
            if ( 'button' === $tagName || ( 'input' === $tagName && in_array($type, array( 'submit', 'image', 'button' ), true) ) ) {
                ++$actions;
                if ( 1 < $actions ) {
                    return false;
                }
            }
        }

        return 1 === $actions;
    }

    /**
     * Whether a control's text/attributes carry submit-like intent. Structural
     * vocabulary only — no fixture-specific identifiers.
     */
    private function hasSubmitSemantics(DOMElement $control): bool
    {
        $haystack = strtolower(implode(' ', array(
            $control->textContent ?? '',
            $this->attr($control, 'value'),
            $this->attr($control, 'class'),
            $this->attr($control, 'id'),
            $this->attr($control, 'name'),
            $this->attr($control, 'aria-label'),
            $this->attr($control, 'data-hook'),
            $this->attr($control, 'data-field-type'),
            $this->attr($control, 'data-testid'),
        )));

        foreach ( array( 'submit', 'subscribe', 'sign up', 'sign-up', 'signup', 'send' ) as $needle ) {
            if ( str_contains($haystack, $needle) ) {
                return true;
            }
        }

        return false;
    }

    private function hasFormAncestor(DOMElement $element): bool
    {
        for ( $parent = $element->parentNode; $parent instanceof DOMElement; $parent = $parent->parentNode ) {
            if ( 'form' === strtolower($parent->tagName) ) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether a form collects user input through at least one data-entry control.
     *
     * A <form> that gathers data (text/email/select/textarea and similar) needs a
     * real form runtime to submit, validate, and notify — even when it declares no
     * action/method/script/event handler (common in static exports and design
     * mockups where submission is wired downstream). Such a form must be preserved
     * as a runtime island carrying its control structure rather than flattened to
     * readable prose, so a consumer can materialize it into a working form. Keying
     * off the control structure keeps this generic: no provider, plugin, or site
     * knowledge leaks into the transformer.
     */
    private function formHasDataEntryControls(DOMElement $form): bool
    {
        foreach ( $this->formControlElements($form) as $control ) {
            if ( $this->isDataEntryControl($control) ) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether a control collects user input (as opposed to a submit/reset/button,
     * hidden state, file upload, or image button).
     *
     * The excluded set mirrors the controls a form provider cannot map to a data
     * field, so a form whose only controls are non-data-entry stays a readable
     * fallback instead of becoming an empty preserved island.
     */
    private function isDataEntryControl(DOMElement $control): bool
    {
        return FormControlClassifier::isDataEntryControl($control);
    }

    private function isReadableFormControl(DOMElement $control): bool
    {
        $tagName = strtolower($control->tagName);
        if ( in_array($tagName, array( 'select', 'textarea' ), true) ) {
            return true;
        }

        return 'button' === $tagName || ( 'input' === $tagName && in_array($this->formControlType($control), array( 'checkbox', 'email', 'number', 'radio', 'range', 'search', 'submit', 'tel', 'text', 'url' ), true) );
    }

    private function readableFormControlText(DOMElement $control): string
    {
        $label = $this->readableFormControlLabel($control);

        $type = $this->formControlType($control);
        if ( '' === $label ) {
            $label = 'select' === $type ? 'Select option' : ucfirst($type);
        }

        $details = array();
        if ( 'select' === strtolower($control->tagName) ) {
            $options = array();
            $selected = array();
            foreach ( $this->selectOptions($control) as $option ) {
                $optionLabel = (string) ($option['label'] ?? '');
                if ( '' === $optionLabel ) {
                    continue;
                }
                $options[] = $optionLabel;
                if ( true === ($option['selected'] ?? false) ) {
                    $selected[] = $optionLabel;
                }
            }
            if ( array() !== $options ) {
                $details[] = implode(', ', $options);
            }
            if ( array() !== $selected ) {
                $details[] = 'selected: ' . implode(', ', $selected);
            }
        } elseif ( 'range' === $type ) {
            $value = trim($this->attr($control, 'value'));
            if ( '' !== $value ) {
                $details[] = $value;
            }

            $bounds = array();
            foreach ( array( 'min', 'max', 'step' ) as $attribute ) {
                $value = trim($this->attr($control, $attribute));
                if ( '' !== $value ) {
                    $bounds[] = $attribute . ' ' . $value;
                }
            }
            if ( array() !== $bounds ) {
                $details[] = implode(', ', $bounds);
            }
        } else {
            foreach ( array( 'value', 'placeholder' ) as $attribute ) {
                $value = trim($this->attr($control, $attribute));
                if ( '' !== $value ) {
                    $details[] = $value;
                    break;
                }
            }
        }

        $text = $label;
        if ( array() !== $details ) {
            $text .= ': ' . implode(' (', $details) . ( count($details) > 1 ? ')' : '' );
        }
        if ( $control->hasAttribute('required') ) {
            $text .= ' (required)';
        }

        $this->registerFormControlEcho($text);

        return $this->runtime->escapeHtml($text);
    }

    /**
     * Record text the transformer synthesizes from a form control (label plus
     * value/placeholder/options/required state) so the content round-trip
     * reporter does not flag it as invented copy — it is intentionally absent
     * from the source's visible content. Harmless if a recorded string never
     * reaches the output: the reporter only ever uses it to suppress an exact
     * match.
     */
    private function registerFormControlEcho(string $text): void
    {
        $this->transformationEvidence()->recordFormControlEcho($text);
    }

    private function readableFormControlLabel(DOMElement $control): string
    {
        $label = $this->formControlLabel($control);
        if ( '' === $label ) {
            $label = $this->attr($control, 'aria-label');
        }
        if ( '' === $label ) {
            $label = $this->attr($control, 'placeholder');
        }
        if ( '' === $label ) {
            $label = $this->attr($control, 'name');
        }

        $type = $this->formControlType($control);
        if ( '' === $label && $this->isSubmitLikeControl($control) ) {
            $label = trim(preg_replace('/\s+/', ' ', $control->textContent ?? '') ?? '');
        }
        if ( '' === $label ) {
            return 'select' === $type ? 'Select option' : ucfirst($type);
        }

        return $label;
    }

    /**
     * Label associated by `for`, not a wrapping parent label. Wrapping labels
     * are converted with the control they contain.
     */
    private function associatedLabelElement(DOMElement $control): ?DOMElement
    {
        $id = $this->attr($control, 'id');
        if ( '' === $id || ! $control->ownerDocument instanceof DOMDocument ) {
            return null;
        }

        foreach ( $control->ownerDocument->getElementsByTagName('label') as $label ) {
            if ( $label instanceof DOMElement && $id === $this->attr($label, 'for') ) {
                return $label;
            }
        }

        return null;
    }

    private function readableSubmitText(DOMElement $control): string
    {
        $text = trim(preg_replace('/\s+/', ' ', $control->textContent ?? '') ?? '');
        if ( '' !== $text ) {
            return $text;
        }

        $value = trim($this->attr($control, 'value'));
        return '' !== $value ? $value : 'Submit';
    }

    /**
     * @return array<int, DOMElement>
     */
    private function formControlElements(DOMElement $form): array
    {
        $controls = array();
        foreach ( $form->getElementsByTagName('*') as $control ) {
            if ( $control instanceof DOMElement && $this->isFormControlElement($control) ) {
                $controls[] = $control;
            }
        }

        return $controls;
    }

    private function hasSearchFormSignal(DOMElement $form, DOMElement $input): bool
    {
        if ( 'search' === $this->formControlType($input) || 'search' === strtolower(trim($this->attr($form, 'role'))) ) {
            return true;
        }

        $queryName = strtolower(trim($this->attr($input, 'name')));
        if ( in_array($queryName, array( 's', 'q', 'query', 'search' ), true) ) {
            return true;
        }

        $haystack = strtolower(implode(' ', array(
            $this->attr($form, 'action'),
            $this->attr($form, 'aria-label'),
            $this->attr($form, 'id'),
            $this->attr($form, 'class'),
        )));

        return str_contains($haystack, 'search');
    }

    private function hasStandaloneSearchSignal(DOMElement $element, DOMElement $input): bool
    {
        if ( 'search' === $this->formControlType($input) || 'search' === strtolower(trim($this->attr($element, 'role'))) ) {
            return true;
        }

        $haystack = strtolower(implode(' ', array(
            $this->attr($element, 'aria-label'),
            $this->attr($element, 'id'),
            $this->attr($element, 'class'),
            $this->attr($input, 'aria-label'),
            $this->attr($input, 'id'),
            $this->attr($input, 'class'),
            $this->attr($input, 'name'),
            $this->attr($input, 'placeholder'),
        )));

        return str_contains($haystack, 'search');
    }

    private function submitButtonText(DOMElement $control): string
    {
        $text = trim(preg_replace('/\s+/', ' ', $control->textContent ?? '') ?? '');
        if ( '' !== $text ) {
            return $text;
        }

        $value = trim($this->attr($control, 'value'));
        return '' !== $value ? $value : 'Search';
    }

    /**
     * @return array<string, mixed>
     */
    private function formControlMetadata(DOMElement $control): array
    {
        if ( ! $this->isFormControlElement($control) ) {
            return array();
        }

        $tagName = strtolower($control->tagName);
        $type = $this->formControlType($control);
        if ( 'button' === $type && $this->isSubmitLikeControl($control) ) {
            $type = 'submit';
        }
        $metadata = array_filter(array(
            'tag'         => $tagName,
            'selector'    => $this->elementSelector($control),
            'id'          => $this->attr($control, 'id'),
            'name'        => $this->attr($control, 'name'),
            'type'        => $type,
            'label'       => $this->formControlLabel($control),
            'placeholder' => $this->attr($control, 'placeholder'),
            'autocomplete' => $this->attr($control, 'autocomplete'),
            'pattern'     => $this->attr($control, 'pattern'),
            'min'         => $this->attr($control, 'min'),
            'max'         => $this->attr($control, 'max'),
            'step'        => $this->attr($control, 'step'),
            'maxlength'   => $this->attr($control, 'maxlength'),
            'rows'        => $this->attr($control, 'rows'),
        ), static fn (string $value): bool => '' !== $value);

        if ( in_array($type, array( 'button', 'reset', 'submit' ), true) ) {
            $text = $this->formButtonText($control);
            if ( '' !== $text ) {
                $metadata['text'] = $text;
            }
        }

        if ( $control->hasAttribute('required') || 'true' === strtolower(trim($this->attr($control, 'aria-required'))) ) {
            $metadata['required'] = true;
        }
        if ( $control->hasAttribute('disabled') ) {
            $metadata['disabled'] = true;
        }
        if ( $control->hasAttribute('readonly') ) {
            $metadata['readonly'] = true;
        }
        if ( $control->hasAttribute('checked') ) {
            $metadata['checked'] = true;
        }
        if ( $control->hasAttribute('multiple') ) {
            $metadata['multiple'] = true;
        }

        $value = $this->attr($control, 'value');
        if ( '' !== $value && 'select' !== $tagName ) {
            $metadata['value'] = $value;
        }

        if ( 'select' === $tagName ) {
            $options = $this->selectOptions($control);
            if ( array() !== $options ) {
                $metadata['options'] = $options;
            }
        }

        return $metadata;
    }

    private function isFormControlElement(DOMElement $element): bool
    {
        return FormControlClassifier::isControlElement($element);
    }

    private function formControlType(DOMElement $control): string
    {
        return FormControlClassifier::controlType($control);
    }

    private function formControlLabel(DOMElement $control): string
    {
        $ariaLabel = trim($this->attr($control, 'aria-label'));
        if ( '' !== $ariaLabel ) {
            return $ariaLabel;
        }

        $id = $this->attr($control, 'id');
        if ( '' !== $id && $control->ownerDocument instanceof DOMDocument ) {
            foreach ( $control->ownerDocument->getElementsByTagName('label') as $label ) {
                if ( $label instanceof DOMElement && $id === $this->attr($label, 'for') ) {
                    return $this->normalizedControlLabelText($label);
                }
            }
        }

        for ( $parent = $control->parentNode; $parent instanceof DOMElement; $parent = $parent->parentNode ) {
            if ( 'label' === strtolower($parent->tagName) ) {
                return $this->normalizedControlLabelText($parent);
            }
        }

        return '';
    }

    private function normalizedControlLabelText(DOMElement $label): string
    {
        return trim(preg_replace('/\s+/', ' ', $this->labelTextWithoutControls($label)) ?? '');
    }

    private function labelTextWithoutControls(DOMNode $node): string
    {
        if ( XML_TEXT_NODE === $node->nodeType ) {
            return $node->textContent ?? '';
        }

        if ( $node instanceof DOMElement && 'true' === strtolower($this->attr($node, 'aria-hidden')) ) {
            return '';
        }

        if ( $node instanceof DOMElement && $this->isFormControlElement($node) ) {
            return '';
        }

        $text = '';
        foreach ( $node->childNodes as $child ) {
            $text .= $this->labelTextWithoutControls($child);
        }

        return $text;
    }

    private function formButtonText(DOMElement $control): string
    {
        foreach ( array( 'aria-label', 'title' ) as $attribute ) {
            $label = trim($this->attr($control, $attribute));
            if ( '' !== $label ) {
                return $label;
            }
        }

        $text = trim(preg_replace('/\s+/', ' ', $control->textContent ?? '') ?? '');
        if ( '' !== $text ) {
            return $text;
        }

        return trim($this->attr($control, 'value'));
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function selectOptions(DOMElement $select): array
    {
        $options = array();
        foreach ( $select->getElementsByTagName('option') as $option ) {
            if ( ! $option instanceof DOMElement ) {
                continue;
            }

            $value = $this->attr($option, 'value');
            $optionMetadata = array(
                'label' => trim(preg_replace('/\s+/', ' ', $option->textContent ?? '') ?? ''),
                // An explicit empty value is a select placeholder semantic, not
                // a missing value to replace with the visible option label.
                'value' => $option->hasAttribute('value') ? $value : trim($option->textContent ?? ''),
            );
            if ( $option->hasAttribute('selected') ) {
                $optionMetadata['selected'] = true;
            }
            if ( $option->hasAttribute('disabled') ) {
                $optionMetadata['disabled'] = true;
            }
            if ( '' === trim($this->attr($option, 'value')) && ( $option->hasAttribute('disabled') || $option->hasAttribute('selected') ) ) {
                $optionMetadata['placeholder'] = true;
            }

            $options[] = $optionMetadata;
        }

        return $options;
    }

    /**
     * @return array<string, mixed>|null
     */
}
