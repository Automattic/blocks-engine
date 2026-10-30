<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Elements;

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Classification\FormControlClassifier;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Classification\FormControlLabel;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Support\SourceDom;
use Closure;
use DOMElement;

/** Replaces plain sign-in forms with destination-owned WordPress authentication. */
final class LoginBlockConverter
{
    /** @param Closure(DOMElement): array<string, mixed> $createLoginBlock */
    public function __construct(private readonly Closure $createLoginBlock)
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
        $usernames = 0;
        $checkboxes = 0;
        $submits = 0;
        // Count raw controls, not the authored inventory: even a hidden-by-CSS
        // or autocomplete="new-password" confirmation field must decline lowering.
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
                    ++$passwords;
                    continue;
                }
                if ( in_array($type, array( 'text', 'email' ), true) ) {
                    ++$usernames;
                    continue;
                }
                if ( 'checkbox' === $type ) {
                    $label = FormControlLabel::element($control);
                    $signal = implode(' ', array(
                        SourceDom::attr($control, 'name'),
                        SourceDom::attr($control, 'id'),
                        SourceDom::attr($control, 'aria-label'),
                        $label?->textContent ?? '',
                    ));
                    if ( ! preg_match('/\b(?:remember(?:[ _-]*me)?|keep[ _-]*me[ _-]*(?:signed|logged)[ _-]*in)\b/i', $signal) ) {
                        return null;
                    }
                    ++$checkboxes;
                    continue;
                }
            }
            if ( in_array($tag, array( 'input', 'button' ), true) && 'submit' === $type ) {
                $signal = implode(' ', array(
                    $control->textContent ?? '',
                    SourceDom::attr($control, 'value'),
                    SourceDom::attr($control, 'name'),
                    SourceDom::attr($control, 'aria-label'),
                    SourceDom::attr($control, 'title'),
                ));
                if ( preg_match('/\b(?:register|registration|sign[\s_-]*up|create[\s_-]*(?:an?[\s_-]*)?account)\b/i', $signal) ) {
                    return null;
                }
                ++$submits;
                continue;
            }
            return null;
        }

        if ( 1 !== $passwords || 1 !== $usernames || 1 < $checkboxes || 1 !== $submits ) {
            return null;
        }

        // Source endpoints, field names, captured state and redirects are not
        // portable authentication contracts. WordPress supplies all of them.
        return ($this->createLoginBlock)($form);
    }
}
