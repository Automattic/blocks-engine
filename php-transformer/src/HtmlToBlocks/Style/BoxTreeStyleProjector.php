<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style;

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Support\SourceDom;

/** Restyle an exact source-node carrier before provenance is discarded. */
final class BoxTreeStyleProjector
{
    private const SELECTOR = "\0carrier\0";

    private readonly BoxTreeDeclarationPruner $pruner;

    public function __construct()
    {
        $this->pruner = new BoxTreeDeclarationPruner();
    }

    /** @param string $blockName The block carrying the rule, whose WordPress base styles a pruned declaration falls back to. */
    public function project(array $attrs, array $observations, array $viewports, LayoutGeometryState $state, array $parents = array(), string $blockName = ''): array
    {
        if (array() === $observations) return $attrs;
        $keys = array_keys($observations);
        usort($keys, static fn(string $a, string $b): int => ($viewports[$b]['width'] ?? 0) <=> ($viewports[$a]['width'] ?? 0));
        $status = array();
        $declarations = array();
        $tagName = self::emittedTag($blockName, $attrs);
        foreach ($keys as $key) {
            $parent = $parents[$key]['used_styles'] ?? null;
            $status[$key] = $this->pruner->classify($observations[$key]['used_styles'], is_array($parent) ? $parent : null, $blockName, $tagName);
            $declarations[$key] = $this->kept($observations[$key]['used_styles'], $status[$key]);
        }
        $baseKey = $keys[0];
        $base = $declarations[$baseKey];
        $mapper = new StyleAttributeMapper();
        $existing = is_array($attrs['style'] ?? null) ? $attrs['style'] : array();
        $existingDeclarations = SourceDeclarations::parse($mapper->serialize($existing)['style']);
        // Never resurrect authored values for observed (including inherited) properties.
        $unobserved = array_diff_key($existingDeclarations, $observations[$baseKey]['used_styles']);
        // Block attributes serialize inline and beat every @media carrier rule,
        // so only a value observed identically in every viewport may become one.
        // A varying property stays entirely in the carrier: base rule plus deltas.
        $invariant = array_filter($base, static function (string $value, string $property) use ($keys, $observations): bool {
            foreach ($keys as $key) {
                if (($observations[$key]['used_styles'][$property] ?? null) !== $value) return false;
            }
            return true;
        }, ARRAY_FILTER_USE_BOTH);
        $mapped = $mapper->map(array_replace($unobserved, $invariant));
        $attrs['style'] = $mapped['style'];
        $attrs = array_replace($attrs, $mapped['attrs']);
        // Layout declarations are deliberately excluded by StyleAttributeMapper;
        // the captured layout belongs to the engine rule, not a core layout guess.
        $consumed = SourceDeclarations::parse($mapper->serialize($mapped['style'])['style']);
        // Border shorthands can serialize as longhands. Retain their captured
        // declarations unless the mapper round-trip represents the same key.
        $css = $this->rule(self::SELECTOR, array_diff_key($base, $consumed));
        $effective = $base;
        $pseudoEffective = array();
        $css .= $this->pseudoRules($observations[$baseKey], $pseudoEffective);
        foreach (array_slice($keys, 1) as $key) {
            $delta = $this->delta($effective, $declarations[$key], $status[$key], $observations[$key]['used_styles']);
            $effective = array_replace($effective, $delta);
            $pseudo = $this->pseudoRules($observations[$key], $pseudoEffective);
            if (array() === $delta && '' === $pseudo) continue;
            $css .= '@media (max-width:' . (int) $viewports[$key]['width'] . 'px){' . $this->rule(self::SELECTOR, $delta) . $pseudo . '}';
        }
        if ('' === $css) return $attrs;
        // One carrier per distinct declaration set, not per source node.
        $class = $state->allocateCarrier('box-tree:' . $css);
        // Outrank core block/layout selectors without defeating editor inline styles.
        $state->registerRule($class, str_replace(self::SELECTOR, '.' . $class . '.' . $class, $css));
        $attrs['className'] = SourceDom::mergeClassNames((string) ($attrs['className'] ?? ''), $class);
        return $attrs;
    }

    /**
     * Declarations a narrower viewport adds over the rules that already apply
     * to it. A longhand the wider rules declare but this viewport prunes is
     * reset: inherited values to `inherit`, initial values to the observed
     * value, since omission would leave the wider value active.
     */
    private function delta(array $effective, array $declarations, array $status, array $observed): array
    {
        $delta = array();
        foreach ($declarations as $property => $value) {
            if (($effective[$property] ?? null) !== $value) $delta[$property] = $value;
        }
        foreach (array_diff_key($effective, $declarations) as $property => $_) {
            $reason = $status[$property] ?? BoxTreeDeclarationPruner::NOT_APPLICABLE;
            if (BoxTreeDeclarationPruner::INHERITED === $reason && 'inherit' !== $effective[$property]) $delta[$property] = 'inherit';
            elseif (BoxTreeDeclarationPruner::INITIAL === $reason && $effective[$property] !== $observed[$property]) $delta[$property] = $observed[$property];
        }
        return $delta;
    }

    /** The element a block's carrier class lands on, when the block fixes it. */
    private static function emittedTag(string $blockName, array $attrs): string
    {
        return match ($blockName) {
            'core/heading' => 'h' . max(1, min(6, (int) ($attrs['level'] ?? 2))),
            'core/post-title', 'core/query-title' => 'h' . max(1, min(6, (int) ($attrs['level'] ?? ('core/post-title' === $blockName ? 2 : 1)))),
            'core/group' => strtolower((string) ($attrs['tagName'] ?? 'div')),
            'core/paragraph' => 'p',
            'core/list' => ! empty($attrs['ordered']) ? 'ol' : 'ul',
            'core/list-item' => 'li',
            'core/code', 'core/preformatted', 'core/verse' => 'pre',
            'core/quote' => 'blockquote',
            default => '',
        };
    }

    private function kept(array $styles, array $status): array
    {
        return array_filter($styles, static fn(string $property): bool => BoxTreeDeclarationPruner::KEPT === ($status[$property] ?? null), ARRAY_FILTER_USE_KEY);
    }

    /** @param array<string, array<string, string>> $effective Pseudo declarations already active, by pseudo name. */
    private function pseudoRules(array $observation, array &$effective): string
    {
        $css = '';
        foreach (array('before', 'after') as $name) {
            $pseudo = $observation['pseudo'][$name] ?? null;
            if (null !== $pseudo) {
                // Pseudos inherit from their originating element, not its parent.
                $pseudoStatus = $this->pruner->classify($pseudo['used_styles'], $observation['used_styles']);
                $declarations = array_merge($this->kept($pseudo['used_styles'], $pseudoStatus), array('content' => $pseudo['content']));
                $delta = isset($effective[$name]) ? $this->delta($effective[$name], $declarations, $pseudoStatus, $pseudo['used_styles']) : $declarations;
                $effective[$name] = array_replace($effective[$name] ?? array(), $delta);
                $css .= $this->rule(self::SELECTOR . '::' . $name, $delta);
            } elseif (isset($effective[$name]) && 'none' !== ($effective[$name]['content'] ?? null)) {
                $effective[$name]['content'] = 'none';
                $css .= $this->rule(self::SELECTOR . '::' . $name, array('content' => 'none'));
            }
        }
        return $css;
    }

    private function rule(string $selector, array $styles): string
    {
        $declarations = array();
        foreach ($styles as $name => $value) {
            // Evidence is external input, not trusted stylesheet syntax.
            if (preg_match('/[{};<>]/', $value) || str_contains($value, '/*')) continue;
            $declarations[] = $name . ':' . $value;
        }
        if (array() === $declarations) return '';
        return $selector . '{' . implode(';', $declarations) . '}';
    }
}
