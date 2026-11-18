<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style;

use Automattic\BlocksEngine\PhpTransformer\Contract\CaptureEvidence;
use Automattic\BlocksEngine\PhpTransformer\Css\CssStylesheetTransformer;
use Automattic\BlocksEngine\PhpTransformer\Css\CssValueSplitter;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Session\HtmlTransformerSession;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Support\SourceDom;
use DOMElement;

/**
 * A box-tree route never ships the author sheet, so a scroll-driven animation
 * the capture observed running (`animation-name` in its used styles) loses
 * its keyframes and timeline: the carrier restates the name alone, which
 * names no keyframes, and the copy registers no animation at all.
 *
 * Scroll-driven animations have no lifecycle for the viewport-entrance
 * runtime to replay; their author binding is the whole behaviour. Restate
 * exactly that binding on a carrier: the animation and timeline
 * declarations of the author rules matching the element, with the custom
 * properties they read substituted under the conditions that define them,
 * the custom properties the keyframes read, and the keyframes themselves.
 * The frozen pose the animation produced at capture (its animated
 * properties) is then the animation's to paint, not the carrier's.
 */
final class BoxTreeScrollAnimationBinding
{
    private const BINDING = '/^(?:animation(?:-[a-z-]+)?|view-timeline(?:-[a-z-]+)?|scroll-timeline(?:-[a-z-]+)?|timeline-scope)$/';

    /** @var list<array{selectors: list<string>, declarations: array<string, string>, conditions: list<string>}>|null */
    private ?array $rules = null;

    /** @var array<string, string>|null Keyframes body by animation name. */
    private ?array $keyframes = null;

    private string $css = '';

    public function __construct(private readonly HtmlTransformerSession $session)
    {
    }

    /**
     * The restated binding for an observed scroll-driven animation, or null.
     *
     * @param array<string, array<string, mixed>> $observations Box-tree observations by viewport.
     * @param array<string, array<string, mixed>> $viewports Capture viewports by key, with their widths.
     * @return array{rules: array<string, array<string, string>>, keyframes: array<string, string>, animated: list<string>}|null
     *         Declarations by condition prelude ('' for none), keyframes by name, and the properties they animate.
     */
    public function binding(DOMElement $element, array $observations, array $viewports): ?array
    {
        if ($element->hasAttribute(CaptureEvidence::VIEWPORT_ENTRANCE)) return null;
        $observed = array();
        $running = array();
        foreach ($observations as $key => $observation) {
            foreach (CssValueSplitter::splitTopLevel((string) ($observation['used_styles']['animation-name'] ?? 'none'), array(',')) as $name) {
                if ('' !== trim($name) && 'none' !== trim($name)) $observed[trim($name)] = $running[$key] = true;
            }
        }
        if (array() === $observed) return null;
        $band = $this->band($observations, $viewports, $running);
        if (null === $band) return null;
        $this->load();
        // Matching declarations in source order, each with its conditions.
        $entries = array();
        foreach ($this->rules as $rule) {
            $matches = false;
            foreach ($rule['selectors'] as $selector) {
                if ($this->session->sourceStyleResolutionState()->matches($element, $selector)) { $matches = true; break; }
            }
            if (!$matches) continue;
            foreach ($rule['declarations'] as $property => $value) $entries[] = array($rule['conditions'], (string) $property, $value);
        }
        foreach (SourceDeclarations::verbatim(SourceDom::attr($element, 'style')) as $property => $value) $entries[] = array(array(), (string) $property, (string) $value);
        $definitions = array();
        foreach ($entries as [$conditions, $property, $value]) {
            if (str_starts_with($property, '--')) $definitions[$property] = array($conditions, $value);
        }
        $rules = array();
        $names = array();
        $scrollDriven = false;
        foreach ($entries as [$conditions, $property, $value]) {
            if (!preg_match(self::BINDING, strtolower($property))) continue;
            $resolved = $this->substitute($value, $definitions, $conditions);
            if (null === $resolved) continue;
            [$value, $conditions] = $resolved;
            if ('' !== $band) array_unshift($conditions, $band);
            $key = implode("\0", $conditions);
            unset($rules[$key][$property]);
            $rules[$key][$property] = $value;
            if (in_array(strtolower($property), array('animation', 'animation-name'), true)) {
                foreach (CssValueSplitter::splitTopLevel($value, array(',')) as $layer) {
                    foreach (CssValueSplitter::splitTopLevelWhitespace($layer) as $token) {
                        if (isset($this->keyframes[$token])) $names[$token] = true;
                    }
                }
            }
            if ('animation-timeline' === strtolower($property) && !in_array(strtolower(trim($value)), array('auto', 'none', 'initial', 'unset'), true)) $scrollDriven = true;
        }
        $names = array_intersect_key($names, $observed);
        if (!$scrollDriven || array() === $names) return null;
        $keyframes = array_intersect_key($this->keyframes, $names);
        // Custom properties the keyframes read come from the element itself.
        preg_match_all('/\bvar\(\s*(--[-_a-zA-Z0-9]+)/', implode('', $keyframes), $read);
        foreach (array_unique($read[1]) as $name) {
            if (!isset($definitions[$name])) continue;
            $resolved = $this->substitute($definitions[$name][1], $definitions, $definitions[$name][0]);
            if (null === $resolved) continue;
            if ('' !== $band) array_unshift($resolved[1], $band);
            $rules[implode("\0", $resolved[1])][$name] = $resolved[0];
        }
        preg_match_all('/(?:^|[;{])\s*([a-z-]+)\s*:/i', implode('', $keyframes), $animated);
        $animated = array_values(array_diff(array_unique(array_map('strtolower', $animated[1])), array('animation-timing-function', 'animation-composition')));
        return array('rules' => $rules, 'keyframes' => $keyframes, 'animated' => $animated);
    }

    /**
     * The widths at which the capture saw the animation running, in the
     * bands the box-tree projector gives each capture: the widest capture
     * holds above the next narrower one, and each narrower capture holds up
     * to its own width. '' when every capture saw it; null when the running
     * captures do not form one contiguous band.
     *
     * @param array<string, true> $running Viewport keys whose capture observed the animation.
     */
    private function band(array $observations, array $viewports, array $running): ?string
    {
        $keys = array_keys($observations);
        foreach ($keys as $key) {
            if (!isset($viewports[$key]['width'])) return null;
        }
        if (count($running) === count($keys)) return '';
        usort($keys, static fn(string $a, string $b): int => $viewports[$b]['width'] <=> $viewports[$a]['width']);
        $min = null;
        $max = null;
        foreach ($keys as $index => $key) {
            if (!isset($running[$key])) continue;
            if (null !== $min && $min !== $index - 1) return null;
            $max ??= $index;
            $min = $index;
        }
        $terms = array();
        if (isset($keys[$min + 1])) $terms[] = '(min-width:' . ((int) $viewports[$keys[$min + 1]]['width'] + 1) . 'px)';
        if ($max > 0) $terms[] = '(max-width:' . (int) $viewports[$keys[$max]]['width'] . 'px)';
        return '@media ' . implode(' and ', $terms);
    }

    /**
     * Restate `$binding` on a carrier class.
     *
     * @param array{rules: array<string, array<string, string>>, keyframes: array<string, string>, animated: list<string>} $binding
     */
    public function carrier(DOMElement $element, array $binding, LayoutGeometryState $state): string
    {
        $css = '';
        $class = "\0class\0";
        foreach ($binding['rules'] as $conditions => $declarations) {
            $body = array();
            foreach ($declarations as $property => $value) {
                if (preg_match('/[{}<>;]/', $value) || str_contains($value, '/*')) continue;
                $body[] = $property . ':' . trim($value);
            }
            if (array() === $body) continue;
            $rule = '.' . $class . '{' . implode(';', $body) . '}';
            foreach (array_reverse('' === $conditions ? array() : explode("\0", $conditions)) as $condition) $rule = $condition . '{' . $rule . '}';
            $css .= $rule;
        }
        if ('' === $css) return '';
        $className = $state->allocateCarrier(SourceDom::elementSelector($element) . "\nscroll-animation\n" . $css);
        $state->registerRule($className, str_replace($class, $className, $css));
        foreach ($binding['keyframes'] as $name => $body) $state->registerKeyframes($className, $name, '@keyframes ' . $name . '{' . trim($body) . '}');
        return $className;
    }

    /**
     * Substitute every `var()` in `$value` from the element's own
     * definitions. A substituted definition holds only where its conditions
     * hold, so they join the reader's. Null when a reference has neither a
     * single-condition definition nor a fallback: the declaration is
     * invalid at computed-value time and the property takes its initial
     * value, which is what omitting it gives.
     *
     * @param array<string, array{0: list<string>, 1: string}> $definitions
     * @param list<string> $conditions
     * @return array{0: string, 1: list<string>}|null
     */
    private function substitute(string $value, array $definitions, array $conditions, int $depth = 0): ?array
    {
        if (!str_contains($value, 'var(')) return array(trim($value), $conditions);
        if ($depth > 8 || !preg_match('/var\(\s*(--[-_a-zA-Z0-9]+)\s*(?:,((?:[^()]|\((?:[^()]|\([^()]*\))*\))*))?\)/', $value, $match, PREG_OFFSET_CAPTURE)) return null;
        $name = $match[1][0];
        if (isset($definitions[$name])) {
            $replacement = $definitions[$name][1];
            $conditions = array_values(array_unique(array_merge($conditions, $definitions[$name][0])));
        } elseif (isset($match[2]) && -1 !== $match[2][1]) {
            $replacement = $match[2][0];
        } else {
            return null;
        }
        $value = substr($value, 0, $match[0][1]) . trim($replacement) . substr($value, $match[0][1] + strlen($match[0][0]));
        return $this->substitute($value, $definitions, $conditions, $depth + 1);
    }

    private function load(): void
    {
        $css = $this->session->authorStyleAnalysis()->combinedCss();
        if (null !== $this->rules && $css === $this->css) return;
        $this->css = $css;
        $this->rules = array();
        $this->keyframes = array();
        $transformer = new CssStylesheetTransformer();
        $transformer->visitStyleRules($css, function (string $selector, string $body, array $ancestors): void {
            $declarations = SourceDeclarations::verbatim($body);
            $relevant = array_filter($declarations, static fn (string $value, int|string $property): bool => str_starts_with((string) $property, '--') || 1 === preg_match(self::BINDING, strtolower((string) $property)), ARRAY_FILTER_USE_BOTH);
            if (array() === $relevant) return;
            $this->rules[] = array(
                'selectors' => array_values(array_filter(array_map('trim', CssValueSplitter::splitTopLevel($selector, array(','))))),
                'declarations' => $relevant,
                'conditions' => array_values(array_map('trim', $ancestors)),
            );
        });
        $transformer->visitKeyframeRules($css, function (string $name, string $body): void {
            $this->keyframes[$name] = $body;
        });
    }
}
