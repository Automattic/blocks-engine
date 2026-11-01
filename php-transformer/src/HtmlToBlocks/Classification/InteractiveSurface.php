<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Classification;

use Automattic\BlocksEngine\PhpTransformer\Contract\CaptureEvidence;
use DOMElement;
use DOMXPath;

/** The first declared classification owns the surface, irrespective of its paint. */
final class InteractiveSurface
{
    /** @return array{kind:string, source:string}|null */
    public static function classify(DOMElement $element): ?array
    {
        foreach (array(CaptureEvidence::GALLERY => 'carousel', CaptureEvidence::GALLERY_STAGE => 'carousel', CaptureEvidence::LISTBOX_PANEL => 'listbox', CaptureEvidence::DIALOG_PANEL => 'dialog') as $attribute => $kind) {
            if ($element->hasAttribute($attribute)) return array('kind' => $kind, 'source' => 'evidence');
        }
        if ('true' === $element->getAttribute('data-blocks-engine-captured-dialog')) return array('kind' => 'dialog', 'source' => 'evidence');
        $role = strtolower(trim($element->getAttribute('role')));
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
        // One indexed lookup instead of a document walk per element.
        $controls = (new DOMXPath($element->ownerDocument))->query('//*[@aria-haspopup="listbox" and contains(concat(" ", normalize-space(@aria-controls), " "), " ' . $id . ' ")]');
        return false !== $controls && $controls->length > 0 ? array('kind' => 'listbox', 'source' => 'semantics') : null;
    }
}
