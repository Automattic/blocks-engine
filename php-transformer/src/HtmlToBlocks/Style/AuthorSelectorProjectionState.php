<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style;

use Closure;
use DOMElement;

/** Per-transform source identities projected from author CSS selectors. */
final class AuthorSelectorProjectionState
{
    private ?AuthorStyleAnalysis $authorStyles = null;

    /** @var array<string, string> */
    private array $tagMarkers = array();

    /** @var array<string, string> */
    private array $controlMarkers = array();

    /** @var array<string, true> */
    private array $buttonPresentationPaths = array();

    /** @var array<string, true> */
    private array $buttonLabelPaths = array();

    /** @var array<string, true> */
    private array $controlPaths = array();

    /**
     * Node paths of menu toggles the transformer drops in favour of Core's
     * native overlay control, and of everything inside them. These never reach
     * the output, so a type selector whose only subjects sit here has nothing
     * to address but the chrome Core renders in their place.
     *
     * @var array<string, true>
     */
    private array $supersededControlPaths = array();

    /** @var array<string, string> */
    private array $semanticMarkers = array();

    /** @var array<string, string> */
    private array $attributeMarkers = array();

    /** @var array<string, array<string, string>> */
    private array $stableAttributeMarkers = array();

    /** @var array<string, list<string>> */
    private array $runtimeAttributeSelectorMarkers = array();

    /** @var array<string, string> */
    private array $attributeNegationMarkers = array();

    /** @var array<string, list<string>> */
    private array $attributeStateMarkers = array();

    /** @var array<string, array<string, string>> Author selector => ancestor attribute condition text => state class. */
    private array $ancestorAttributeStateConditions = array();

    /** @var array<string, list<string>> Source path => class-only ancestor attribute-state markers. */
    private array $ancestorAttributeStateMarkers = array();

    /** @var array<string, array<string, true>> Holder key => visited source paths. */
    private array $ancestorAttributeStateVisits = array();

    /** @var array<string, true> Holder keys with at least one marked holder. */
    private array $ancestorAttributeStateHolders = array();

    /** @var array<string, bool> */
    private array $scriptWrittenAttributes = array();

    /** @var array<string, string> */
    private array $rootChildMarkers = array();

    /** @var array<string, string> */
    private array $mediaTextImageMarkers = array();

    /** @var array<string, string> */
    private array $imageWrapperMarkers = array();

    /** @var array<string, string> */
    private array $imageLinkMarkers = array();

    /**
     * Source image path => the core/image figure that now carries its class
     * list and id. core/image saves both on the <figure>, never on the <img>,
     * so an author subject that names the image by class or id has to follow
     * them there.
     *
     * @var array<string, array{classes: list<string>, anchor: string, linked: bool}>
     */
    private array $imageFigures = array();

    /** @var array<string, string> */
    private array $tableMarkers = array();

    /** @var array<int, bool> */
    private array $tableRepresentability = array();

    /** @var array<int, array<int, string>> */
    private array $tableDescendantPaths = array();

    /** @var array<string, string> */
    private array $richTextMarkers = array();

    /** @var array<string, true> */
    private array $inlineLayoutCarrierPaths = array();

    /** @var array<string, true> Source boxes retained verbatim by a layout shell. */
    private array $retainedSourcePaths = array();

    /** @var array<string, true> */
    private array $navigationItemAnchorPaths = array();

    /**
     * Source list items core/navigation renders as its own items, each with
     * whether its rendered siblings are exactly its source list's items.
     *
     * @var array<string, bool>
     */
    private array $navigationListItemPaths = array();

    /** @var array<string, true> Source lists that are the element a core/navigation block stands in for. */
    private array $navigationListHostPaths = array();

    public function installAuthorStyles(AuthorStyleAnalysis $authorStyles): void
    {
        $this->authorStyles = $authorStyles;
    }

    public function ensureTagMarker(string $tagName): string
    {
        $tagName = strtolower($tagName);
        return $this->tagMarkers[$tagName] ??= $this->allocateMarker('source-' . $tagName);
    }

    public function tagMarker(string $tagName): string
    {
        return $this->tagMarkers[strtolower($tagName)] ?? '';
    }

    /** @return array<string, string> */
    public function tagMarkers(): array
    {
        return $this->tagMarkers;
    }

    public function markControlPath(string $path): void
    {
        $this->controlPaths[$path] = true;
    }

    public function isControlPath(string $path): bool
    {
        return isset($this->controlPaths[$path]);
    }

    public function markSupersededControlPath(string $path): void
    {
        if ( '' !== $path ) {
            $this->supersededControlPaths[$path] = true;
        }
    }

    public function isSupersededControlPath(string $path): bool
    {
        return isset($this->supersededControlPaths[$path]);
    }

    public function ensureControlMarker(string $path): string
    {
        return $this->controlMarkers[$path] ??= $this->allocateMarker('control');
    }

    public function controlMarker(string $path): string
    {
        return $this->controlMarkers[$path] ?? '';
    }

    public function installButtonPresentationMarker(string $path, string $marker): void
    {
        $this->controlMarkers[$path] = $marker;
        $this->buttonPresentationPaths[$path] = true;
    }

    public function isButtonPresentationPath(string $path): bool
    {
        return isset($this->buttonPresentationPaths[$path]);
    }

    /** Register the RichText surface that replaces an unwrapped button label. */
    public function installButtonLabelPath(string $path): void
    {
        $this->buttonLabelPaths[$path] = true;
        $this->ensureRichTextMarker($path);
    }

    public function isButtonLabelPath(string $path): bool
    {
        return isset($this->buttonLabelPaths[$path]);
    }

    public function installImageLinkMarker(string $id, string $path): void
    {
        $this->imageLinkMarkers[$id] = $this->ensureSemanticMarker($path);
    }

    /** @return array<string, string> */
    public function imageLinkMarkers(): array
    {
        return $this->imageLinkMarkers;
    }

    /** Remember which figure a source image became, and what identity the figure carries. */
    public function recordImageFigure(string $path, string $className, string $anchor, bool $linked): void
    {
        if ( '' === $path ) {
            return;
        }
        $this->imageFigures[$path] = array(
            'classes' => array_values(array_filter(preg_split('/\s+/', trim($className)) ?: array(), static fn (string $class): bool => '' !== $class)),
            'anchor' => $anchor,
            'linked' => $linked,
        );
    }

    /** @return array{classes: list<string>, anchor: string, linked: bool}|null */
    public function imageFigure(string $path): ?array
    {
        return $this->imageFigures[$path] ?? null;
    }

    public function ensureImageWrapperMarker(string $path): string
    {
        return $this->imageWrapperMarkers[$path] ??= $this->allocateMarker('semantic');
    }

    public function imageWrapperMarker(string $path): string
    {
        return $this->imageWrapperMarkers[$path] ?? '';
    }

    public function ensureSemanticMarker(string $path): string
    {
        return $this->semanticMarkers[$path] ??= $this->allocateMarker('semantic');
    }

    public function semanticMarker(string $path): string
    {
        return $this->semanticMarkers[$path] ?? '';
    }

    public function ensureRichTextMarker(string $path): string
    {
        return $this->richTextMarkers[$path] ??= $this->allocateMarker('richtext');
    }

    public function richTextMarker(string $path): string
    {
        return $this->richTextMarkers[$path] ?? '';
    }

    public function hasRichTextMarkers(): bool
    {
        return array() !== $this->richTextMarkers;
    }

    public function markInlineLayoutCarrierPath(string $path): void
    {
        $this->inlineLayoutCarrierPaths[$path] = true;
    }

    public function isInlineLayoutCarrierPath(string $path): bool
    {
        return isset($this->inlineLayoutCarrierPaths[$path]);
    }

    public function markRetainedSourcePath(string $path): void
    {
        $this->retainedSourcePaths[$path] = true;
    }

    public function isRetainedSourcePath(string $path): bool
    {
        return isset($this->retainedSourcePaths[$path]);
    }

    /**
     * Record a source anchor that core/navigation renders inside a list item
     * of its own, so the anchor's position among its source siblings now
     * belongs to that item.
     */
    public function markNavigationItemAnchor(DOMElement $anchor): void
    {
        $path = $anchor->getNodePath() ?? '';
        if ( '' !== $path ) {
            $this->navigationItemAnchorPaths[$path] = true;
        }
    }

    public function isNavigationItemAnchorPath(string $path): bool
    {
        return isset($this->navigationItemAnchorPaths[$path]);
    }

    /**
     * The stable identity for the marker a `>` attribute selector places on
     * the subject's PARENT. It differs from the subject's identity (the bare
     * selector), so the parent and the subject get different marker classes:
     * the projected subject form `:where(.marker)` must not also select the
     * parent, or the child's declarations (`width:100%`) land on the wrapper.
     */
    public static function parentAttributeIdentity(string $selector): string
    {
        // Hash input only (never emitted); the NUL keeps it apart from any real selector text.
        return "parent-of\0" . $selector;
    }

    /**
     * Record a source `<li>` that core/navigation-link or core/navigation-submenu
     * renders as `<li class="wp-block-navigation-item">`. The source-type marker
     * other list items carry never reaches that rendered item, so a rule
     * authored on the `li` has to address the class core puts there.
     */
    public function markNavigationListItem(DOMElement $item, bool $rendersSourceSiblings = true): void
    {
        $path = $item->getNodePath() ?? '';
        if ( '' !== $path ) {
            $this->navigationListItemPaths[$path] = $rendersSourceSiblings;
        }
    }

    public function isNavigationListItemPath(string $path): bool
    {
        return isset($this->navigationListItemPaths[$path]);
    }

    /**
     * Whether the rendered item's container holds exactly the items of its
     * source list, in source order. Not so when core gathers the items of
     * two source lists into one container: there the last item of the first
     * list has a rendered sibling it had no source sibling for.
     */
    public function navigationListItemRendersSourceSiblings(string $path): bool
    {
        return true === ( $this->navigationListItemPaths[$path] ?? false );
    }

    /**
     * Record a source `<ul>`/`<ol>` that is itself the element a core/navigation
     * block replaces. WordPress renders that block as a `<nav>` carrying the
     * list's classes and id, and copies them onto an inner `<ul>`; a selector
     * qualified by the list type reaches only that inner copy.
     */
    public function markNavigationListHost(DOMElement $list): void
    {
        $path = $list->getNodePath() ?? '';
        if ( '' !== $path ) {
            $this->navigationListHostPaths[$path] = true;
        }
    }

    public function isNavigationListHostPath(string $path): bool
    {
        return isset($this->navigationListHostPaths[$path]);
    }

    public function ensureAttributeMarker(string $path, ?string $stableIdentity = null): string
    {
        if ( null !== $stableIdentity ) {
            return $this->stableAttributeMarkers[$path][$stableIdentity]
                ??= $this->allocateStableAttributeMarker($stableIdentity);
        }
        return $this->attributeMarkers[$path] ??= $this->allocateMarker('attribute');
    }

    private function allocateStableAttributeMarker(string $identity): string
    {
        return ($this->authorStyles
            ?? throw new \LogicException('Author styles have not been installed for selector projection.'))
            ->allocateStableMarker('attribute', $identity);
    }

    public function attributeMarker(string $path, ?string $stableIdentity = null): string
    {
        if ( null !== $stableIdentity ) {
            return $this->stableAttributeMarkers[$path][$stableIdentity] ?? '';
        }
        return $this->attributeMarkers[$path] ?? (array_values($this->stableAttributeMarkers[$path] ?? array())[0] ?? '');
    }

    /** @param list<string> $markers */
    public function installRuntimeAttributeSelectorMarkers(string $selector, array $markers): void
    {
        $this->runtimeAttributeSelectorMarkers[$selector] = array_values(array_unique(array_filter($markers)));
    }

    /** @return array<string, list<string>> */
    public function runtimeAttributeSelectorMarkers(): array
    {
        return $this->runtimeAttributeSelectorMarkers;
    }

    public function isRuntimeAttributePath(string $path): bool
    {
        $pathMarkers = array_values(array_filter(array_merge(
            array($this->attributeMarkers[$path] ?? ''),
            array_values($this->stableAttributeMarkers[$path] ?? array())
        )));
        if ( array() === $pathMarkers ) {
            return false;
        }

        foreach ( $this->runtimeAttributeSelectorMarkers as $runtimeMarkers ) {
            if ( array() !== array_intersect($pathMarkers, $runtimeMarkers) ) {
                return true;
            }
        }

        return false;
    }

    public function installAttributeNegationMarker(string $selector, string $marker): void
    {
        $this->attributeNegationMarkers[$selector] = $marker;
    }

    public function attributeNegationMarker(string $selector): string
    {
        return $this->attributeNegationMarkers[$selector] ?? '';
    }

    /** @return array<string,string> */
    public function attributeNegationMarkers(): array
    {
        return $this->attributeNegationMarkers;
    }

    /** @param array<string, string> $conditions Condition text => state class; empty records a decision not to project. */
    public function installAncestorAttributeStateConditions(string $selector, array $conditions): void
    {
        $this->ancestorAttributeStateConditions[$selector] = $conditions;
    }

    public function hasAncestorAttributeStateDecision(string $selector): bool
    {
        return isset($this->ancestorAttributeStateConditions[$selector]);
    }

    /** @return array<string, string> */
    public function ancestorAttributeStateConditions(string $selector): array
    {
        return $this->ancestorAttributeStateConditions[$selector] ?? array();
    }

    public function hasAncestorAttributeStateConditions(): bool
    {
        foreach ( $this->ancestorAttributeStateConditions as $conditions ) {
            if ( array() !== $conditions ) {
                return true;
            }
        }
        return false;
    }

    /** Records a visit; false when `$path` was already walked for this holder key. */
    public function visitAncestorAttributeStatePath(string $holderKey, string $path): bool
    {
        if ( isset($this->ancestorAttributeStateVisits[$holderKey][$path]) ) {
            return false;
        }
        $this->ancestorAttributeStateVisits[$holderKey][$path] = true;
        return true;
    }

    public function addAncestorAttributeStateMarker(string $path, string $marker, string $holderKey): void
    {
        $this->ancestorAttributeStateHolders[$holderKey] = true;
        if ( ! in_array($marker, $this->ancestorAttributeStateMarkers[$path] ?? array(), true) ) {
            $this->ancestorAttributeStateMarkers[$path][] = $marker;
        }
    }

    public function hasAncestorAttributeStateHolder(string $holderKey): bool
    {
        return isset($this->ancestorAttributeStateHolders[$holderKey]);
    }

    /**
     * Class-only markers: emitted on the block's className, never consulted
     * for wrapper preservation or layout ownership.
     *
     * @return list<string>
     */
    public function ancestorAttributeStateMarkers(string $path): array
    {
        return $this->ancestorAttributeStateMarkers[$path] ?? array();
    }

    /** @param \Closure(): bool $resolve */
    public function scriptWritesAttribute(string $name, \Closure $resolve): bool
    {
        return $this->scriptWrittenAttributes[$name] ??= $resolve();
    }

    public function addAttributeStateMarker(string $path, string $marker): void
    {
        $this->attributeStateMarkers[$path][] = $marker;
    }

    /** @return list<string> */
    public function attributeStateMarkers(string $path): array
    {
        return $this->attributeStateMarkers[$path] ?? array();
    }

    /** @return list<string> Attribute-identity and attribute-state classes owned by selector projection. */
    public function sourceAttributeSelectorMarkers(string $path): array
    {
        return array_values(array_unique(array_filter(array_merge(
            array($this->attributeMarkers[$path] ?? ''),
            array_values($this->stableAttributeMarkers[$path] ?? array()),
            $this->attributeStateMarkers($path)
        ))));
    }

    public function ensureRootChildMarker(string $path): string
    {
        return $this->rootChildMarkers[$path] ??= $this->allocateMarker('root-child');
    }

    public function rootChildMarker(string $path): string
    {
        return $this->rootChildMarkers[$path] ?? '';
    }

    public function ensureMediaTextImageMarker(string $path): string
    {
        return '' === $path ? '' : ($this->mediaTextImageMarkers[$path] ??= $this->allocateMarker('media-text-image'));
    }

    public function mediaTextImageMarker(string $path): string
    {
        return $this->mediaTextImageMarkers[$path] ?? '';
    }

    /** @return list<string> */
    public function semanticMarkersForPath(string $path): array
    {
        return array_values(array_filter(array_merge(
            array($this->semanticMarker($path), $this->attributeMarkers[$path] ?? ''),
            array_values($this->stableAttributeMarkers[$path] ?? array()),
            $this->attributeStateMarkers($path),
            array($this->rootChildMarker($path))
        ), static fn (string $marker): bool => '' !== $marker));
    }

    public function ensureTableMarker(string $path): string
    {
        return $this->tableMarkers[$path] ??= $this->allocateMarker('table');
    }

    public function tableMarker(string $path): string
    {
        return $this->tableMarkers[$path] ?? '';
    }

    /** @param Closure(): bool $resolve */
    public function tableRepresentable(int $tableId, Closure $resolve): bool
    {
        return $this->tableRepresentability[$tableId] ??= $resolve();
    }

    /** @param Closure(): array<int, string> $buildPaths */
    public function tableDescendantPath(int $tableId, int $elementId, Closure $buildPaths): string
    {
        $paths = $this->tableDescendantPaths[$tableId] ??= $buildPaths();
        return $paths[$elementId] ?? '';
    }

    private function allocateMarker(string $kind): string
    {
        return ($this->authorStyles
            ?? throw new \LogicException('Author styles have not been installed for selector projection.'))
            ->allocateMarker($kind);
    }
}
