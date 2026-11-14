<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Elements;

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\SourceDeclarations;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\CssValueInspector;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Classification\FormControlClassifier;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Classification\FormControlLabel;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Support\SourceDom;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\SourceBlockCreator;
use DOMElement;
use DOMText;

/** Replaces plain credential forms with destination-owned WordPress authentication. */
final class LoginBlockConverter
{
    public function __construct(private readonly SourceBlockCreator $createBlock)
    {
    }

    /** @return array<string, mixed>|null */
    public function loginBlockFromForm(DOMElement $form): ?array
    {
        if ( 'login' !== FormRuntimeRequirementAnalyzer::formIntent($form)
            || 0 < $form->getElementsByTagName('script')->length
            || array() !== SourceDom::eventMetadata($form) ) {
            return null;
        }

        $passwords = 0;
        $confirmations = 0;
        $identityOnly = true;
        $registrationText = '';
        $usernames = 0;
        $checkboxes = 0;
        $terms = false;
        $submits = 0;

        // Count raw controls, including hidden-by-CSS and new-password fields.
        foreach ( $form->getElementsByTagName('*') as $control ) {
            if ( ! $control instanceof DOMElement || ! FormControlClassifier::isControlElement($control) ) {
                continue;
            }
            if ( array() !== SourceDom::eventMetadata($control) ) {
                return null;
            }
            $tag = strtolower($control->tagName);
            $type = FormControlClassifier::controlType($control);
            if ( 'input' === $tag ) {
                if ( 'hidden' === $type ) {
                    continue;
                }
                if ( 'password' === $type ) {
                    if ( preg_match('/confirm|repeat|retype/i', $this->controlSignal($control)) ) {
                        ++$confirmations;
                    } else {
                        ++$passwords;
                    }
                    continue;
                }
                if ( in_array($type, array( 'text', 'email' ), true) ) {
                    $identityOnly = $identityOnly && ('email' === $type || 1 === preg_match('/\b(?:name|first[ _-]*name|last[ _-]*name|full[ _-]*name|display[ _-]*name|user[ _-]*name|user[ _-]*login|email|e-mail|log|login)\b/i', $this->controlSignal($control)));
                    ++$usernames;
                    continue;
                }
                if ( 'checkbox' === $type ) {
                    $signal = $this->controlSignal($control);
                    if ( ! preg_match('/\b(?:remember(?:[ _-]*me)?|keep[ _-]*me[ _-]*(?:signed|logged)[ _-]*in)\b/i', $signal) ) {
                        if ( preg_match('/\bterms\b/i', $signal) ) {
                            $terms = true;
                            continue;
                        }
                        return null;
                    }
                    ++$checkboxes;
                    continue;
                }
            }
            if ( in_array($tag, array( 'input', 'button' ), true) && 'submit' === $type ) {
                $text = trim('input' === $tag ? SourceDom::attr($control, 'value') : ($control->textContent ?? ''));
                if ( preg_match('/\b(?:register|registration|sign[\s_-]*up|create[\s_-]*(?:an?[\s_-]*)?account)\b/i', $text) ) {
                    $registrationText = $text;
                }
                ++$submits;
                continue;
            }
            if ( 'button' === $tag && 'button' === $type ) {
                continue;
            }
            return null;
        }

        $registration = '' !== $registrationText;
        if ( 1 !== $passwords || 1 > $usernames || 1 < $checkboxes || 1 !== $submits
            || ($registration ? (! $identityOnly || 1 < $confirmations) : (1 !== $usernames || 0 !== $confirmations || $terms)) ) {
            return null;
        }

        // Source endpoints, field names, captured state and redirects are not
        // portable authentication contracts. WordPress supplies all of them.
        $blocks = array( $this->createBlock->createBlock('core/loginout', array( 'displayLoginAsForm' => true ), array(), $form) );
        if ( $registration ) {
            $blocks[] = $this->buttons($registrationText, '/wp-login.php?action=register');
        }
        // Collect prose after the submit, flushing it before the matching
        // secondary control. Credential labels remain owned by loginout.
        $afterSubmit = false;
        $prose = '';
        foreach ( $this->signInContent($form) as $node ) {
            if ( $node instanceof DOMText ) {
                if ( $afterSubmit ) {
                    $prose .= $node->textContent;
                }
                continue;
            }
            if ( 'submit' === FormControlClassifier::controlType($node) ) {
                $afterSubmit = true;
                continue;
            }
            $text = trim(preg_replace('/\s+/', ' ', $node->textContent ?? '') ?? '');
            if ( '' === $text ) {
                return null;
            }
            $prose = trim(preg_replace('/\s+/', ' ', $prose) ?? '');
            if ( '' !== $prose ) {
                $blocks[] = $this->createBlock->createBlock('core/paragraph', array( 'content' => htmlspecialchars($prose, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ));
            }
            $prose = '';
            $blocks[] = $this->buttons($text);
        }
        return 1 === count($blocks) ? $blocks[0] : $this->createBlock->createBlock('core/group', array(), $blocks, $form);
    }

    /** @return \Generator<int, DOMElement|DOMText> */
    private function signInContent(DOMElement $element): \Generator
    {
        foreach ( $element->childNodes as $child ) {
            if ( $child instanceof DOMText ) {
                yield $child;
                continue;
            }
            if ( ! $child instanceof DOMElement
                || in_array(strtolower($child->tagName), array( 'label', 'legend', 'script', 'style', 'template' ), true)
                || $child->hasAttribute('hidden')
                || SourceDom::isAriaHidden($child)
                || CssValueInspector::hidesBox(SourceDeclarations::inline($child)) ) {
                continue;
            }
            if ( FormControlClassifier::isControlElement($child) ) {
                if ( in_array(FormControlClassifier::controlType($child), array( 'submit', 'button' ), true) ) {
                    yield $child;
                }
                continue;
            }
            yield from $this->signInContent($child);
        }
    }

    /** @return array<string, mixed> */
    private function buttons(string $text, ?string $url = null): array
    {
        $button = $this->createBlock->createBlock('core/button', array_filter(array( 'text' => $text, 'url' => $url ), static fn ($value): bool => null !== $value));
        return $this->createBlock->createBlock('core/buttons', array(), array( $button ));
    }

    private function controlSignal(DOMElement $control): string
    {
        return implode(' ', array(
            SourceDom::attr($control, 'name'),
            SourceDom::attr($control, 'id'),
            SourceDom::attr($control, 'aria-label'),
            FormControlLabel::element($control)?->textContent ?? '',
        ));
    }
}
