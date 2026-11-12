<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Elements;

use Automattic\BlocksEngine\PhpTransformer\Contract\CaptureEvidence;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Generators\CapturedDialogBlockGenerator;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Patterns\NavigationPattern;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Patterns\PatternTreeConverter;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Session\HtmlTransformerSession;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\StyleResolver;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Support\SourceDom;
use DOMElement;

/** Materializes editable dialog content as a core Group with a runtime shell. */
final class CapturedDialogConverter implements ElementConverter
{
    /** @var list<DOMElement> Adopted panels converting as their own content. */
    private array $adopting = array();

    public function __construct(
        private readonly HtmlTransformerSession $session,
        private readonly PatternTreeConverter $tree,
        private readonly StyleResolver $styleResolver
    ) {
    }

    /**
     * A source panel adopted in place with its resting presentation is itself
     * the dialog: it carries the bindings, whatever its tag. Claimed before
     * any other converter, its shell wraps the panel converted as ordinary
     * content, and renders no element of its own.
     *
     * @param array<int, array<string, mixed>> $fallbacks
     */
    public function convertAdoptedPanel(DOMElement $element, array &$fallbacks): ConversionOutcome
    {
        if ( ! self::isAdoptedPanel($element) ) return ConversionOutcome::unhandled();
        foreach ( $this->adopting as $adopting ) {
            if ( $adopting->isSameNode($element) ) return ConversionOutcome::unhandled();
        }
        if ( $this->isSupersededByNativeNavigation($element) ) {
            return ConversionOutcome::handled(null);
        }

        return ConversionOutcome::handled($this->block($element, $fallbacks));
    }

    public static function isAdoptedPanel(DOMElement $element): bool
    {
        return 'true' === SourceDom::attr($element, 'data-blocks-engine-captured-dialog')
            && in_array(SourceDom::attr($element, 'data-blocks-engine-resting'), array('authored', 'hidden'), true);
    }

    /** @param array<int, array<string, mixed>> $fallbacks */
    public function convert(DOMElement $element, string $tagName, array &$fallbacks, bool $captureUnsupported = false): ConversionOutcome
    {
        if ( self::isAdoptedPanel($element) ) {
            return $this->convertAdoptedPanel($element, $fallbacks);
        }
        // A native closed dialog already supplies its own hidden runtime shell;
        // capture evidence is only needed for other dialog surfaces.
        $isClosedNativeDialog = 'dialog' === $tagName && !$element->hasAttribute('open');
        if ( ! $isClosedNativeDialog && (! in_array($tagName, array('dialog', 'div'), true) || 'true' !== SourceDom::attr($element, 'data-blocks-engine-captured-dialog')) ) {
            return ConversionOutcome::unhandled();
        }

        if ( $this->isSupersededByNativeNavigation($element) ) {
            return ConversionOutcome::handled(null);
        }

        return ConversionOutcome::handled($this->block($element, $fallbacks));
    }

    private function isSupersededByNativeNavigation(DOMElement $dialog): bool
    {
        $document = $dialog->ownerDocument;
        if ( null === $document || ('nav' !== strtolower($dialog->tagName) && 0 === $dialog->getElementsByTagName('nav')->length) ) return false;
        foreach ( SourceDom::tokens(SourceDom::attr($dialog, 'data-blocks-engine-triggers')) as $triggerId ) {
            $trigger = $document->getElementById($triggerId);
            if ( ! $trigger instanceof DOMElement ) continue;
            if ( 'dialog' === strtolower(trim(SourceDom::attr($trigger, 'aria-haspopup')))
                && SourceDom::controlsSourceNavigation($trigger)
                && ! NavigationPattern::ownsCapturedSubmenuTrigger($trigger)
                && $this->session->navigationProjectionState()->hasTargetForControl($trigger)
            ) return true;
        }
        return false;
    }

    /**
     * The dialog's inline style, plus the open-state values a state rule gives
     * the panel over rest rules that hide or offset it. The dialog is the
     * captured open panel, so those rest rules must not win while it is open.
     * Inline and without `!important`: it beats the rest rules, and the block
     * stylesheet's `:not([open]){display:none!important}` still closes it.
     */
    private function openSourceStyle(DOMElement $element): string
    {
        $style = trim(SourceDom::attr($element, 'style'));
        if ( 'dialog' !== strtolower($element->tagName) || '' !== SourceDom::attr($element, 'data-blocks-engine-presentation') ) {
            return $style;
        }
        foreach ( $this->styleResolver->capturedDialogOpenStateValues($element) as $property => $value ) {
            if ( 1 === preg_match('/(?:^|;)\s*' . preg_quote($property, '/') . '\s*:/i', $style) ) {
                continue;
            }
            $style = rtrim($style, "; \t\n") . ('' === $style ? '' : ';') . $property . ':' . $value;
        }

        return $style;
    }

    /**
     * A modal whose source panel positions itself (`position:fixed` or
     * `absolute` from its own author rules) drew its own box: the source panel
     * was not a `<dialog>`, so it had no user agent padding, border, auto
     * margins, insets or size caps. Mark it so the block stylesheet drops that
     * geometry and the source rules alone place the panel.
     */
    private function modalPlacement(DOMElement $element): string
    {
        if ( 'dialog' !== strtolower($element->tagName) || '' !== SourceDom::attr($element, 'data-blocks-engine-presentation') ) {
            return '';
        }

        return 1 === preg_match('/(?:^|;)\s*position\s*:\s*(?:fixed|absolute)\b/i', $this->styleResolver->specificityResolvedPresentationStyle($element)) ? 'source' : '';
    }

    /**
     * @param array<int, array<string, mixed>> $fallbacks
     * @return array<string, mixed>
     */
    public function block(DOMElement $element, array &$fallbacks): array
    {
        $attrs = array_filter(array(
            'tagName' => 'div' === strtolower($element->tagName) ? 'div' : '',
            'sourceStyle' => $this->openSourceStyle($element),
            'ancestorUnverified' => SourceDom::attr($element, CaptureEvidence::DIALOG_ANCESTOR_UNVERIFIED),
            'dialogId' => trim(SourceDom::attr($element, 'id')),
            'triggerIds' => array_values(array_filter(SourceDom::tokens(SourceDom::attr($element, 'data-blocks-engine-triggers')))),
            'closerIds' => array_values(array_filter(SourceDom::tokens(SourceDom::attr($element, 'data-blocks-engine-closers')))),
            'ariaLabel' => trim(SourceDom::attr($element, 'aria-label')),
            'ariaLabelledby' => trim(SourceDom::attr($element, 'aria-labelledby')),
            'ariaDescribedby' => trim(SourceDom::attr($element, 'aria-describedby')),
            'className' => trim(SourceDom::attr($element, 'class')),
            'presentation' => 'dropdown' === SourceDom::attr($element, 'data-blocks-engine-presentation') ? 'dropdown' : '',
            'placement' => in_array(SourceDom::attr($element, 'data-blocks-engine-placement'), array('in-place', 'under-header', 'source'), true) ? SourceDom::attr($element, 'data-blocks-engine-placement') : $this->modalPlacement($element),
            'addCloseButton' => 'true' === SourceDom::attr($element, 'data-blocks-engine-add-close'),
            'gallerySelection' => json_decode(SourceDom::attr($element, 'data-blocks-engine-gallery-selection'), true) ?: array(),
            'ancestorState' => CapturedDialogBlockGenerator::ancestorState(json_decode(SourceDom::attr($element, 'data-blocks-engine-ancestor-state'), true)),
            'resting' => in_array(SourceDom::attr($element, 'data-blocks-engine-resting'), array('authored', 'hidden'), true) ? SourceDom::attr($element, 'data-blocks-engine-resting') : '',
            'restingState' => CapturedDialogBlockGenerator::restingState(json_decode(SourceDom::attr($element, 'data-blocks-engine-resting-state'), true)),
        ), static fn(mixed $value): bool => false !== $value && '' !== $value && array() !== $value);
        $adopted = self::isAdoptedPanel($element);
        if ( $adopted ) {
            // The panel block renders the panel's own tag, class, style and
            // labels; the shell only adds the bindings to that element.
            unset($attrs['tagName'], $attrs['sourceStyle'], $attrs['className'], $attrs['ariaLabel'], $attrs['ariaLabelledby'], $attrs['ariaDescribedby'], $attrs['addCloseButton']);
            $this->adopting[] = $element;
            try {
                $panel = $this->tree->element($element, $fallbacks, true);
            } finally {
                array_pop($this->adopting);
            }
            $children = null === $panel ? array() : array($panel);
        } else {
            $children = $this->tree->children($element, $fallbacks, true);
        }
        $escape = static fn(string $value): string => htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $tag = $attrs['tagName'] ?? 'dialog';
        $opening = '<' . $tag;
        foreach (array('dialogId' => 'id', 'className' => 'class', 'sourceStyle' => 'style', 'ancestorUnverified' => CaptureEvidence::DIALOG_ANCESTOR_UNVERIFIED, 'presentation' => 'data-blocks-engine-presentation', 'placement' => 'data-blocks-engine-placement', 'ariaLabel' => 'aria-label', 'ariaLabelledby' => 'aria-labelledby', 'ariaDescribedby' => 'aria-describedby') as $key => $attribute) {
            if (isset($attrs[$key])) $opening .= ' ' . $attribute . '="' . $escape((string) $attrs[$key]) . '"';
        }
        if (isset($attrs['triggerIds'])) $opening .= ' data-blocks-engine-triggers="' . $escape(implode(' ', $attrs['triggerIds'])) . '"';
        if (isset($attrs['closerIds'])) $opening .= ' data-blocks-engine-closers="' . $escape(implode(' ', $attrs['closerIds'])) . '"';
        if (isset($attrs['gallerySelection'])) $opening .= ' data-blocks-engine-gallery-selection="' . $escape(json_encode($attrs['gallerySelection'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)) . '"';
        if (isset($attrs['ancestorState'])) $opening .= ' data-blocks-engine-ancestor-state="' . $escape(json_encode($attrs['ancestorState'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)) . '"';
        if (isset($attrs['resting'])) $opening .= ' data-blocks-engine-resting="' . $escape((string) $attrs['resting']) . '"';
        if (isset($attrs['restingState'])) $opening .= ' data-blocks-engine-resting-state="' . $escape(json_encode($attrs['restingState'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)) . '"';
        $opening .= '>';
        // Save a canonical core/group, not a companion block or raw HTML.
        // Only the front-end shell needs the native dialog tag and bindings;
        // its contents remain ordinary editable InnerBlocks in the editor.
        $attributes = array();
        preg_match_all('/ ([a-z][a-z0-9-]*)="([^"]*)"/', $opening, $shellAttributes, PREG_SET_ORDER);
        foreach ($shellAttributes as $attribute) {
            $attributes[$attribute[1]] = html_entity_decode($attribute[2], ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }
        // An adopted panel's shell names no tag: it renders as the panel.
        $shell = $adopted ? array('adopt' => true, 'attributes' => $attributes) : array('tagName' => $tag, 'attributes' => $attributes, 'addCloseButton' => !empty($attrs['addCloseButton']));
        $groupAttrs = array('metadata' => array('blocksEngineDialog' => $shell));
        if (isset($attrs['className'])) $groupAttrs['className'] = $attrs['className'];
        // An adopted panel's block holds its anchor; the shell holds none.
        $anchor = $adopted ? null : ($attrs['dialogId'] ?? null);
        if (null !== $anchor) $groupAttrs['anchor'] = $anchor;
        $groupOpening = '<div' . (null !== $anchor ? ' id="' . $escape($anchor) . '"' : '')
            . ' class="wp-block-group' . (isset($attrs['className']) ? ' ' . $escape($attrs['className']) : '') . '">';
        $innerContent = array($groupOpening);
        foreach ($children as $_) $innerContent[] = null;
        $innerContent[] = '</div>';

        return array(
            'blockName' => 'core/group',
            'attrs' => $groupAttrs,
            'innerBlocks' => $children,
            'innerHTML' => $groupOpening . '</div>',
            'innerContent' => $innerContent,
        );
    }
}
