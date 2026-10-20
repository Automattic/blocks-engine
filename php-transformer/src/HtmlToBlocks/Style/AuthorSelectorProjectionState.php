<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style;

use Closure;
use Automattic\BlocksEngine\PhpTransformer\Support\EngineMarker;
use DOMElement;

/** Per-transform source identities projected from author CSS selectors. */
final class AuthorSelectorProjectionState
{
    /** Roles a source element plays in the core/navigation block that replaces it. */
    public const NAVIGATION_ANCHOR = 'anchor';
    public const NAVIGATION_ITEM = 'item';
    public const NAVIGATION_LIST_HOST = 'list-host';

    private ?AuthorStyleAnalysis $authorStyles = null;

    /** @var array<string, string> */
    private array $tagMarkers = array();

    /** @var array<string, string> */
    private array $controlMarkers = array();

    /**
     * Marker carriers that received subject classes, by role kind (`control`,
     * `semantic`, `richtext`) and marker: the source tag and class tokens the subject
     * classes state, or null when the source element could not be resolved.
     *
     * @var array<string, array<string, array{tag: string, classes: array<string, true>}|null>>
     */
    private array $subjectCarriers = array();

    /**
     * Markers some carrier block was emitted for without subject classes
     * (see routesOutsideRoleArms()). A marker in both maps may sit on blocks
     * with and without them, so its own arm stays.
     *
     * @var array<string, array<string, true>>
     */
    private array $withheldSubjectCarriers = array();

    /**
     * RichText markers whose every carrier in the emitted block markup was
     * seen with its role and subject classes (see confirmRichTextCarriers()).
     * RichText carriers are also emitted by paths that never ask for those
     * classes (a format span keeping its data attribute, a block attribute a
     * render callback restates), so only a marker confirmed here counts as
     * covered by a subject arm.
     *
     * @var array<string, true>
     */
    private array $confirmedRichTextCarriers = array();

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

    /** @var array<string, true> Author selectors kept live because a source script owns their data state. */
    private array $runtimeOwnedSelectors = array();

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

    /**
     * Source elements core/navigation renders as something other than
     * themselves, by role:
     *
     * - NAVIGATION_ANCHOR: a direct anchor core re-parents into a list item of
     *   its own, so its position among its source siblings belongs to that item.
     * - NAVIGATION_ITEM: a source `<li>` core renders as `<li class="wp-block-navigation-item">`,
     *   without the source-type marker; the flag records whether its rendered
     *   siblings are exactly its source list's items.
     * - NAVIGATION_LIST_HOST: a source `<ul>`/`<ol>` that is itself the element
     *   the navigation block stands in for. Its flag is false when projection
     *   moves the list inside a control-owned native overlay instead.
     *
     * @var array<string, array<string, bool>>
     */
    private array $navigationSourcePaths = array();

    /** @var array<string, string> Source subject path => its exact native CSS target. */
    private array $navigationSubjects = array();

    public function installNavigationSubject(string $path, string $selector): void
    {
        $this->navigationSubjects[$path] ??= $selector;
    }

    public function navigationSubject(string $path): string
    {
        return $this->navigationSubjects[$path] ?? '';
    }

    /** @var array<string,true> Source classes that mark the served route's navigation item. */
    private array $currentNavigationClasses = array();

    /**
     * Record the route state of a source navigation item: the classes its item
     * and anchor carry that their resting peers do not.
     *
     * @param list<string> $classes
     */
    public function markCurrentNavigationClasses(array $classes): void
    {
        foreach ($classes as $class) $this->currentNavigationClasses[$class] = true;
    }

    public function isCurrentNavigationClass(string $class): bool
    {
        return isset($this->currentNavigationClasses[$class]);
    }

    /** Whether a selector addresses the served route's current-item state. */
    public function selectsCurrentNavigationState(string $selector): bool
    {
        if (1 === preg_match('/\[\s*aria-current\b/i', $selector)) return true;
        preg_match_all('/\.((?:\\.|[A-Za-z0-9_-])+)/', $selector, $classes);
        foreach ($classes[1] as $class) {
            if (isset($this->currentNavigationClasses[stripslashes($class)])) return true;
        }
        return false;
    }

    /**
     * A navigation subject while its item is the served route. Shared menus
     * render every item on every route; WordPress marks the current one with
     * aria-current, so a source current-state rule follows that hook rather
     * than the item the source snapshot happened to select.
     */
    public static function currentNavigationSubject(string $subject): string
    {
        return (string) preg_replace('/\.wp-block-navigation-item(?![\w-])/', '.wp-block-navigation-item:has(.wp-block-navigation-item__content[aria-current])', $subject, 1);
    }

    /** @var array<string,string> Source anchor paths whose identity belongs to Core's rendered a, not li. */
    private array $socialAnchorMarkers = array();

    public function markSocialAnchor(DOMElement $anchor): string
    {
        $path = $anchor->getNodePath() ?? '';
        return $this->socialAnchorMarkers[$path] = $this->ensureSemanticMarker($path);
    }

    public function socialAnchorMarker(string $path): string
    {
        return $this->socialAnchorMarkers[$path] ?? '';
    }

    public function hasSocialAnchors(): bool
    {
        return array() !== $this->socialAnchorMarkers;
    }

    public function installAuthorStyles(AuthorStyleAnalysis $authorStyles): void
    {
        $this->authorStyles = $authorStyles;
    }

    public function ensureTagMarker(string $tagName): string
    {
        $tagName = strtolower($tagName);
        return $this->tagMarkers[$tagName] ??= ($this->authorStyles
            ?? throw new \LogicException('Author styles have not been installed for selector projection.'))
            ->allocateTagMarker($tagName);
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
        return $this->controlMarkers[$path] ??= $this->allocatePathMarker('control', $path);
    }

    public function controlMarker(string $path): string
    {
        return $this->controlMarkers[$path] ?? '';
    }

    /**
     * Classes for the block that carries the control at `$path`: its marker,
     * the control role class, and the subject classes of the source element
     * (see EngineMarker::subjectTagClass()). The carrier is recorded with the
     * tag and class tokens those subject classes were made from, so a projected
     * subject arm can be checked against exactly what the markup carries.
     *
     * @return list<string>
     */
    public function controlCarrierClasses(string $path): array
    {
        return $this->carrierClasses('' === $path ? '' : $this->ensureControlMarker($path), $path);
    }

    /**
     * Classes for a block that carries a semantic marker (a semantic or image
     * wrapper marker) of the source element at `$path`: the marker, the
     * semantic role class and the element's subject classes, recorded as for
     * controlCarrierClasses().
     *
     * @return list<string>
     */
    public function semanticCarrierClasses(string $marker, string $path): array
    {
        return $this->carrierClasses($marker, $path);
    }

    /**
     * Classes for an inline element that carries the RichText marker
     * `$marker`: the richtext role class and the subject classes of its source
     * element, recorded as for controlCarrierClasses(). The marker itself is
     * not among them; the carrier states it in its style or data attribute.
     *
     * @return list<string>
     */
    public function richTextCarrierClasses(string $marker): array
    {
        $path = array_search($marker, $this->richTextMarkers, true);
        return false === $path ? array() : array_values(array_diff($this->carrierClasses($marker, (string) $path), array($marker)));
    }

    /**
     * `$html` with each RichText carrier in it (a `<mark>` stating its marker
     * in its style, a `<span>` in its data attribute) given its
     * richTextCarrierClasses(). Idempotent.
     */
    public function withRichTextCarrierClasses(string $html): string
    {
        if ( ! str_contains($html, 'blocks-engine-richtext-marker') ) {
            return $html;
        }
        return preg_replace_callback('/<(mark|span)\b([^>]*)>/i', function (array $tag): string {
            $form = 'mark' === strtolower($tag[1])
                ? '/\sstyle="[^"]*--blocks-engine-richtext-marker:\s*(blocks-engine-richtext-[a-f0-9]+-\d+)/i'
                : '/\sdata-blocks-engine-richtext-marker="(blocks-engine-richtext-[a-f0-9]+-\d+)"/i';
            $classes = 1 === preg_match($form, $tag[2], $marker) ? $this->richTextCarrierClasses($marker[1]) : array();
            if ( array() === $classes ) {
                return $tag[0];
            }
            if ( 1 !== preg_match('/\sclass="([^"]*)"/i', $tag[2], $class) ) {
                return '<' . $tag[1] . ' class="' . implode(' ', $classes) . '"' . $tag[2] . '>';
            }
            $tokens = array_filter(preg_split('/\s+/', trim($class[1])) ?: array(), static fn (string $token): bool => '' !== $token);
            return '<' . $tag[1] . str_replace($class[0], ' class="' . implode(' ', array_unique(array_merge($tokens, $classes))) . '"', $tag[2]) . '>';
        }, $html) ?? $html;
    }

    /**
     * Record which RichText markers reach the subject arm: those whose every
     * occurrence in the serialized blocks is a `<mark>` or `<span>` carrier
     * holding the role and subject classes richTextCarrierClasses() gave it.
     * Any other occurrence (a carrier emitted without them, a marker kept in
     * a block attribute) leaves the marker on its own per-element arm.
     */
    public function confirmRichTextCarriers(string $markup): void
    {
        $this->confirmedRichTextCarriers = array();
        // A carrier restated in a block attribute (a navigation link label)
        // is JSON-escaped in the block comment.
        $markup = str_replace(array( '\\u003c', '\\u003e', '\\u0022' ), array( '<', '>', '"' ), $markup);
        foreach ( $this->subjectCarriers['richtext'] ?? array() as $marker => $carrier ) {
            $marker = (string) $marker;
            if ( null === $carrier || isset($this->withheldSubjectCarriers['richtext'][$marker]) ) {
                continue;
            }
            $pattern = preg_quote($marker, '/') . '(?!\d)';
            $occurrences = preg_match_all('/' . $pattern . '/', $markup);
            if ( ! is_int($occurrences) || 0 === $occurrences
                || ! preg_match_all('/<(?:mark|span)\b[^>]*' . $pattern . '[^>]*>/i', $markup, $tags) || count($tags[0]) !== $occurrences
            ) {
                continue;
            }
            $expected = array( EngineMarker::roleClass($marker), ...EngineMarker::subjectClasses($carrier['tag'], array_map('strval', array_keys($carrier['classes']))) );
            foreach ( $tags[0] as $tag ) {
                $classes = 1 === preg_match('/\sclass="([^"]*)"/i', $tag, $class) ? preg_split('/\s+/', trim($class[1])) ?: array() : array();
                if ( array() !== array_diff($expected, $classes) ) {
                    continue 2;
                }
            }
            $this->confirmedRichTextCarriers[$marker] = true;
        }
    }

    /** @return list<string> */
    private function carrierClasses(string $marker, string $path): array
    {
        if ( '' === $marker ) {
            return array();
        }
        $role = EngineMarker::roleClass($marker);
        $kind = (string) EngineMarker::markerKind($marker);
        if ( '' === $role ) {
            return array($marker);
        }
        if ( $this->routesOutsideRoleArms($path, $kind) ) {
            $this->withheldSubjectCarriers[$kind][$marker] = true;
            return array($marker, $role);
        }
        if ( ! array_key_exists($marker, $this->subjectCarriers[$kind] ?? array()) ) {
            $element = '' === $path ? null : $this->authorStyles?->sourceElementAtPath($path);
            $this->subjectCarriers[$kind][$marker] = null === $element ? null : array(
                'tag' => strtolower($element->tagName),
                'classes' => array_fill_keys(array_values(array_filter(preg_split('/[\x09\x0A\x0C\x0D\x20]+/', trim($element->getAttribute('class'))) ?: array(), static fn (string $class): bool => '' !== $class)), true),
            );
        }
        $carrier = $this->subjectCarriers[$kind][$marker];
        if ( null === $carrier ) {
            return array($marker, $role);
        }
        return array($marker, $role, ...EngineMarker::subjectClasses($carrier['tag'], array_map('strval', array_keys($carrier['classes']))));
    }

    /**
     * Whether selector projection gives the element at `$path` an arm of its
     * own instead of the plain control or semantic arm: a RichText or inline
     * layout carrier, a toggle Core's overlay supersedes, a source box a shell
     * retains, or something core/navigation or core/social-links renders. Its
     * carrier gets no subject classes, so a subject arm (which some other
     * page's copy of a stylesheet may hold) never reaches it; its rules keep
     * naming its marker. A RichText carrier of `$kind` `richtext` is the one
     * a RichText marker stands for, so for it only the other routes count.
     */
    private function routesOutsideRoleArms(string $path, string $kind): bool
    {
        if ( ('richtext' !== $kind && '' !== $this->richTextMarker($path)) || $this->isInlineLayoutCarrierPath($path) || $this->isSupersededControlPath($path)
            || $this->isRetainedSourcePath($path) || '' !== $this->navigationSubject($path) || '' !== $this->socialAnchorMarker($path)
        ) {
            return true;
        }
        foreach ( $this->navigationSourcePaths as $paths ) {
            if ( isset($paths[$path]) ) {
                return true;
            }
        }
        return false;
    }

    /**
     * The `$kind` carriers whose recorded source tag and classes satisfy a
     * context-free compound (type or `*`, plus classes): `reached`, every
     * marker some block carries with those subject classes, and `covered`,
     * those whose every block carries them. Null when a carrier could not
     * record its source element: then the subject classes do not describe
     * every carrier and a subject arm is unsound.
     *
     * @param array<string, mixed> $compound
     * @return array{reached: list<string>, covered: list<string>}|null
     */
    public function carrierMarkersMatching(string $kind, array $compound): ?array
    {
        $type = is_string($compound['type'] ?? null) ? strtolower($compound['type']) : null;
        $reached = array();
        $covered = array();
        foreach ( $this->subjectCarriers[$kind] ?? array() as $marker => $carrier ) {
            if ( null === $carrier ) {
                return null;
            }
            if ( null !== $type && $type !== $carrier['tag'] ) {
                continue;
            }
            foreach ( $compound['classes'] ?? array() as $class ) {
                if ( ! isset($carrier['classes'][(string) $class]) ) {
                    continue 2;
                }
            }
            $reached[] = (string) $marker;
            if ( ! isset($this->withheldSubjectCarriers[$kind][$marker]) && ('richtext' !== $kind || isset($this->confirmedRichTextCarriers[$marker])) ) {
                $covered[] = (string) $marker;
            }
        }
        return array( 'reached' => $reached, 'covered' => $covered );
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
        return $this->imageWrapperMarkers[$path] ??= $this->allocatePathMarker('semantic', $path);
    }

    public function imageWrapperMarker(string $path): string
    {
        return $this->imageWrapperMarkers[$path] ?? '';
    }

    public function ensureSemanticMarker(string $path): string
    {
        return $this->semanticMarkers[$path] ??= $this->allocatePathMarker('semantic', $path);
    }

    public function semanticMarker(string $path): string
    {
        return $this->semanticMarkers[$path] ?? '';
    }

    public function ensureRichTextMarker(string $path): string
    {
        return $this->richTextMarkers[$path] ??= $this->allocatePathMarker('richtext', $path);
    }

    public function richTextMarker(string $path): string
    {
        return $this->richTextMarkers[$path] ?? '';
    }

    public function hasRichTextMarkers(): bool
    {
        return array() !== $this->richTextMarkers;
    }

    /**
     * The source element at this path became a block of its own (a text-only
     * span emitted as its paragraph), so author rules address it through a
     * block-level semantic marker instead of a carrier or RichText hook.
     */
    public function adoptAsSemanticBlock(string $path): string
    {
        unset($this->richTextMarkers[$path], $this->inlineLayoutCarrierPaths[$path]);
        return $this->ensureSemanticMarker($path);
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
     * Record a source element core/navigation renders in one of the
     * NAVIGATION_* roles. `$rendersSourceSiblings` seeds item provenance;
     * lists start root-owned and can subsequently move into an overlay.
     */
    public function markNavigationSource(DOMElement $element, string $role, bool $rendersSourceSiblings = true): void
    {
        $path = $element->getNodePath() ?? '';
        if ( '' !== $path ) {
            $this->navigationSourcePaths[$role][$path] = $rendersSourceSiblings;
        }
    }

    public function isNavigationSourcePath(string $path, string $role): bool
    {
        return isset($this->navigationSourcePaths[$role][$path]);
    }

    /** A projected opener owns the root; the source list now owns the inner UL. */
    public function projectNavigationListIntoOverlay(DOMElement $element): void
    {
        $path = $element->getNodePath() ?? '';
        if (isset($this->navigationSourcePaths[self::NAVIGATION_LIST_HOST][$path])) $this->navigationSourcePaths[self::NAVIGATION_LIST_HOST][$path] = false;
    }

    public function navigationListOwnsRoot(string $path): bool
    {
        return true === ($this->navigationSourcePaths[self::NAVIGATION_LIST_HOST][$path] ?? false);
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
     * Whether the rendered item's container holds exactly the items of its
     * source list, in source order. Not so when core gathers the items of
     * two source lists into one container: there the last item of the first
     * list has a rendered sibling it had no source sibling for.
     */
    public function navigationListItemRendersSourceSiblings(string $path): bool
    {
        return true === ( $this->navigationSourcePaths[self::NAVIGATION_ITEM][$path] ?? false );
    }

    public function ensureAttributeMarker(string $path, ?string $stableIdentity = null): string
    {
        if ( null !== $stableIdentity ) {
            return $this->stableAttributeMarkers[$path][$stableIdentity]
                ??= $this->allocateStableAttributeMarker($stableIdentity);
        }
        return $this->attributeMarkers[$path] ??= $this->allocatePathMarker('attribute', $path);
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

    /** Records an author selector whose data predicates stay live in the output. */
    public function retainRuntimeOwnedSelector(string $selector): void
    {
        $this->runtimeOwnedSelectors[trim($selector)] = true;
    }

    public function retainsRuntimeOwnedSelector(string $selector): bool
    {
        return isset($this->runtimeOwnedSelectors[trim($selector)]);
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
        return $this->rootChildMarkers[$path] ??= $this->allocatePathMarker('root-child', $path);
    }

    public function rootChildMarker(string $path): string
    {
        return $this->rootChildMarkers[$path] ?? '';
    }

    public function ensureMediaTextImageMarker(string $path): string
    {
        return '' === $path ? '' : ($this->mediaTextImageMarkers[$path] ??= $this->allocatePathMarker('media-text-image', $path));
    }

    public function mediaTextImageMarker(string $path): string
    {
        return $this->mediaTextImageMarkers[$path] ?? '';
    }

    /** @return list<string> */
    public function semanticMarkersForPath(string $path): array
    {
        // A semantic carrier also carries the kind's role class and its
        // source's subject classes, so a rule arm that has to stay off carriers
        // excludes them through one constant class, and a context-free arm
        // reaches them without enumerating every carrier's marker.
        return array_values(array_filter(array_merge(
            $this->semanticCarrierClasses($this->semanticMarker($path), $path),
            array($this->attributeMarkers[$path] ?? ''),
            array_values($this->stableAttributeMarkers[$path] ?? array()),
            $this->attributeStateMarkers($path),
            array($this->rootChildMarker($path))
        ), static fn (string $marker): bool => '' !== $marker));
    }

    /**
     * The table's engine marker. A table converted from the engine's own saved
     * markup already carries one on its figure, and keeping it is what makes
     * that conversion a fixed point: allocating again would re-seed from a page
     * that gained the figure, and the collision check would see the table's own
     * previous marker as taken and step the counter on every pass.
     */
    public function ensureTableMarker(string $path, ?\DOMElement $table = null): string
    {
        return $this->tableMarkers[$path] ??= self::existingTableMarker($table) ?? $this->allocatePathMarker('table', $path);
    }

    private static function existingTableMarker(?\DOMElement $table): ?string
    {
        $figure = $table?->parentNode;
        foreach (array($table, $figure) as $carrier) {
            if ( ! $carrier instanceof \DOMElement ) {
                continue;
            }
            if ( 1 === preg_match('/(?:^|\s)(blocks-engine-table-[a-f0-9]{12}-\d+)(?:\s|$)/', $carrier->getAttribute('class'), $match) ) {
                return $match[1];
            }
        }
        return null;
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

    /**
     * A per-element marker named from the source element's context (see
     * AuthorStyleAnalysis::allocateContextMarker()), so the same element under
     * the same author CSS projects the same class on every page.
     */
    private function allocatePathMarker(string $kind, string $path): string
    {
        return ($this->authorStyles
            ?? throw new \LogicException('Author styles have not been installed for selector projection.'))
            ->allocateContextMarker($kind, $path);
    }
}
