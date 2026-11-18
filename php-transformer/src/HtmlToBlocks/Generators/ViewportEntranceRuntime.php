<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Generators;

use Automattic\BlocksEngine\PhpTransformer\Contract\CaptureEvidence;

/** Provider-neutral replay of capture entrance evidence; never runs in the editor. */
final class ViewportEntranceRuntime
{
    /**
     * Capture activator semantics (viewport-entrances.ts,
     * revision 51fabbf9).
     * Keep attribute poses, CSS-animation bindings and startup contexts together:
     * the evidence is a replay contract, not an animation-provider heuristic.
     *
     * Evidence may carry `devices`: the device documents whose source plays the
     * entrance. `$viewportQueries` is a JSON object of media queries keyed by
     * device (the site's core viewport queries, the same ones block visibility
     * uses); a device without a query matches the selected device document.
     */
    public static function script(string $viewportQueries = '{}'): string
    {
        return str_replace(array('__ENTRANCE__', '__DEVICE_DOCUMENT__', '__ENTRANCE_LOSS__', '__SELECTED_DOCUMENT__', '__VIEWPORT_QUERIES__'), array(CaptureEvidence::VIEWPORT_ENTRANCE, CaptureEvidence::DEVICE_DOCUMENT, CaptureEvidence::VIEWPORT_ENTRANCE . '-loss', CaptureEvidence::SELECTED_DOCUMENT, $viewportQueries), <<<'JS'
function replayViewportEntrances() {
    const reduced = matchMedia('(prefers-reduced-motion: reduce)');
    const viewportQueries = __VIEWPORT_QUERIES__;
    const selected = document.documentElement.getAttribute('__SELECTED_DOCUMENT__');
    const onDevice = (devices) => devices.some(device => typeof viewportQueries[device] === 'string'
        ? matchMedia(viewportQueries[device]).matches : selected === null || selected === device);
    // Engines enumerate a keyframe's properties in different orders (Chromium
    // changed it between releases), so the binding compares values, not order.
    // Lengths serialize at engine precision (177.140625px reads back as 177.141px,
    // a compiled 177.14px as 177.14px), so numbers compare at two decimals.
    const precise = (number) => Math.round(number * 100) / 100;
    const signature = (value) => JSON.stringify(value, (_key, field) => field && typeof field === 'object' && !Array.isArray(field)
        ? Object.fromEntries(Object.entries(field).sort(([left], [right]) => left < right ? -1 : left > right ? 1 : 0))
        : typeof field === 'number' ? precise(field) : typeof field === 'string' ? field.replace(/\d*\.\d+/g, number => String(precise(Number(number)))) : field);
    for (const element of document.querySelectorAll('[__ENTRANCE__]')) {
        let state;
        try { state = JSON.parse(element.getAttribute('__ENTRANCE__'));
            if (!state || !state.attributes || !Array.isArray(state.threshold)) continue;
        } catch (_) { continue; }
        if (element.closest('[__DEVICE_DOCUMENT__]') && !element.getClientRects().length)
            continue;
        // Class and style poses replay only what differs between the poses, so
        // classes and declarations the compiler emitted stay on the element.
        const tokens = (value) => new Set(String(value ?? '').split(/\s+/).filter(Boolean));
        const declarations = (value) => {
            const probe = document.createElement('div').style;
            probe.cssText = value ?? '';
            return new Map(Array.from(probe, name => [name, [probe.getPropertyValue(name), probe.getPropertyPriority(name)]]));
        };
        const apply = (pose) => {
            for (const [name, values] of Object.entries(state.attributes)) {
                const value = values[pose], other = values[pose === 'before' ? 'after' : 'before'];
                if (name === 'class') {
                    const keep = tokens(value), drop = tokens(other);
                    drop.forEach(token => keep.has(token) || element.classList.remove(token));
                    keep.forEach(token => drop.has(token) || element.classList.add(token));
                } else if (name === 'style') {
                    const keep = declarations(value), drop = declarations(other);
                    drop.forEach((_, property) => keep.has(property) || element.style.removeProperty(property));
                    keep.forEach(([text, priority], property) => drop.get(property)?.join() === [text, priority].join() || element.style.setProperty(property, text, priority));
                } else if (value === null)
                    element.removeAttribute(name);
                else
                    element.setAttribute(name, value);
            }
        };
        // An entrance the source plays only on some devices shows its settled
        // pose on the others.
        if (reduced.matches || !window.IntersectionObserver || (Array.isArray(state.devices) && !onDevice(state.devices))) {
            apply('after');
            continue;
        }
        try {
        apply('before');
        const inline = element.style;
        const transition = inline.getPropertyValue('transition');
        const priority = inline.getPropertyPriority('transition');
        if (!state.cssAnimations)
            inline.setProperty('transition', 'none', 'important');
        // Establish the pending pose before the observer can switch it; this creates
        // real CSS transitions even for entrances already in the initial viewport.
        getComputedStyle(element).opacity;
        if (!state.cssAnimations) {
            if (transition)
                inline.setProperty('transition', transition, priority);
            else
                inline.removeProperty('transition');
        }
        let running = false, complete = false;
        // A lost lifecycle settles on the after pose: the before pose is a
        // hidden pending state, and nothing will ever play it to completion.
        const lose = (reason) => {
            element.setAttribute('__ENTRANCE_LOSS__', reason);
            observer.disconnect();
            running = false;
            complete = true;
            apply('after');
        };
        const start = () => {
            if (running || complete)
                return;
            const effects = element.getAnimations().filter((animation) => animation instanceof CSSAnimation &&
                animation.timeline === document.timeline && state.cssAnimations.some(binding => binding.name === animation.animationName));
            const signatures = effects.map(effect => signature({ name: effect.animationName, timing: effect.effect?.getTiming(), frames: effect.effect.getKeyframes() })).sort();
            if (JSON.stringify(signatures) !== JSON.stringify(state.cssAnimations.map(binding => signature(binding)).sort()) ||
                effects.some(effect => effect.playState !== 'paused' || effect.currentTime !== 0)) {
                lose('authored paused CSS animation binding changed');
                return;
            }
            running = true;
            effects.forEach(effect => effect.play());
            void Promise.all(effects.map(effect => effect.finished)).then(() => {
                apply('after');
                complete = true;
                running = false;
                if (!state.repeat)
                    observer.unobserve(element);
            }, () => lose('CSS entrance completion was interrupted'));
        };
        const observer = new IntersectionObserver(entries => {
            for (const entry of entries) {
                if (entry.isIntersecting && (!state.cssAnimations || entry.intersectionRatio >= state.threshold[0])) {
                    if (state.cssAnimations)
                        start();
                    else {
                        apply('after');
                        if (!state.repeat)
                            observer.unobserve(element);
                    }
                }
                else if (!entry.isIntersecting && state.repeat && !running) {
                    // `repeatSides` limits the reset to the sides the source
                    // replays from; without it the entrance resets on both.
                    const side = entry.boundingClientRect.top >= (entry.rootBounds?.top ?? 0) ? 'below' : 'above';
                    if (!Array.isArray(state.repeatSides) || state.repeatSides.includes(side)) {
                        apply('before');
                        complete = false;
                    }
                }
            }
        }, { rootMargin: state.rootMargin, threshold: state.threshold });
        observer.observe(element);
        if (state.repeat) {
            // A jump straight across the viewport never intersects, so the
            // observer would keep the side the element first left on.
            const sideOf = () => {
                const rect = element.getBoundingClientRect();
                return rect.bottom <= 0 ? 'above' : rect.top >= innerHeight ? 'below' : null;
            };
            let lastSide = sideOf();
            addEventListener('scroll', () => {
                const side = sideOf();
                if (side && lastSide && side !== lastSide) {
                    observer.unobserve(element);
                    observer.observe(element);
                }
                lastSide = side;
            }, { passive: true });
        }
        if (state.cssAnimations && state.startupContexts) {
            const startup = state.startupContexts.find(pose => pose.width === document.documentElement.clientWidth && pose.height === document.documentElement.clientHeight);
            if (startup?.started)
                start();
            else if (!startup)
                element.setAttribute('__ENTRANCE_LOSS__', 'CSS startup context was not observed');
        }
        reduced.addEventListener('change', event => {
            if (event.matches) {
                observer.disconnect();
                apply('after');
            }
        });
        } catch (_) { apply('after'); }
    }
}

if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', replayViewportEntrances, { once: true });
else replayViewportEntrances();
JS);
    }
}
