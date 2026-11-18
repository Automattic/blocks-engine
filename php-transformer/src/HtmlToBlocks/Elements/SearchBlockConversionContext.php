<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Elements;

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Support\SourceDom;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Classification\FormControlClassifier;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\ElementPresentationResolver;
use DOMElement;

/** Explicit transformer-owned surface consumed by {@see SearchBlockConverter}. */
final class SearchBlockConversionContext
{
    public function __construct(
        private readonly ElementPresentationResolver $presentationResolver
    ) {
    }

    /**
     * The native search contract shared by core/search lowering and form
     * intent: one text/search input, at most one submit, no other authored
     * controls (hidden server tokens are not authored), no scripts or event
     * handlers, and search evidence on the form or input. core/search replaces
     * the source handler, so a non-GET method only needs an explicit submit.
     *
     * @return array{input: DOMElement, submit: ?DOMElement}|null
     */
    public static function searchControls(DOMElement $form): ?array
    {
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

        if ( ! $textInput instanceof DOMElement || ! self::hasSearchFormSignal($form, $textInput) ) {
            return null;
        }

        $method = strtolower(trim(SourceDom::attr($form, 'method')));
        if ( '' !== $method && 'get' !== $method && null === $submitControl ) {
            return null;
        }

        return array( 'input' => $textInput, 'submit' => $submitControl );
    }

    public static function hasSearchFormSignal(DOMElement $form, DOMElement $input): bool
    {
        if ( 'search' === FormControlClassifier::controlType($input) || 'search' === SourceDom::role($form) ) {
            return true;
        }

        $queryName = strtolower(trim(SourceDom::attr($input, 'name')));
        if ( in_array($queryName, array( 's', 'q', 'query', 'search' ), true) ) {
            return true;
        }

        $haystack = strtolower(implode(' ', array(
            SourceDom::attr($form, 'action'),
            SourceDom::attr($form, 'aria-label'),
            SourceDom::attr($form, 'id'),
            SourceDom::attr($form, 'class'),
        )));

        if ( str_contains($haystack, 'search') ) {
            return true;
        }

        foreach ( FormControlClassifier::controlElements($form) as $control ) {
            if ( 'submit' === FormControlClassifier::controlType($control) && self::hasSearchIconSignal($control) ) {
                return true;
            }
        }

        return false;
    }

    /** Shared glyph evidence for form recognition and native icon-button rendering. */
    public static function hasSearchIconSignal(DOMElement $control): bool
    {
        $signals = array(
            SourceDom::attr($control, 'class'),
            SourceDom::attr($control, 'id'),
            SourceDom::attr($control, 'aria-label'),
            SourceDom::attr($control, 'title'),
            SourceDom::attr($control, 'data-icon'),
        );
        foreach ( $control->getElementsByTagName('*') as $descendant ) {
            $tag = strtolower($descendant->tagName);
            if ( in_array($tag, array( 'i', 'span', 'svg' ), true) ) {
                $signals[] = SourceDom::attr($descendant, 'class');
            }
            if ( 'use' === $tag ) {
                $signals[] = SourceDom::attr($descendant, 'href');
                $signals[] = SourceDom::attr($descendant, 'xlink:href');
            }
            $signals[] = SourceDom::attr($descendant, 'data-icon');
        }

        $haystack = strtolower(implode(' ', $signals));
        // Substring match keeps compound names (searchsubmit, btnSearch); close/clear
        // controls must not become search submits even when named search-close.
        return ! str_contains($haystack, 'close')
            && 1 !== preg_match('/(?:^|[^a-z0-9])clear(?:$|[^a-z0-9])/', $haystack)
            && 1 === preg_match('/search|magnif/', $haystack);
    }

    /** @return array<string, string> */
    public function presentationDeclarations(DOMElement $element): array
    {
        // Search replaces leaf controls, including elements below the normal
        // high-value presentation boundary. Keep their matched source rules.
        return array_merge(
            $this->presentationResolver->structuralPresentationDeclarations($element),
            $this->presentationResolver->presentationDeclarations($element)
        );
    }
}
