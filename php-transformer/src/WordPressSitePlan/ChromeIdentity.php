<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan;

use Automattic\BlocksEngine\PhpTransformer\Contract\CaptureEvidence;
use Automattic\BlocksEngine\PhpTransformer\Support\BlockAttributes;
use Automattic\BlocksEngine\PhpTransformer\Support\DocumentVariantIds;
use Automattic\BlocksEngine\PhpTransformer\Support\EngineMarker;
use Automattic\BlocksEngine\PhpTransformer\Support\HtmlSerialization;
use Automattic\BlocksEngine\PhpTransformer\Support\RenderEquivalentMarkup;
use Automattic\BlocksEngine\PhpTransformer\Support\ShellLandmarkPolicy;

/**
 * The canonical form two captures of the same chrome share.
 *
 * Each captured page renders its header, footer or panel with per-document
 * detail: generated ids and hashes, the current menu item, scroll and panel
 * snapshot state, and class order. Two captures are the same chrome when
 * their identities are equal, so shared template parts and post chrome are
 * matched on identity rather than on raw markup.
 *
 * Stateless: every method is a pure function of its inputs.
 */
final class ChromeIdentity
{
    public static function document(string $markup, ?string $document = null): string
    {
        // Only generated identity is ignored; authored ids, classes and all
        // content remain significant. Device variants are compared separately.
        $markup = preg_replace('/be-inline-geometry-[a-f0-9]{16}(?:-[a-f0-9]{16})?/', '', $markup) ?? $markup;
        $references = array();
        preg_match_all('/\s(?:href|for|aria-[\w-]+)\s*=\s*([\'\"])(.*?)\1/is', $document ?? $markup, $attributes, PREG_SET_ORDER);
        foreach ($attributes as $attribute) {
            foreach (preg_split('/\s+/', html_entity_decode($attribute[2], ENT_QUOTES | ENT_HTML5)) ?: array() as $value) {
                $references[ltrim($value, '#')] = true;
            }
        }
        $markup = preg_replace_callback('/("(?:anchor|id)"\s*:\s*"|\sid\s*=\s*[\'\"])(yui_\d+_[\w-]+)([\'\"])/', static fn(array $match): string => isset($references[$match[2]]) ? $match[0] : $match[1] . $match[3], $markup) ?? $markup;
        $suffix = (string) DocumentVariantIds::suffixForClass(CaptureEvidence::MOBILE_DOCUMENT_CLASS);
        // Block attribute JSON escapes hyphens; rendered HTML keeps them literal.
        $markup = str_replace(str_replace('--', '\\u002d\\u002d', $suffix), $suffix, $markup);
        $markup = preg_replace('/' . preg_quote($suffix, '/') . '\b/', '', $markup) ?? $markup;
        $markup = preg_replace_callback('/("(?:className|class)"\s*:\s*"|\bclass=")([^"]*)"/', static function (array $match): string {
            $classes = array_values(array_filter(preg_split('/\s+/', trim($match[2])) ?: array(), static fn(string $class): bool => !str_starts_with($class, EngineMarker::EDITOR_ANCHOR_PREFIX)));
            // Squarespace toggles this state after measuring the slide layer.
            if (in_array('sqs-slide-layer-content', $classes, true)) $classes = array_values(array_diff($classes, array('scroll')));
            sort($classes, SORT_STRING);
            return $match[1] . implode(' ', $classes) . '"';
        }, $markup) ?? $markup;
        return $markup;
    }

    /**
     * A captured panel's snapshot (`data-blocks-engine-capture-existing-panel`)
     * is capture evidence of the panel's resting presentation, recorded per
     * route. Its HTML carries that route's current-page state and route-numbered
     * ids (a drawer's active link, `nav-414-navId-mobile`), so routes sharing one
     * drawer record different snapshots. The compiler already projected the
     * panel from it, so the plan carries it as evidence nothing renders. Chrome
     * identity compares the snapshot's structure instead: element names, link
     * targets and text.
     */
    private static function capturedPanelStructureIdentity(string $markup): string
    {
        if (!str_contains($markup, CaptureEvidence::EXISTING_PANEL)) return $markup;
        $name = preg_quote(CaptureEvidence::EXISTING_PANEL, '/');
        // Serialized block attributes escape quotes as \u0022, so the value has none.
        $markup = preg_replace_callback('/"' . $name . '":"([^"]*)"/', static function (array $match): string {
            $snapshot = json_decode('"' . $match[1] . '"');
            return is_string($snapshot) ? '"' . CaptureEvidence::EXISTING_PANEL . '":"' . self::capturedPanelSnapshotStructure($snapshot) . '"' : $match[0];
        }, $markup) ?? $markup;

        return preg_replace_callback('/\s' . $name . '="([^"]*)"/', static fn (array $match): string => ' ' . CaptureEvidence::EXISTING_PANEL . '="' . self::capturedPanelSnapshotStructure(html_entity_decode($match[1], ENT_QUOTES | ENT_HTML5)) . '"', $markup) ?? $markup;
    }

    private static function capturedPanelSnapshotStructure(string $snapshot): string
    {
        $decoded = json_decode($snapshot, true);
        if (!is_array($decoded)) return hash('sha256', $snapshot);
        array_walk_recursive($decoded, static function (&$value, $key): void {
            if ('html' !== $key || !is_string($value)) return;
            $signature = array();
            $walk = static function (\DOMNode $node) use (&$walk, &$signature): void {
                foreach ($node->childNodes as $child) {
                    if ($child instanceof \DOMElement) {
                        $signature[] = '<' . strtolower($child->tagName) . ($child->hasAttribute('href') ? ' ' . $child->getAttribute('href') : '') . '>';
                        $walk($child);
                        $signature[] = '</>';
                    } elseif (XML_TEXT_NODE === $child->nodeType && '' !== trim((string) $child->textContent)) {
                        $signature[] = trim((string) $child->textContent);
                    }
                }
            };
            $walk(HtmlSerialization::parseFragment('<div>' . $value . '</div>'));
            $value = 'structure:' . hash('sha256', implode("\0", $signature));
        });

        return hash('sha256', (string) json_encode($decoded));
    }

    public static function markup(string $markup): string
    {
        // Emission-only landmark transport must not prevent producer/capture matching.
        $markup = str_replace(array(',"className":"blocks-engine-shell-landmark"', ' blocks-engine-shell-landmark'), '', $markup);
        $markup = RestingNavigation::markup($markup, true);
        $markup = self::capturedPanelStructureIdentity($markup);
        $markup = self::normalizeRenderIds($markup);
        $markup = self::withoutScrollStateCarrierIdentity($markup);
        $markup = preg_replace('/\s*' . EngineMarker::patternBody() . '/', '', $markup) ?? $markup;
        $markup = preg_replace('/\s*(?:' . EngineMarker::specificityPatternBody('specificity-class') . '|blocks-engine-disclosure-summary-[a-f0-9]{6,}(?:-\d+)?)/', '', $markup) ?? $markup;
        // A captured dialog's generated id, and its triggers' ids, carry a per-document hash;
        // the capture's own panel handle (id, panel attribute, aria-controls) carries a per-document ordinal.
        $markup = preg_replace('/\b' . preg_quote(CaptureEvidence::DIALOG_CLASS, '/') . '-\d+\b/', CaptureEvidence::DIALOG_CLASS, $markup) ?? $markup;
        $markup = preg_replace('/blocks-engine-dialog-trigger-[a-f0-9]+-(\d+)/', 'blocks-engine-dialog-trigger-$1', $markup) ?? $markup;
        $markup = preg_replace('/blocks-engine-dialog-[a-f0-9]{16}\b/', 'blocks-engine-dialog', $markup) ?? $markup;
        $markup = preg_replace('/\s*be-inline-geometry-[a-f0-9]{16}(?:-[a-f0-9]{16})?/', '', $markup) ?? $markup;
        $markup = preg_replace('/--blocks-engine-richtext-marker:\s*blocks-engine-richtext-[a-f0-9]+-\d+;?/', '', $markup) ?? $markup;
        $markup = preg_replace('/(?:\.\.\/)+assets\//', 'assets/', $markup) ?? $markup;
        if (!preg_match('/<pre\b|white-space\s*:\s*(?:pre|break-spaces)/i', $markup)) $markup = preg_replace('/\s*\n\s*/', "\n", $markup) ?? $markup;
        $markup = preg_replace('/\bblock-[a-f0-9]{16,}\b/', 'block', $markup) ?? $markup;
        $markup = preg_replace('/("url":"[^"#\s]+)#(?:\\\\u0022|[^"\\\\])+/', '$1', $markup) ?? $markup;
        $markup = preg_replace_callback('/"className":"([^"]*)"/', static function (array $match): string {
            $classes = preg_split('/\s+/', trim($match[1])) ?: array();
            sort($classes, SORT_STRING);
            return '"className":"' . implode(' ', $classes) . '"';
        }, $markup) ?? $markup;
        $markup = RestingNavigation::withoutMenuSelection($markup);
        $markup = preg_replace_callback('/\sclass="([^"]*)"/', static fn (array $match): string => ' class="' . implode(' ', array_filter(preg_split('/\s+/', trim($match[1])) ?: array(), static fn (string $class): bool => !self::isInheritedNavigationLinkColor($class))) . '"', $markup) ?? $markup;
        // Block comments were canonicalized as JSON above; only the rendered HTML
        // between them is read as tags.
        $markup = implode('', array_map(static fn (string $piece): string => str_starts_with($piece, '<!--') ? $piece : RenderEquivalentMarkup::canonical($piece, true), preg_split('/(<!--.*?-->)/s', $markup, -1, PREG_SPLIT_DELIM_CAPTURE) ?: array($markup)));
        return ChromeStackingIdentity::normalize(ShellLandmarkPolicy::withoutResponsiveCorrespondenceMarkup($markup));
    }

    private static function withoutScrollStateCarrierIdentity(string $markup): string
    {
        $markup = preg_replace('/<!--\s*(\/?)wp:[a-z0-9-]+\/scroll-state\b/', '<!-- $1wp:group', $markup) ?? $markup;
        $markup = preg_replace('/\s*data-blocks-engine-scroll-state="true"/', '', $markup) ?? $markup;
        $markup = preg_replace('/\s*data-blocks-engine-scroll-state-config="[^"]*"/', '', $markup) ?? $markup;
        $markup = preg_replace('/\s*(?:wp-block-group|blocks-engine-empty-visual-group|blocks-engine-css-owned-layout|' . preg_quote(EngineMarker::EDITOR_ANCHOR_PREFIX, '/') . '[A-Za-z0-9_-]+)\b/', '', $markup) ?? $markup;
        $markup = preg_replace('/class="\s+/', 'class="', $markup) ?? $markup;
        $markup = self::canonicalizeIdentityBlockComments($markup);
        return self::withoutEmptyGroupStyleIdentity($markup);
    }

    /**
     * A resting navigation-link colour of `inherit` asks the link to use its
     * navigation's colour, which is what core navigation renders by default.
     * Whether a page's cascade restated that default does not change the chrome.
     */
    private static function isInheritedNavigationLinkColor(string $class): bool
    {
        static $inherited = null;
        if (null === $inherited) {
            $inherited = array();
            for ($mask = 0; $mask <= 15; ++$mask) $inherited['blocks-engine-navigation-link-color-' . hash('sha256', "inherit\0" . $mask)] = true;
        }
        return isset($inherited[$class]);
    }

    private static function canonicalizeIdentityBlockComments(string $markup): string
    {
        return preg_replace_callback('/<!--\s*wp:(?!\/).*?-->/s', static function (array $match): string {
            if (!preg_match('/^<!--\s*wp:(\S+)\s+(\{.*\})\s*(\/?)-->$/s', $match[0], $parts)) return $match[0];
            $attrs = json_decode($parts[2], true);
            if (!is_array($attrs)) return $match[0];
            unset($attrs['config']);
            if (in_array($attrs['metadata']['name'] ?? null, array('Header', 'Footer'), true) && 1 === count($attrs['metadata'])) unset($attrs['metadata']);
            if (array('typography' => array('lineHeight' => '1')) === ($attrs['style'] ?? null)) unset($attrs['style']);
            foreach (array('margin', 'padding') as $box) {
                if (!is_array($attrs['style']['spacing'][$box] ?? null)) continue;
                foreach ($attrs['style']['spacing'][$box] as $side => $value) if (is_string($value)) $attrs['style']['spacing'][$box][$side] = RenderEquivalentMarkup::canonicalZeroLength($value);
            }
            // Rendered HTML a block carries as an attribute (its content, a
            // label's markup) reads like the HTML between block comments.
            foreach ($attrs as $name => $value) if (is_string($value) && ('content' === $name || preg_match('/<[a-zA-Z]/', $value))) $attrs[$name] = RenderEquivalentMarkup::canonical($value, true);
            if (is_string($attrs['className'] ?? null)) {
                $classes = array_values(array_filter(preg_split('/\s+/', trim($attrs['className'])) ?: array(), static fn(string $class): bool => '' !== $class && 'wp-block-group' !== $class && 'blocks-engine-empty-visual-group' !== $class && 'blocks-engine-css-owned-layout' !== $class && null === EngineMarker::editorAnchorId($class) && !self::isInheritedNavigationLinkColor($class)));
                sort($classes, SORT_STRING);
                if (array() === $classes) unset($attrs['className']); else $attrs['className'] = implode(' ', $classes);
            }
            self::canonicalizeIdentityStyles($attrs);
            self::ksortRecursive($attrs);
            $encoded = BlockAttributes::encode($attrs);
            return '' !== $encoded ? '<!-- wp:' . $parts[1] . ' ' . $encoded . ' ' . $parts[3] . '-->' : $match[0];
        }, $markup) ?? $markup;
    }

    /** Canonicalize declaration strings, leaving Core's structured style objects intact.
     * @param array<string,mixed> $attrs
     */
    private static function canonicalizeIdentityStyles(array &$attrs): void
    {
        foreach ($attrs as $name => &$value) {
            if (CaptureEvidence::VIEWPORT_ENTRANCE === $name) {
                unset($attrs[$name]);
            } elseif (is_array($value)) {
                $hadValues = array() !== $value;
                self::canonicalizeIdentityStyles($value);
                if ($hadValues && array() === $value) unset($attrs[$name]);
            } elseif (is_string($value) && str_contains($value, ':') && ('style' === $name || str_ends_with((string) $name, 'Style'))) {
                $value = RenderEquivalentMarkup::canonicalStyle($value, true);
                if ('' === $value) unset($attrs[$name]);
            }
        }
    }

    /** @param array<string,mixed> $value */
    private static function ksortRecursive(array &$value): void
    {
        ksort($value);
        foreach ($value as &$child) if (is_array($child)) self::ksortRecursive($child);
    }

    private static function withoutEmptyGroupStyleIdentity(string $markup): string
    {
        return preg_replace_callback('/<!-- wp:group (\{[^>]*\}) -->(\s*<[a-z0-9]+[^>]*>\s*<\/[a-z0-9]+>\s*)<!-- \/wp:group -->/', static function (array $match): string {
            $attrs = json_decode($match[1], true);
            if (!is_array($attrs) || !isset($attrs['style'])) return $match[0];
            unset($attrs['style']);
            self::ksortRecursive($attrs);
            $encoded = BlockAttributes::encode($attrs);
            $html = preg_replace('/\sstyle="[^"]*"/', '', $match[2]) ?? $match[2];
            return '' !== $encoded ? '<!-- wp:group ' . $encoded . ' -->' . $html . '<!-- /wp:group -->' : $match[0];
        }, $markup) ?? $markup;
    }

    /** Timestamp-like builder ids and React useId tokens are render noise, not authored anchors. */
    private static function normalizePerRenderIdTokens(string $value): string
    {
        return preg_replace('/(?:^id[0-9]{13}$|:r[0-9a-z]+:|«r[0-9a-z]+»|_r_[0-9a-z]+_)/iu', 'id-render', $value) ?? $value;
    }

    private static function normalizeRenderIds(string $markup): string
    {
        $html = static fn(string $value): string => preg_replace_callback('/\\b(id|for|aria-controls|aria-labelledby|aria-describedby|data-element-id)=("|\')([^"\']*)\\2/i', static fn(array $m): string => $m[1] . '=' . $m[2] . self::normalizePerRenderIdTokens($m[3]) . $m[2], $value) ?? $value;
        $normalize = static function (array $attrs) use (&$normalize, $html): array {
            foreach ($attrs as $key => $value) {
                if (is_array($value)) $attrs[$key] = $normalize($value);
                elseif (is_string($value)) $attrs[$key] = in_array($key, array('anchor', 'id', 'for', 'aria-controls', 'aria-labelledby', 'aria-describedby', 'data-element-id'), true) ? self::normalizePerRenderIdTokens($value) : $html($value);
            }
            return $attrs;
        };
        $markup = preg_replace_callback('/<!--\\s*wp:(\\S+)\\s+(\\{.*?\\})\\s*(\\/?)-->/s', static function (array $m) use ($normalize): string {
            $attrs = json_decode($m[2], true);
            return is_array($attrs) ? '<!-- wp:' . $m[1] . ' ' . BlockAttributes::encode($normalize($attrs)) . ' ' . $m[3] . '-->' : $m[0];
        }, $markup) ?? $markup;
        return $html($markup);
    }
}
