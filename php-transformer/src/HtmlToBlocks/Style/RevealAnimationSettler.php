<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style;

/**
 * Settle captured entrance animations that can never reach their end state.
 *
 * A reveal animation is only ever a way of arriving at a resting appearance.
 * Sources drive one from script — Wix pauses `animation: motion-fadeIn … backwards
 * paused` until its runtime stamps `data-motion-enter="done"`, older themes hold
 * `opacity:0` until an IntersectionObserver adds an in-view class. Capture can also
 * hand the animation to a scroll-driven timeline (`animation-timeline: view()`).
 * Import carries the CSS and drops the driver, so the animation is left in a state
 * it cannot leave: `animation-fill-mode: backwards` pins the element to the `0%`
 * keyframe, and with a paused play state or a timeline that never becomes active
 * that keyframe is forever. When `0%` is `opacity: 0`, the content is in the DOM,
 * in the block markup, and permanently unpainted (#239).
 *
 * The governing rule is that a captured reveal must never leave content less
 * visible than its own settled end state. So an animation that cannot progress is
 * not carried as a live animation at all: it is replaced by the resolved state it
 * was travelling towards — its `100%`/`to` keyframe — which is what a visitor of
 * the source would have seen.
 *
 * Deliberately narrow. An animation that can still run on the document timeline is
 * untouched, and so is one whose start keyframe is no less visible than its end
 * (a paused marquee or spinner keeps its own declarations, since settling a
 * cyclical animation to its last frame would move content rather than reveal it).
 */
final class RevealAnimationSettler
{
    /**
     * End-keyframe declarations worth restating. These are the properties a
     * reveal animates on its way to the resting appearance; anything else the
     * keyframes touch is left to the element's own cascade once the animation
     * is gone.
     */
    private const SETTLED_PROPERTIES = array(
        'clip-path' => true,
        'filter' => true,
        'opacity' => true,
        'rotate' => true,
        'scale' => true,
        'transform' => true,
        'translate' => true,
        'visibility' => true,
        '-webkit-transform' => true,
    );

    /** Shorthand tokens that are never an `animation-name`. */
    private const RESERVED_ANIMATION_KEYWORDS = array(
        'alternate' => true,
        'alternate-reverse' => true,
        'backwards' => true,
        'both' => true,
        'ease' => true,
        'ease-in' => true,
        'ease-in-out' => true,
        'ease-out' => true,
        'forwards' => true,
        'infinite' => true,
        'inherit' => true,
        'initial' => true,
        'linear' => true,
        'normal' => true,
        'paused' => true,
        'reverse' => true,
        'revert' => true,
        'revert-layer' => true,
        'running' => true,
        'step-end' => true,
        'step-start' => true,
        'unset' => true,
    );

    /**
     * Repair rules for every rule in the stylesheet that leaves content parked
     * in a reveal animation's hidden start state.
     *
     * @return list<string>
     */
    public function settleRules(string $css): array
    {
        if ( 1 !== preg_match('/(?:^|[;{\s])animation(?:-[a-z-]+)?\s*:/i', $css) ) {
            return array();
        }
        $keyframes = $this->keyframeBoundaryStates($css);
        if ( array() === $keyframes ) {
            return array();
        }

        $settled = array();
        ( new CssStylesheetTransformer() )->visitStyleRules(
            $css,
            function (string $prelude, string $body) use ($keyframes, &$settled): void {
                $endState = $this->suspendedRevealEndState($this->declarations($body), $keyframes);
                if ( null === $endState ) {
                    return;
                }
                foreach ( CssStylesheetTransformer::splitSelectorList($prelude) ?? array() as $selector ) {
                    $selector = $this->settleableSelector($selector);
                    if ( '' === $selector ) {
                        continue;
                    }
                    foreach ( $endState as $property => $value ) {
                        $settled[$selector][$property] = $value;
                    }
                }
            }
        );

        ksort($settled, SORT_STRING);
        $rules = array();
        foreach ( $settled as $selector => $declarations ) {
            ksort($declarations, SORT_STRING);
            $body = '';
            foreach ( $declarations as $property => $value ) {
                $body .= $property . ':' . $value . '!important;';
            }
            $rules[] = ':root ' . $selector . '{' . rtrim($body, ';') . '}';
        }

        return $rules;
    }

    /**
     * The resolved resting declarations for a rule whose animation cannot
     * complete, or null when the rule is safe as authored.
     *
     * @param array<string, string> $declarations
     * @param array<string, array{start: array<string, string>, end: array<string, string>}> $keyframes
     * @return array<string, string>|null
     */
    private function suspendedRevealEndState(array $declarations, array $keyframes): ?array
    {
        $animation = $this->animationConfiguration($declarations);
        if ( array() === $animation['names'] || ! $animation['suspended'] || ! $animation['fills_before'] ) {
            return null;
        }

        foreach ( $animation['names'] as $name ) {
            $frames = $keyframes[$name] ?? null;
            if ( null === $frames || ! $this->hidesAtStart($frames['start'], $frames['end']) ) {
                continue;
            }
            $settled = array( 'animation' => 'none' );
            foreach ( $frames['end'] as $property => $value ) {
                if ( isset(self::SETTLED_PROPERTIES[ $property ]) ) {
                    $settled[ $property ] = $value;
                }
            }

            return $settled;
        }

        return null;
    }

    /**
     * Resolve the animation names a rule applies, whether the animation can
     * still progress, and whether its before-phase state is what the element
     * actually shows.
     *
     * @param array<string, string> $declarations
     * @return array{names: list<string>, suspended: bool, fills_before: bool}
     */
    private function animationConfiguration(array $declarations): array
    {
        $names = array();
        $paused = false;
        $fillMode = '';
        $delay = 0.0;

        $shorthand = trim((string) ($declarations['animation'] ?? ''));
        if ( '' !== $shorthand ) {
            foreach ( CssValueSplitter::splitTopLevel($shorthand, array( ',' )) as $layer ) {
                $layerName = '';
                $times = array();
                foreach ( CssValueSplitter::splitTopLevelWhitespace($layer) as $token ) {
                    $lower = strtolower($token);
                    $seconds = $this->timeSeconds($lower);
                    if ( null !== $seconds ) {
                        $times[] = $seconds;
                        continue;
                    }
                    if ( in_array($lower, array( 'none', 'forwards', 'backwards', 'both' ), true) ) {
                        $fillMode = $lower;
                        continue;
                    }
                    if ( 'paused' === $lower ) {
                        $paused = true;
                        continue;
                    }
                    if ( 'running' === $lower ) {
                        $paused = false;
                        continue;
                    }
                    if ( isset(self::RESERVED_ANIMATION_KEYWORDS[ $lower ]) || is_numeric($lower) || str_contains($token, '(') ) {
                        continue;
                    }
                    if ( '' === $layerName ) {
                        $layerName = $token;
                    }
                }
                if ( isset($times[1]) ) {
                    $delay = $times[1];
                }
                if ( '' !== $layerName ) {
                    $names[] = $layerName;
                }
            }
        }

        $longhandNames = trim((string) ($declarations['animation-name'] ?? ''));
        if ( '' !== $longhandNames ) {
            $names = array();
            foreach ( CssValueSplitter::splitTopLevel($longhandNames, array( ',' )) as $name ) {
                if ( 'none' !== strtolower($name) ) {
                    $names[] = $name;
                }
            }
        }
        if ( isset($declarations['animation-play-state']) ) {
            $paused = in_array('paused', CssValueSplitter::splitTopLevel(strtolower($declarations['animation-play-state']), array( ',' )), true);
        }
        if ( isset($declarations['animation-fill-mode']) ) {
            $fillMode = strtolower(trim((string) (CssValueSplitter::splitTopLevel($declarations['animation-fill-mode'], array( ',' ))[0] ?? '')));
        }
        if ( isset($declarations['animation-delay']) ) {
            $delay = $this->timeSeconds(strtolower(trim((string) (CssValueSplitter::splitTopLevel($declarations['animation-delay'], array( ',' ))[0] ?? '')))) ?? $delay;
        }

        // A scroll-driven timeline has no document clock behind it. Whether it
        // ever advances depends on a scroll container the conversion does not
        // control, and on this import it does not: `getAnimations()` reports the
        // animation running while its progress — and the element's opacity —
        // stays at zero.
        $timeline = strtolower(trim((string) ($declarations['animation-timeline'] ?? '')));
        $timelineDriven = '' !== $timeline && ! in_array($timeline, array( 'auto', 'none', 'initial', 'inherit', 'unset', 'revert', 'revert-layer' ), true);

        return array(
            'names' => $names,
            'suspended' => $paused || $timelineDriven,
            // The before phase is what the element shows either because the fill
            // mode paints it there, or because a non-positive delay leaves the
            // stalled animation inside its active phase at time zero.
            'fills_before' => in_array($fillMode, array( 'backwards', 'both' ), true) || $delay <= 0.0,
        );
    }

    /**
     * @param array<string, string> $start
     * @param array<string, string> $end
     */
    private function hidesAtStart(array $start, array $end): bool
    {
        $startVisibility = strtolower(trim((string) ($start['visibility'] ?? '')));
        if ( in_array($startVisibility, array( 'hidden', 'collapse' ), true) && $startVisibility !== strtolower(trim((string) ($end['visibility'] ?? ''))) ) {
            return true;
        }
        if ( 'none' === strtolower(trim((string) ($start['display'] ?? ''))) && 'none' !== strtolower(trim((string) ($end['display'] ?? ''))) ) {
            return true;
        }

        $startOpacity = $this->opacity($start['opacity'] ?? null);
        if ( null === $startOpacity ) {
            return false;
        }
        $endOpacity = $this->opacity($end['opacity'] ?? null);

        // An end opacity that is absent or computed (`var(--comp-opacity, 1)`)
        // resolves to something the element itself decides, which by definition
        // is at least as visible as a start that dims it.
        return null === $endOpacity ? $startOpacity < 1.0 : $startOpacity < $endOpacity;
    }

    /**
     * Index every `@keyframes` rule by name, keeping the declarations of its
     * first and last offsets.
     *
     * @return array<string, array{start: array<string, string>, end: array<string, string>}>
     */
    private function keyframeBoundaryStates(string $css): array
    {
        $transformer = new CssStylesheetTransformer();
        $keyframes = array();
        $transformer->visitKeyframeRules(
            $css,
            function (string $name, string $body) use ($transformer, &$keyframes): void {
                $frames = $keyframes[ $name ] ?? array( 'start' => array(), 'end' => array() );
                $transformer->visitStyleRules(
                    $body,
                    function (string $prelude, string $declarationBlock) use (&$frames): void {
                        $declarations = $this->declarations($declarationBlock);
                        if ( array() === $declarations ) {
                            return;
                        }
                        foreach ( CssStylesheetTransformer::splitSelectorList($prelude) ?? array() as $offset ) {
                            $offset = strtolower(trim($offset));
                            if ( 'from' === $offset || '0%' === $offset ) {
                                $frames['start'] = array_merge($frames['start'], $declarations);
                            } elseif ( 'to' === $offset || '100%' === $offset ) {
                                $frames['end'] = array_merge($frames['end'], $declarations);
                            }
                        }
                    }
                );
                $keyframes[ $name ] = $frames;
            }
        );

        return $keyframes;
    }

    /** @return array<string, string> */
    private function declarations(string $body): array
    {
        $declarations = array();
        foreach ( CssValueSplitter::splitTopLevel($body, array( ';' )) as $declaration ) {
            $separator = strpos($declaration, ':');
            if ( false === $separator || 0 === $separator ) {
                continue;
            }
            $property = strtolower(trim(substr($declaration, 0, $separator)));
            $value = trim(substr($declaration, $separator + 1));
            $value = trim((string) preg_replace('/!\s*important\s*$/i', '', $value));
            if ( '' !== $property && '' !== $value ) {
                $declarations[ $property ] = $value;
            }
        }

        return $declarations;
    }

    /**
     * A selector the repair can safely restate. Pseudo-elements are skipped —
     * they are decoration rather than the imported content that must stay
     * readable — and so is a selector carrying an interior comment, which
     * cannot be dropped without risking a changed combinator.
     */
    private function settleableSelector(string $selector): string
    {
        $selector = trim((string) preg_replace('#^(?:\s*/\*.*?\*/\s*)+|(?:\s*/\*.*?\*/\s*)+$#s', '', trim($selector)));

        return '' === $selector || str_contains($selector, '::') || str_contains($selector, '/*') ? '' : $selector;
    }

    private function opacity(?string $value): ?float
    {
        $value = strtolower(trim((string) $value));
        if ( 1 === preg_match('/^([0-9]*\.?[0-9]+)%$/', $value, $match) ) {
            return (float) $match[1] / 100.0;
        }

        return 1 === preg_match('/^[0-9]*\.?[0-9]+$/', $value) ? (float) $value : null;
    }

    private function timeSeconds(string $token): ?float
    {
        if ( 1 !== preg_match('/^(-?[0-9]*\.?[0-9]+)(m?s)$/', $token, $match) ) {
            return null;
        }

        return 'ms' === $match[2] ? (float) $match[1] / 1000.0 : (float) $match[1];
    }
}
