<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Elements;

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Classification\FormControlClassifier;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Patterns\ListingControlsRecognizer;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Support\LinkUrlSanitizer;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Support\SourceDom;
use Automattic\BlocksEngine\PhpTransformer\Support\HtmlSerialization;
use DOMDocument;
use DOMElement;
use WeakMap;

/** Determines whether a form depends on behavior that native blocks cannot retain. */
final class FormRuntimeRequirementAnalyzer
{
    /** @var WeakMap<DOMDocument, array<string, true>>|null */
    private static ?WeakMap $sourceBehaviorForms = null;

    /** @var WeakMap<DOMDocument, array<string, bool>>|null */
    private static ?WeakMap $sourceToolForms = null;

    /** Retain behavior evidence removed by script extraction or URL sanitization. */
    public static function indexSourceBehavior(DOMDocument $document, string $sourceHtml): void
    {
        $forms = $document->getElementsByTagName('form');
        if ( 0 === $forms->length || '' === $sourceHtml ) {
            return;
        }
        $source = HtmlSerialization::parseDocument($sourceHtml);
        $sourceForms = $source->getElementsByTagName('form');
        $behavior = array();
        $tools = array();
        foreach ( $sourceForms as $index => $form ) {
            if ( self::isClientSideTool($form) ) {
                $tools[$index] = true;
            }
            if ( self::hasSourceBehavior($form) ) {
                $behavior[$index] = true;
            }
        }
        $paths = array();
        $toolPaths = array();
        foreach ( $forms as $index => $form ) {
            if ( $forms->length === $sourceForms->length ) {
                $toolPaths[(string) $form->getNodePath()] = isset($tools[$index]);
            }
            // If normalization changed the form count, do not guess which
            // remaining form owned the removed behavior.
            if ( isset($behavior[$index]) || (array() !== $behavior && $forms->length !== $sourceForms->length) ) {
                $paths[(string) $form->getNodePath()] = true;
            }
        }
        self::$sourceBehaviorForms ??= new WeakMap();
        self::$sourceBehaviorForms[$document] = $paths;
        self::$sourceToolForms ??= new WeakMap();
        self::$sourceToolForms[$document] = $toolPaths;
    }

    public static function releaseSourceBehavior(DOMDocument $document): void
    {
        if ( null !== self::$sourceBehaviorForms ) {
            unset(self::$sourceBehaviorForms[$document]);
        }
        if ( null !== self::$sourceToolForms ) {
            unset(self::$sourceToolForms[$document]);
        }
    }

    /**
     */
    public function __construct(
        private readonly RuntimeDomTargets $runtimeDomTargets
    ) {
    }

    public function requiresPreservation(DOMElement $form): bool
    {
        return 'client_side_tool' === self::formIntent($form)
            || 0 < $form->getElementsByTagName('script')->length
            || array() !== SourceDom::eventMetadata($form)
            || $this->hasSubmissionMetadata($form)
            || self::hasCommerceSubmissionSignal($form)
            || $this->hasRuntimeDomTargets($form);
    }

    private function hasSubmissionMetadata(DOMElement $form): bool
    {
        $action = trim(SourceDom::attr($form, 'action'));
        if ( '' !== $action && '#' !== $action ) {
            return true;
        }

        if ( '' === $action && '' !== trim(SourceDom::attr($form, 'method')) ) {
            return true;
        }

        foreach ( array( 'enctype', 'target' ) as $attribute ) {
            if ( '' !== trim(SourceDom::attr($form, $attribute)) ) {
                return true;
            }
        }

        return false;
    }

    /**
     * Forms made only of bounded choices keep their real controls as editable
     * blocks, whether or not they submit, because there is nothing to collect
     * that a contact provider could stand in for.
     */
    public static function collectsOnlyChoices(DOMElement $form): bool
    {
        return in_array(self::formIntent($form), array( 'preference', 'choice_submission' ), true);
    }

    /** Classifies source semantics only; classes and vendor attributes are not intent. */
    public static function formIntent(DOMElement $form): string
    {
        $path = strtolower((string) parse_url(SourceDom::attr($form, 'action'), PHP_URL_PATH));
        $controls = self::intentControls($form);
        // Hidden submission data is deliberately absent from the authored-control
        // inventory; inspect it separately without exposing it as an editable field.
        $hiddenItem = false;
        foreach ( $form->getElementsByTagName('input') as $input ) {
            if ( ! $input->hasAttribute('disabled') && 'hidden' === strtolower(SourceDom::attr($input, 'type'))
                && in_array(strtolower(SourceDom::attr($input, 'name')), array( 'id', 'item_id', 'product_id', 'variant_id' ), true)
                && '' !== trim(SourceDom::attr($input, 'value')) ) {
                $hiddenItem = true;
            }
        }
        $quantity = false;
        $email = false;
        $message = false;
        $search = 'search' === strtolower(SourceDom::attr($form, 'role'));
        $select = false;
        $password = false;
        $subscribe = false;
        $donate = 1 === preg_match('~(?:^|/)(?:donat(?:e|ion|ions)|give|giving)(?:[/.]|$)~', $path);
        $freeText = false;
        $hasChoice = false;
        $onlyChoices = true;
        $hasSubmit = false;
        $choiceGroupSizes = array();
        // Submission controls count even when they are not rendered/authored
        // fields in the provider inventory (for example a hidden submit button).
        foreach ( $controls as $control ) {
            $tag = strtolower($control->tagName);
            $controlType = FormControlClassifier::controlType($control);
            if ( in_array($tag, array( 'button', 'input' ), true)
                && in_array($controlType, array( 'submit', 'image' ), true) ) {
                $hasSubmit = true;
            }
            if ( FormControlClassifier::isSubmitLikeControl($control) ) {
                $donate = $donate || self::hasDonationSubmitSemantics($control);
            }
        }
        foreach ( $controls as $control ) {
            $type = strtolower(SourceDom::attr($control, 'type'));
            $sourceName = SourceDom::attr($control, 'name');
            $name = strtolower($sourceName);
            $tag = strtolower($control->tagName);
            $controlType = FormControlClassifier::controlType($control);
            if ( 'select' === $tag || ('input' === $tag && in_array($controlType, array( 'radio', 'checkbox' ), true)) ) {
                $hasChoice = true;
                if ( 'select' !== $tag && '' !== $name ) {
                    $group = $controlType . ':' . $sourceName;
                    $choiceGroupSizes[$group] = ($choiceGroupSizes[$group] ?? 0) + 1;
                }
            } elseif ( ('button' === $tag && in_array(strtolower(SourceDom::attr($control, 'role')), array( 'radio', 'checkbox', 'switch' ), true))
                || ('button' !== $tag && ! in_array($controlType, array( 'submit', 'reset', 'button', 'image' ), true)) ) {
                // Custom stateful widgets are not plain button/reset controls;
                // retain their existing provider composition and presentation.
                $onlyChoices = false;
            }
            $quantity = $quantity || in_array($name, array( 'quantity', 'qty' ), true);
            $email = $email || 'email' === $type || 'email' === $name;
            $message = $message || 'textarea' === $tag;
            $search = $search || 'search' === $type || in_array($name, array( 'q', 's', 'search', 'query' ), true);
            $select = $select || 'select' === $tag;
            $password = $password || 'password' === $type;
            // Filters choose among values (select, checkbox, radio, range, number,
            // date); a form that asks for free text such as a name or phone
            // collects a message, never a catalog filter.
            $freeText = $freeText || 'textarea' === $tag || ('input' === $tag && in_array($type, array( '', 'text', 'tel', 'email', 'url' ), true) && ! in_array($name, array( 'q', 's', 'search', 'query' ), true));
            if ( FormControlClassifier::isSubmitLikeControl($control) ) {
                $subscribe = $subscribe || 1 === preg_match('/\b(?:subscribe|newsletter)\b/i', ($control->textContent ?? '') . ' ' . SourceDom::attr($control, 'value'));
            }
        }
        if ( preg_match('~(?:^|/)cart/add(?:[/.]|$)~', $path) || ($hiddenItem && $quantity) || self::hasCommerceSubmissionSignal($form, true) ) {
            return 'commerce_add_to_cart';
        }
        if ( $password ) return 'login';
        // A contribution submit (donate / give / contribute) or donation action
        // path outranks the GET filter rule: amount and frequency choices are
        // what the visitor gives, not a catalog filter.
        if ( $donate ) return 'donation';
        // Messaging intents first: a contact form may offer a subject <select>
        // and omit `method`, but a search or filter form never collects an email.
        if ( $email && ($subscribe || preg_match('~(?:^|/)(?:newsletter|subscribe)(?:/|$)~', $path)) ) return 'newsletter';
        if ( $email && $message ) return 'contact';
        if ( self::isClientSideTool($form) ) return 'client_side_tool';
        $method = strtolower(trim(SourceDom::attr($form, 'method')));
        if ( '' === $method || 'get' === $method ) {
            if ( $search || preg_match('~(?:^|/)search(?:/|$)~', $path) ) return 'search';
            if ( $select && ! $freeText ) return 'filter_sort';
            if ( ! $freeText && (new ListingControlsRecognizer())->hasNumericBoundsForForm($form) ) return 'filter_sort';
        }

        // Keep existing GET search/filter intents, but recognize bounded choice
        // state without a submit control independently of method or destination.
        if ( $hasChoice && $onlyChoices && ! $hasSubmit && ! $email ) return 'preference';
        // One question answered by picking among named options, such as a poll,
        // needs a handler the source site owned. A lone consent checkbox or a
        // select with a submit may belong to a larger flow, and so may anything
        // that buys, registers, or searches, so those stay unknown.
        if ( $onlyChoices && $hasSubmit && ! $select && ! $email && ! $search && ! $hiddenItem
            && ! self::hasCommerceSubmissionSignal($form)
            && ! self::hasSourceBehavior($form)
            && array() !== array_filter($choiceGroupSizes, static fn (int $size): bool => $size > 1) ) {
            return 'choice_submission';
        }

        return 'unknown';
    }

    /**
     * Intent spans the whole workflow, not just the currently rendered step.
     * Keep this separate from the authored inventory used by block topology and
     * presentation: captured non-rendering state is not evidence of intent.
     *
     * @return list<DOMElement>
     */
    public static function intentControls(DOMElement $form): array
    {
        $controls = array();
        foreach ( $form->getElementsByTagName('*') as $control ) {
            if ( ! FormControlClassifier::isControlElement($control)
                || $control->hasAttribute('disabled')
                || 'hidden' === FormControlClassifier::controlType($control)
                || FormControlClassifier::isHoneypotControl($control)
                || SourceDom::hasNonAuthoredAncestor($control) ) {
                continue;
            }
            $controls[] = $control;
        }

        return $controls;
    }

    /**
     * Scripts, embedded documents, event handlers and non-web link schemes
     * anywhere in the form keep it on the source fallback, which already owns
     * that markup.
     */
    private static function hasSourceBehavior(DOMElement $form): bool
    {
        if ( null !== $form->ownerDocument && isset(self::$sourceBehaviorForms[$form->ownerDocument][(string) $form->getNodePath()]) ) {
            return true;
        }
        $elements = array( $form, ...iterator_to_array($form->getElementsByTagName('*'), false) );
        foreach ( $elements as $element ) {
            if ( in_array(strtolower($element->tagName), array( 'script', 'iframe', 'frame', 'object', 'embed' ), true)
                || array() !== SourceDom::eventMetadata($element) ) {
                return true;
            }
            foreach ( array( 'action', 'formaction', 'href', 'src', 'xlink:href' ) as $attribute ) {
                $url = trim(SourceDom::attr($element, $attribute));
                $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
                if ( '' !== $url && ('' === LinkUrlSanitizer::sanitize($url) || ! in_array($scheme, array( '', 'http', 'https' ), true)) ) {
                    return true;
                }
            }
        }

        return false;
    }

    /** Local result workflows need the source runtime, not a form provider. */
    private static function isClientSideTool(DOMElement $form): bool
    {
        if ( null !== $form->ownerDocument && isset(self::$sourceToolForms[$form->ownerDocument][(string) $form->getNodePath()]) ) {
            return self::$sourceToolForms[$form->ownerDocument][(string) $form->getNodePath()];
        }
        if ( ! in_array(trim(SourceDom::attr($form, 'action')), array( '', '#' ), true) ) {
            return false;
        }
        foreach ( $form->getElementsByTagName('*') as $control ) {
            if ( ! in_array(trim(SourceDom::attr($control, 'formaction')), array( '', '#' ), true) ) {
                return false;
            }
            // A select that changes location is navigation, even beside a status.
            foreach ( $control->attributes as $attribute ) {
                if ( 1 === preg_match('/\\b(?:location|open)\\b/i', str_starts_with(strtolower($attribute->nodeName), 'on') ? ($attribute->nodeValue ?? '') : '') ) {
                    return false;
                }
            }
        }
        $document = $form->ownerDocument;
        if ( null === $document || (0 === $document->getElementsByTagName('script')->length && ! self::hasSourceBehavior($form)) ) {
            return false;
        }
        // Results commonly sit just after the form rather than inside it.
        $scope = $form->parentNode;
        if ( ! $scope instanceof DOMElement ) {
            return false;
        }
        foreach ( $scope->getElementsByTagName('*') as $result ) {
            if ( 'output' === strtolower($result->tagName)
                || 'status' === strtolower(SourceDom::attr($result, 'role'))
                || in_array(strtolower(SourceDom::attr($result, 'aria-live')), array( 'polite', 'assertive' ), true) ) {
                return true;
            }
        }

        return false;
    }

    private static function hasDonationSubmitSemantics(DOMElement $control): bool
    {
        // Join text nodes with spaces so sibling spans ("Donate" + a loading
        // copy of "Donate") do not fuse into one word.
        $text = array();
        foreach ( (new \DOMXPath($control->ownerDocument ?? new \DOMDocument()))->query('.//text()', $control) ?: array() as $node ) {
            $text[] = $node->textContent;
        }
        $haystack = implode(' ', array_merge($text, array(
            SourceDom::attr($control, 'value'),
            SourceDom::attr($control, 'alt'),
            SourceDom::attr($control, 'aria-label'),
            SourceDom::attr($control, 'title'),
        )));

        return 1 === preg_match('/\b(?:donat(?:e|ion)|give|contribut(?:e|ion))\b/i', $haystack);
    }

    private static function hasCommerceSubmissionSignal(DOMElement $form, bool $addToCartOnly = false): bool
    {
        foreach ( $form->getElementsByTagName('*') as $control ) {
            if ( $control->hasAttribute('disabled') || ! FormControlClassifier::isSubmitLikeControl($control) ) {
                continue;
            }

            $action = $control->hasAttribute('formaction') ? SourceDom::attr($control, 'formaction') : SourceDom::attr($form, 'action');
            $host = rtrim(strtolower((string) parse_url(trim($action), PHP_URL_HOST)), '.');
            if ( ! $addToCartOnly && 1 === preg_match('/(?:^|\.)paypal\.com$/', $host) ) {
                return true;
            }

            $haystack = strtolower(implode(' ', array(
                $control->textContent ?? '',
                SourceDom::attr($control, 'value'),
                SourceDom::attr($control, 'alt'),
                $addToCartOnly ? '' : SourceDom::attr($control, 'class'),
                $addToCartOnly ? '' : SourceDom::attr($control, 'id'),
                SourceDom::attr($control, 'name'),
                SourceDom::attr($control, 'aria-label'),
                SourceDom::attr($control, 'title'),
            )));

            $semantics = $addToCartOnly ? 'add[ _-]*(?:to[ _-]*)?cart' : 'add to cart|cart|checkout|pay|payment|paypal|purchase|buy|order|register|registration|ticket';
            if ( preg_match('/(?:^|[^a-z0-9])(?:' . $semantics . ')(?:[^a-z0-9]|$)/', $haystack) ) {
                return true;
            }
        }

        return false;
    }

    private function hasRuntimeDomTargets(DOMElement $form): bool
    {
        if ( $this->runtimeDomTargets->isRuntimeDomTarget($form) || $this->hasRuntimeClassSignal($form) ) {
            return true;
        }

        foreach ( FormControlClassifier::controlElements($form) as $control ) {
            if ( $this->runtimeDomTargets->isRuntimeDomTarget($control) || $this->hasRuntimeClassSignal($control) ) {
                return true;
            }
        }

        return false;
    }

    private function hasRuntimeClassSignal(DOMElement $element): bool
    {
        foreach ( SourceDom::tokens(SourceDom::attr($element, 'class')) as $class ) {
            if ( preg_match('/^js-[A-Za-z0-9_-]+$/', $class) ) {
                return true;
            }
        }

        return false;
    }
}
