<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Elements;

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Diagnostics\FallbackDiagnostic;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Generators\CopyToClipboardBlockGenerator;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Session\HtmlTransformerSession;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Support\SourceDom;
use DOMDocument;
use DOMElement;
use DOMText;
use LogicException;

/** Promotes a copy-to-clipboard control onto the companion block. */
final class CopyToClipboardConverter implements ElementConverter
{
    /** @var list<string> */
    private const COPY_VERBS = array(
        'copy', 'copied',
        'copier', 'copie',
        'kopieren', 'kopiert',
        'copiar', 'copiado', 'copiada',
        'copia', 'copiare', 'copiato',
        'kopiuj', 'kopiera', 'kopier', 'kopioi',
    );

    public function __construct(private readonly HtmlTransformerSession $session)
    {
    }

    /** @param array<int, array<string, mixed>> $fallbacks */
    public function convert(DOMElement $element, string $tagName, array &$fallbacks): ConversionOutcome
    {
        if ( ! $this->isCopyControlElement($element, $tagName) ) {
            return ConversionOutcome::unhandled();
        }

        if ( ! $this->hasCopyAffordance($element) ) {
            return ConversionOutcome::unhandled();
        }

        $copyText = $this->resolveCopyText($element);
        if ( '' === $copyText ) {
            $fallbacks[] = FallbackDiagnostic::build(array(
                'type' => 'html',
                'reason' => 'interactive_control_behavior_lost',
                'diagnostic_code' => 'interactive_control_behavior_lost',
                'message' => 'A copy control was recognized but its copy target could not be resolved from the source.',
                'source_format' => 'html',
                'tag' => strtolower($element->tagName),
                'selector' => SourceDom::elementSelector($element),
                'attributes' => SourceDom::htmlAttributes($element),
                'context' => array(
                    'parent_tag' => SourceDom::closestTagName($element) ?? '',
                    'ancestor_tags' => SourceDom::ancestorTags($element),
                ),
            ));

            return ConversionOutcome::unhandled();
        }

        return ConversionOutcome::handled($this->block($element, $copyText));
    }

    /** @return array<string, mixed> */
    private function block(DOMElement $element, string $copyText): array
    {
        $generator = new CopyToClipboardBlockGenerator();
        $registry = $this->session->generatedBlockRegistry()
            ?? throw new LogicException('Generated block registry has not been prepared for this transform.');
        $registry->register(CopyToClipboardBlockGenerator::class, $generator->definition($registry->namespace()));
        $label = $this->visibleLabel($element);
        $ariaLabel = trim(SourceDom::attr($element, 'aria-label'));
        if ( '' === $ariaLabel ) {
            $ariaLabel = trim(SourceDom::attr($element, 'title'));
        }
        $attrs = array_filter(array(
            'id' => SourceDom::attr($element, 'id'),
            'ariaLabel' => $ariaLabel,
            'className' => SourceDom::attr($element, 'class'),
            'style' => SourceDom::attr($element, 'style'),
            'label' => $label,
            'copyText' => $copyText,
            'iconHtml' => $generator->safeIcon($this->iconHtml($element)),
            'copiedAnnouncement' => 'Copied',
        ), static fn (mixed $value): bool => '' !== $value);
        $markup = $generator->markup($attrs);

        return array(
            'blockName' => $registry->blockName(CopyToClipboardBlockGenerator::LOCAL_NAME),
            'attrs' => $attrs,
            'innerBlocks' => array(),
            'innerHTML' => $markup,
            'innerContent' => array( $markup ),
        );
    }

    private function isCopyControlElement(DOMElement $element, string $tagName = ''): bool
    {
        $tagName = '' !== $tagName ? $tagName : strtolower($element->tagName);
        if ( 'button' === $tagName ) {
            return true;
        }
        if ( 'button' === strtolower(SourceDom::attr($element, 'role')) ) {
            return true;
        }
        if ( 'a' !== $tagName ) {
            return false;
        }

        return $this->hasClipboardContract($element) || $this->isNonNavigatingHref($element);
    }

    private function hasCopyAffordance(DOMElement $element): bool
    {
        return $this->hasClipboardContract($element)
            || $this->hasCopyHandler($element)
            || $this->isCopyIntentName($this->accessibleName($element));
    }

    private function hasClipboardContract(DOMElement $element): bool
    {
        foreach ( $element->attributes ?? array() as $attribute ) {
            if ( 1 === preg_match('/^data-(?:clipboard|copy)(?:-[a-z0-9-]+)?$/', strtolower($attribute->nodeName)) ) {
                return true;
            }
        }

        return false;
    }

    private function hasCopyHandler(DOMElement $element): bool
    {
        foreach ( array( 'onclick', 'onchange' ) as $attribute ) {
            $value = SourceDom::attr($element, $attribute);
            if ( '' !== $value && 1 === preg_match('/clipboard|execCommand\s*\(\s*[\'"]copy[\'"]/i', $value) ) {
                return true;
            }
        }

        return false;
    }

    private function isNonNavigatingHref(DOMElement $element): bool
    {
        $href = trim(SourceDom::attr($element, 'href'));

        return '' === $href || '#' === $href || 1 === preg_match('/^javascript:\s*(?:void\s*\(\s*0*\s*\)|void\s+0)?\s*;?\s*$/i', $href);
    }

    private function resolveCopyText(DOMElement $element): string
    {
        $contract = $this->clipboardContract($element);
        if ( '' !== $contract['text'] ) {
            return $this->normalizeText($contract['text']);
        }
        if ( '' !== $contract['target'] ) {
            $target = $this->elementBySelector($element, $contract['target']);
            if ( $target instanceof DOMElement ) {
                $text = $this->normalizeText($target->textContent ?? '');
                if ( '' !== $text ) {
                    return $text;
                }
            }
        }

        $handlerText = $this->handlerCopyText($element);
        if ( '' !== $handlerText ) {
            return $handlerText;
        }

        return $this->ancestorCopyText($element);
    }

    /** @return array{text: string, target: string} */
    private function clipboardContract(DOMElement $element): array
    {
        $text = '';
        $target = '';
        foreach ( $element->attributes ?? array() as $attribute ) {
            $name = strtolower($attribute->nodeName);
            if ( 1 !== preg_match('/^data-(?:clipboard|copy)(?:-([a-z0-9-]+))?$/', $name, $matches) ) {
                continue;
            }
            $suffix = $matches[1] ?? '';
            $value = trim((string) ($attribute->nodeValue ?? ''));
            if ( '' === $value ) {
                continue;
            }
            if ( in_array($suffix, array( 'target', 'selector' ), true) ) {
                $target = $value;
                continue;
            }
            if ( str_starts_with($value, '#') ) {
                $target = $value;
                continue;
            }
            if ( in_array($suffix, array( '', 'text', 'value', 'content' ), true) ) {
                $text = $value;
            }
        }

        return array( 'text' => $text, 'target' => $target );
    }

    private function handlerCopyText(DOMElement $element): string
    {
        foreach ( array( 'onclick', 'onchange' ) as $attribute ) {
            $value = SourceDom::attr($element, $attribute);
            if ( 1 === preg_match('/clipboard\.writeText\s*\(\s*([\'"])(.*?)\1/s', $value, $matches) ) {
                return $this->normalizeText(html_entity_decode($matches[2], ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            }
        }

        return '';
    }

    private function ancestorCopyText(DOMElement $element): string
    {
        $label = $this->visibleLabel($element);
        for ( $parent = $element->parentNode; $parent instanceof DOMElement; $parent = $parent->parentNode ) {
            $tag = strtolower($parent->tagName);
            if ( in_array($tag, array( 'body', 'html' ), true) ) {
                break;
            }
            if ( $this->containsOtherCopyControl($parent, $element) ) {
                break;
            }
            $text = $this->visibleTextExcluding($parent, $element);
            if ( '' === $text || $text === $label || $this->isCopyIntentName($text) ) {
                continue;
            }

            return $text;
        }

        return '';
    }

    private function containsOtherCopyControl(DOMElement $root, DOMElement $current): bool
    {
        foreach ( $root->getElementsByTagName('*') as $candidate ) {
            if ( ! $candidate instanceof DOMElement || $candidate->isSameNode($current) ) {
                continue;
            }
            if ( $this->isCopyControlElement($candidate) && $this->hasCopyAffordance($candidate) ) {
                return true;
            }
        }

        return false;
    }

    private function visibleTextExcluding(DOMElement $root, DOMElement $excluded): string
    {
        $text = '';
        $walk = function (mixed $node) use (&$walk, &$text, $excluded): void {
            if ( $node instanceof DOMElement && $node->isSameNode($excluded) ) {
                return;
            }
            if ( $node instanceof DOMText ) {
                $text .= $node->textContent;
                return;
            }
            if ( $node instanceof DOMElement ) {
                foreach ( $node->childNodes as $child ) {
                    $walk($child);
                }
            }
        };
        foreach ( $root->childNodes as $child ) {
            $walk($child);
        }

        return $this->normalizeText($text);
    }

    private function elementBySelector(DOMElement $context, string $selector): ?DOMElement
    {
        $selector = trim($selector);
        if ( 1 !== preg_match('/^#([A-Za-z][\w:-]*)$/', $selector, $matches) ) {
            return null;
        }
        $id = $matches[1];
        $document = $context->ownerDocument;
        if ( ! $document instanceof DOMDocument ) {
            return null;
        }
        $found = $document->getElementById($id);
        if ( $found instanceof DOMElement ) {
            return $found;
        }
        foreach ( $document->getElementsByTagName('*') as $candidate ) {
            if ( $candidate instanceof DOMElement && $id === $candidate->getAttribute('id') ) {
                return $candidate;
            }
        }

        return null;
    }

    private function accessibleName(DOMElement $element): string
    {
        foreach ( array( 'aria-label', 'title' ) as $attribute ) {
            $value = trim(SourceDom::attr($element, $attribute));
            if ( '' !== $value ) {
                return $value;
            }
        }
        $labelledBy = trim(SourceDom::attr($element, 'aria-labelledby'));
        if ( '' !== $labelledBy && $element->ownerDocument instanceof DOMDocument ) {
            $label = $this->elementBySelector($element, '#' . $labelledBy);
            if ( $label instanceof DOMElement ) {
                return $this->normalizeText($label->textContent ?? '');
            }
        }

        return $this->visibleLabel($element);
    }

    private function visibleLabel(DOMElement $element): string
    {
        return $this->normalizeText($element->textContent ?? '');
    }

    private function iconHtml(DOMElement $element): string
    {
        foreach ( $element->childNodes as $child ) {
            if ( ! $child instanceof DOMElement ) {
                continue;
            }
            $tag = strtolower($child->tagName);
            if ( 'img' === $tag || 'svg' === $tag ) {
                return SourceDom::outerHtml($child);
            }
        }

        return '';
    }

    private function isCopyIntentName(string $name): bool
    {
        $normalized = $this->normalizeName($name);
        if ( '' === $normalized || str_contains($normalized, 'copyright') ) {
            return false;
        }
        if ( in_array($normalized, self::COPY_VERBS, true) ) {
            return true;
        }
        foreach ( self::COPY_VERBS as $verb ) {
            if ( str_starts_with($normalized, $verb . ' ') ) {
                return true;
            }
        }

        return false;
    }

    private function normalizeName(string $value): string
    {
        $value = mb_strtolower($this->normalizeText($value), 'UTF-8');
        $transliterated = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);

        return is_string($transliterated) && '' !== $transliterated ? strtolower($transliterated) : $value;
    }

    private function normalizeText(string $value): string
    {
        return trim(preg_replace('/\s+/u', ' ', $value) ?? '');
    }
}
