<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Support;

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Patterns\NavigationPattern;
use DOMDocument;
use DOMElement;
use DOMNode;

/**
 * Finds a captured mobile menu that repeats a menu the page already shows.
 *
 * A header often draws its menu twice: a row of items for wide screens, and a
 * hamburger that opens a panel with the same items for narrow screens. When the
 * capture records that panel as a dialog, the importer used to keep it as a
 * separate hamburger button plus a custom dialog block, next to a navigation
 * block that still showed no hamburger.
 *
 * This class decides when the dialog is only a second copy of a row whose
 * items open dropdown panels. The decision is read from the markup alone: every
 * entry in the dialog (a link, or an accordion button) must name an item the
 * row already has, and at least one entry must be an accordion that matches a
 * dropdown item. When that holds, the row's navigation can take over the
 * hamburger and the panel can be dropped.
 */
final class CapturedMenuDialogFold
{
    /**
     * @param DOMElement $row The menu row whose items open dropdown panels.
     * @param list<DOMElement> $triggers The controls that open the dialog.
     * @param DOMElement $dialog The captured dialog.
     * @param list<DOMElement> $suppressed Source elements the folded output no longer needs.
     * @param array<string, true> $entrySignatures What the dialog lists, keyed `link:label|href` or `button:label`.
     * @param list<array{label:string, href:string}> $linkEntries The dialog's plain link entries, in order.
     * @param DOMElement|null $group The hamburger's wrapper, when it only repeated controls the row already has.
     * @param bool $hamburgerLast Whether the hamburger came after those controls in that wrapper.
     */
    private function __construct(
        public readonly DOMElement $row,
        public readonly array $triggers,
        public readonly DOMElement $dialog,
        public readonly array $suppressed,
        public readonly array $entrySignatures,
        public readonly array $linkEntries,
        public readonly ?DOMElement $group,
        public readonly bool $hamburgerLast
    ) {
    }

    /** @return list<self> */
    public static function detect(DOMElement $root): array
    {
        $document = $root->ownerDocument;
        if ( ! $document instanceof DOMDocument ) {
            return array();
        }

        $folds = array();
        foreach ( iterator_to_array($root->getElementsByTagName('dialog')) as $dialog ) {
            if ( ! $dialog instanceof DOMElement || 'true' !== $dialog->getAttribute('data-blocks-engine-captured-menu') ) {
                continue;
            }
            $triggers = array();
            foreach ( preg_split('/\s+/', trim($dialog->getAttribute('data-blocks-engine-triggers'))) ?: array() as $id ) {
                $trigger = '' === $id ? null : $document->getElementById($id);
                if ( $trigger instanceof DOMElement ) {
                    $triggers[] = $trigger;
                }
            }
            $entries = self::entries($dialog, 0);
            if ( array() === $triggers || null === $entries || array() === $entries ) {
                continue;
            }

            $row = self::equivalentRow($root, $dialog, $triggers, $entries);
            if ( ! $row instanceof DOMElement ) {
                continue;
            }

            $signatures = array();
            $links = array();
            foreach ( $entries as $entry ) {
                $signatures[self::signature($entry['kind'], $entry['label'], $entry['href'])] = true;
                if ( 'link' === $entry['kind'] ) {
                    $links[] = array( 'label' => $entry['label'], 'href' => $entry['href'] );
                }
            }
            $suppressed = array( $dialog );
            $group = self::redundantTriggerGroup($row, $triggers, $dialog);
            if ( $group instanceof DOMElement ) {
                $suppressed[] = $group;
            } else {
                array_push($suppressed, ...$triggers);
            }
            $folds[] = new self($row, $triggers, $dialog, $suppressed, $signatures, $links, $group, $group instanceof DOMElement && self::followsControls($group, $triggers[0]));
        }

        return $folds;
    }

    /** Whether the dialog lists this control: a link by label and href, or every link and button inside it. */
    public function listsControl(DOMElement $control): bool
    {
        $tag = strtolower($control->tagName);
        if ( 'a' === $tag ) {
            return isset($this->entrySignatures[self::signature('link', self::label($control), trim($control->getAttribute('href')))]);
        }
        if ( 'button' === $tag ) {
            return isset($this->entrySignatures[self::signature('button', self::label($control), '')]);
        }

        $found = 0;
        foreach ( $control->getElementsByTagName('*') as $descendant ) {
            if ( ! $descendant instanceof DOMElement || ! in_array(strtolower($descendant->tagName), array( 'a', 'button' ), true) ) {
                continue;
            }
            if ( ! $this->listsControl($descendant) ) {
                return false;
            }
            ++$found;
        }

        return 0 < $found;
    }

    public static function signature(string $kind, string $label, string $href): string
    {
        return $kind . ':' . $label . ( 'link' === $kind ? '|' . $href : '' );
    }

    public static function label(DOMElement $element): string
    {
        $text = '';
        foreach ( $element->childNodes as $child ) {
            $text .= self::textWithoutArtwork($child);
        }

        return strtolower(trim(preg_replace('/\s+/', ' ', $text) ?? ''));
    }

    private static function textWithoutArtwork(DOMNode $node): string
    {
        if ( $node instanceof DOMElement ) {
            if ( in_array(strtolower($node->tagName), array( 'svg', 'script', 'style' ), true) ) {
                return '';
            }
            $text = '';
            foreach ( $node->childNodes as $child ) {
                $text .= self::textWithoutArtwork($child);
            }

            return $text;
        }

        return XML_TEXT_NODE === $node->nodeType ? (string) $node->textContent : '';
    }

    /**
     * What a dialog lists, as plain links and accordion buttons. A dialog with
     * anything else in it is not a plain menu copy.
     *
     * @return list<array{kind:string, label:string, href:string, children:list<array{label:string, href:string}>}>|null
     */
    private static function entries(DOMElement $container, int $depth): ?array
    {
        if ( 4 < $depth ) {
            return null;
        }
        $entries = array();
        foreach ( $container->childNodes as $child ) {
            if ( XML_COMMENT_NODE === $child->nodeType || ( XML_TEXT_NODE === $child->nodeType && '' === trim($child->textContent ?? '') ) ) {
                continue;
            }
            if ( ! $child instanceof DOMElement ) {
                return null;
            }
            $tag = strtolower($child->tagName);
            if ( 'a' === $tag ) {
                $label = self::label($child);
                $href = trim($child->getAttribute('href'));
                if ( '' === $label || '' === $href ) {
                    return null;
                }
                $entries[] = array( 'kind' => 'link', 'label' => $label, 'href' => $href, 'children' => array() );
                continue;
            }
            if ( 'button' === $tag ) {
                $label = self::label($child);
                if ( '' === $label ) {
                    return null;
                }
                $entries[] = array( 'kind' => 'button', 'label' => $label, 'href' => '', 'children' => array() );
                continue;
            }
            if ( ! in_array($tag, array( 'div', 'span', 'li', 'ul', 'ol', 'nav' ), true) ) {
                return null;
            }

            $button = null;
            foreach ( $child->childNodes as $grandchild ) {
                if ( $grandchild instanceof DOMElement && 'button' === strtolower($grandchild->tagName) ) {
                    $button = $grandchild;
                    break;
                }
            }
            if ( ! $button instanceof DOMElement ) {
                $nested = self::entries($child, $depth + 1);
                if ( null === $nested ) {
                    return null;
                }
                array_push($entries, ...$nested);
                continue;
            }

            $label = self::label($button);
            if ( '' === $label ) {
                return null;
            }
            $children = array();
            foreach ( $child->getElementsByTagName('a') as $anchor ) {
                if ( $anchor instanceof DOMElement ) {
                    $children[] = array( 'label' => self::label($anchor), 'href' => trim($anchor->getAttribute('href')) );
                }
            }
            $entries[] = array( 'kind' => 'button', 'label' => $label, 'href' => '', 'children' => $children );
        }

        return $entries;
    }

    /**
     * The one row that already has every entry. Entries that are accordion
     * buttons must match a dropdown item by label (and, when the capture opened
     * the accordion, by its links); links must match a link in the row by label
     * and href.
     *
     * @param list<DOMElement> $triggers
     * @param list<array{kind:string, label:string, href:string, children:list<array{label:string, href:string}>}> $entries
     */
    private static function equivalentRow(DOMElement $root, DOMElement $dialog, array $triggers, array $entries): ?DOMElement
    {
        $matches = array();
        foreach ( $root->getElementsByTagName('*') as $candidate ) {
            if ( ! $candidate instanceof DOMElement || SourceDom::elementContains($dialog, $candidate) || $candidate->isSameNode($dialog) ) {
                continue;
            }
            foreach ( $triggers as $trigger ) {
                if ( $candidate->isSameNode($trigger) || SourceDom::elementContains($candidate, $trigger) ) {
                    continue 2;
                }
            }
            $dropdowns = self::dropdownItems($candidate);
            if ( array() === $dropdowns ) {
                continue;
            }
            if ( self::rowHasEntries($candidate, $dropdowns, $entries) ) {
                $matches[] = $candidate;
            }
        }

        // Rows nest: a wrapper around the row also matches. The innermost one is the row.
        foreach ( $matches as $index => $match ) {
            foreach ( $matches as $other ) {
                if ( ! $match->isSameNode($other) && SourceDom::elementContains($match, $other) ) {
                    unset($matches[$index]);
                    break;
                }
            }
        }

        return 1 === count($matches) ? array_values($matches)[0] : null;
    }

    /** @return array<string, list<array{label:string, href:string}>> dropdown label => its links */
    private static function dropdownItems(DOMElement $row): array
    {
        $items = array();
        foreach ( $row->childNodes as $child ) {
            if ( ! $child instanceof DOMElement ) {
                continue;
            }
            $parts = NavigationPattern::buttonDropdownItemParts($child);
            if ( null === $parts ) {
                continue;
            }
            $links = array();
            foreach ( $parts['cluster']->getElementsByTagName('a') as $anchor ) {
                if ( $anchor instanceof DOMElement ) {
                    $links[] = array( 'label' => self::label($anchor), 'href' => trim($anchor->getAttribute('href')) );
                }
            }
            $items[strtolower($parts['label'])] = $links;
        }

        return $items;
    }

    /**
     * @param array<string, list<array{label:string, href:string}>> $dropdowns
     * @param list<array{kind:string, label:string, href:string, children:list<array{label:string, href:string}>}> $entries
     */
    private static function rowHasEntries(DOMElement $row, array $dropdowns, array $entries): bool
    {
        $anchors = array();
        foreach ( $row->getElementsByTagName('a') as $anchor ) {
            if ( $anchor instanceof DOMElement ) {
                $anchors[self::signature('link', self::label($anchor), trim($anchor->getAttribute('href')))] = true;
            }
        }
        $buttons = array();
        foreach ( $row->getElementsByTagName('button') as $button ) {
            if ( $button instanceof DOMElement ) {
                $buttons[self::label($button)] = true;
            }
        }

        $matchedDropdown = false;
        foreach ( $entries as $entry ) {
            if ( 'link' === $entry['kind'] ) {
                if ( ! isset($anchors[self::signature('link', $entry['label'], $entry['href'])]) ) {
                    return false;
                }
                continue;
            }
            if ( isset($dropdowns[$entry['label']]) ) {
                $panelLinks = array_map(static fn (array $link): string => $link['label'] . '|' . $link['href'], $dropdowns[$entry['label']]);
                foreach ( $entry['children'] as $child ) {
                    if ( ! in_array($child['label'] . '|' . $child['href'], $panelLinks, true) ) {
                        return false;
                    }
                }
                $matchedDropdown = true;
                continue;
            }
            if ( ! isset($buttons[$entry['label']]) ) {
                return false;
            }
        }

        return $matchedDropdown;
    }

    /**
     * The hamburger's own wrapper, when everything else in it is something the
     * row already shows (a second copy of a language switcher, say). Dropping
     * the wrapper then leaves one copy of each control. Returns null when the
     * wrapper holds anything the row does not, so only the hamburger goes.
     *
     * @param list<DOMElement> $triggers
     */
    private static function redundantTriggerGroup(DOMElement $row, array $triggers, DOMElement $dialog): ?DOMElement
    {
        $trigger = $triggers[0];
        $group = $trigger;
        while ( $group->parentNode instanceof DOMElement && ! SourceDom::elementContains($group->parentNode, $row) ) {
            $group = $group->parentNode;
        }
        if ( $group->isSameNode($trigger) || SourceDom::elementContains($group, $dialog) || SourceDom::elementContains($group, $row) ) {
            return null;
        }
        foreach ( $triggers as $other ) {
            if ( ! SourceDom::elementContains($group, $other) ) {
                return null;
            }
        }

        $rowLinks = array();
        foreach ( $row->getElementsByTagName('a') as $anchor ) {
            if ( $anchor instanceof DOMElement ) {
                $rowLinks[self::signature('link', self::label($anchor), trim($anchor->getAttribute('href')))] = true;
            }
        }
        $rowButtons = array();
        foreach ( $row->getElementsByTagName('button') as $button ) {
            if ( $button instanceof DOMElement ) {
                $rowButtons[self::label($button)] = true;
            }
        }

        foreach ( $group->getElementsByTagName('*') as $descendant ) {
            if ( ! $descendant instanceof DOMElement ) {
                continue;
            }
            foreach ( $triggers as $other ) {
                if ( $descendant->isSameNode($other) || SourceDom::elementContains($other, $descendant) ) {
                    continue 2;
                }
            }
            $tag = strtolower($descendant->tagName);
            if ( 'a' === $tag ) {
                if ( ! isset($rowLinks[self::signature('link', self::label($descendant), trim($descendant->getAttribute('href')))]) ) {
                    return null;
                }
            } elseif ( 'button' === $tag ) {
                if ( ! isset($rowButtons[self::label($descendant)]) ) {
                    return null;
                }
            } elseif ( in_array($tag, array( 'img', 'svg', 'picture', 'video', 'input', 'select', 'textarea', 'form', 'iframe', 'canvas' ), true) ) {
                if ( ! self::insideMatchedControl($descendant, $group) ) {
                    return null;
                }
            }
        }

        // Text outside every link and button must be bare punctuation, such as
        // the divider between two switcher buttons.
        return self::onlyPunctuationOutsideControls($group, $triggers) ? $group : null;
    }

    /** Whether a link or button of the wrapper comes before the hamburger in document order. */
    private static function followsControls(DOMElement $group, DOMElement $trigger): bool
    {
        foreach ( $group->getElementsByTagName('*') as $descendant ) {
            if ( ! $descendant instanceof DOMElement ) {
                continue;
            }
            if ( $descendant->isSameNode($trigger) ) {
                return false;
            }
            if ( in_array(strtolower($descendant->tagName), array( 'a', 'button' ), true) ) {
                return true;
            }
        }

        return false;
    }

    private static function insideMatchedControl(DOMElement $element, DOMElement $group): bool
    {
        for ( $node = $element->parentNode; $node instanceof DOMElement && ! $node->isSameNode($group); $node = $node->parentNode ) {
            if ( in_array(strtolower($node->tagName), array( 'a', 'button' ), true) ) {
                return true;
            }
        }

        return false;
    }

    /** @param list<DOMElement> $triggers */
    private static function onlyPunctuationOutsideControls(DOMNode $node, array $triggers): bool
    {
        foreach ( $node->childNodes as $child ) {
            if ( $child instanceof DOMElement ) {
                if ( in_array(strtolower($child->tagName), array( 'a', 'button', 'svg' ), true) ) {
                    continue;
                }
                if ( ! self::onlyPunctuationOutsideControls($child, $triggers) ) {
                    return false;
                }
                continue;
            }
            if ( XML_TEXT_NODE === $child->nodeType && 1 === preg_match('/[\p{L}\p{N}]/u', (string) $child->textContent) ) {
                return false;
            }
        }

        return true;
    }
}
