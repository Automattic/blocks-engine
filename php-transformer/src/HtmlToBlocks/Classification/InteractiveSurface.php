<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Classification;

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Support\SourceDom;
use Automattic\BlocksEngine\PhpTransformer\Contract\CaptureEvidence;
use DOMDocument;
use DOMElement;
use DOMXPath;
use WeakMap;

/** The first declared classification owns the surface, irrespective of its paint. */
final class InteractiveSurface
{
    /** @var WeakMap<DOMDocument, array<string, true>>|null Conversion-scoped reverse control index. */
    private static ?WeakMap $listboxTargets = null;

    public static function indexDocument(DOMDocument $document): void
    {
        $targets = array();
        $controls = (new DOMXPath($document))->query('//*[@aria-haspopup="listbox" and @aria-controls]');
        foreach (false === $controls ? array() : $controls as $control) {
            // XPath normalize-space uses only XML whitespace, not all PHP \s.
            foreach (explode(' ', trim((string) preg_replace('/[\x20\x09\x0d\x0a]+/', ' ', $control->getAttribute('aria-controls')), ' ')) as $id) {
                if ('' !== $id) $targets[$id] = true;
            }
        }
        self::$listboxTargets ??= new WeakMap();
        self::$listboxTargets[$document] = $targets;
    }

    public static function releaseDocument(DOMDocument $document): void
    {
        if (null !== self::$listboxTargets) unset(self::$listboxTargets[$document]);
    }

    /** @return array{kind:string, source:string}|null */
    public static function classify(DOMElement $element): ?array
    {
        foreach (array(CaptureEvidence::GALLERY => 'carousel', CaptureEvidence::GALLERY_STAGE => 'carousel', CaptureEvidence::LISTBOX_PANEL => 'listbox', CaptureEvidence::DIALOG_PANEL => 'dialog') as $attribute => $kind) {
            if ($element->hasAttribute($attribute)) return array('kind' => $kind, 'source' => 'evidence');
        }
        if ('true' === $element->getAttribute('data-blocks-engine-captured-dialog')) return array('kind' => 'dialog', 'source' => 'evidence');
        $role = SourceDom::role($element);
        $description = strtolower(trim($element->getAttribute('aria-roledescription')));
        $kind = match (true) {
            'carousel' === $description, 'group' === $role && 'slide' === $description => 'carousel',
            'listbox' === $role, 'select' === strtolower($element->tagName) => 'listbox',
            in_array($role, array('dialog', 'alertdialog'), true), 'dialog' === strtolower($element->tagName), 'true' === strtolower(trim($element->getAttribute('aria-modal'))) => 'dialog',
            default => null,
        };
        if (null !== $kind) return array('kind' => $kind, 'source' => 'semantics');
        $id = $element->getAttribute('id');
        if ('' === $id || null === $element->ownerDocument || str_contains($id, '"')) return null;
        if (isset(self::$listboxTargets[$element->ownerDocument])) {
            return isset(self::$listboxTargets[$element->ownerDocument][$id]) ? array('kind' => 'listbox', 'source' => 'semantics') : null;
        }
        // Outside a conversion, keep standalone queries live for mutable DOMs.
        $controls = (new DOMXPath($element->ownerDocument))->query('//*[@aria-haspopup="listbox" and contains(concat(" ", normalize-space(@aria-controls), " "), " ' . $id . ' ")]');
        return false !== $controls && $controls->length > 0 ? array('kind' => 'listbox', 'source' => 'semantics') : null;
    }
}
