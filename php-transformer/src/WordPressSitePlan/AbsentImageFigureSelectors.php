<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan;

use Automattic\BlocksEngine\PhpTransformer\Css\CssStylesheetTransformer;
use Automattic\BlocksEngine\PhpTransformer\WordPress\SourceClassIdentity;

/**
 * Projection restates an authored image rule for every native image figure
 * a source image could become: the plain figure and the inline and flex-item
 * figure carriers, each with its `> img` and `> a > img` leaves. Which
 * carriers a site has is known only once the plan's markup is final, and
 * core/image saves its figure, with its classes, in that markup.
 *
 * An arm is dropped only when one of its compounds restates a carried
 * figure on an engine carrier it never becomes: the compound's source
 * classes sit together on an emitted image figure, but none of those
 * figures also carries the compound's engine (`blocks-engine-`) classes.
 * A compound whose source classes no emitted figure shows is not judged:
 * the markup is not proof of where they live, unless one of those classes
 * is named only in class attributes of emitted elements that are not image
 * figures (an inline icon `<img class="me-1">`): the markup then shows
 * every element it lives on, and none is a figure. Classes a shipped script
 * names, editor and WordPress render classes and state tokens are never
 * required: they may be added where the markup does not show them. The
 * figure's link is never judged: an editor can link any image, so the
 * linked leaf of a carried figure stays.
 *
 * Only sheets the front end loads are pruned. An editor-only sheet is
 * written against the editor canvas, whose block wrappers saved markup does
 * not describe, and adds no weight to any route.
 */
final class AbsentImageFigureSelectors
{
    private const FIGURE = 'wp-block-image';
    private const ENGINE = 'blocks-engine-';
    private const EXEMPT = '/^(?:wp-|is-|has-|editor-|block-editor-|components-)/';
    private const SELECTOR_PSEUDO = array('is', 'where', 'matches', '-webkit-any', '-moz-any');
    private const MAX_ALTERNATIVES = 64;

    /** @var list<array<string,true>> */
    private array $figures = array();
    /** @var array<string,true> */
    private array $scriptTokens = array();
    /** @var array<string,int> Class attribute placements on elements that are not image figures. */
    private array $offFigure = array();
    /** @var array<string,true> Classes some image figure carries, or named where no class attribute places them. */
    private array $unplaced = array();
    /** @var list<string> */
    private array $texts = array();
    /** @var array<string,bool> */
    private array $verdicts = array();

    /**
     * @param list<array<string,mixed>> $assets
     * @param string $markup Every emitted page, part, template, pattern and menu.
     * @param array<mixed> $records Runtime declarations and entity records.
     * @return list<array<string,mixed>>
     */
    public static function prune(array $assets, string $markup, array $records): array
    {
        $scripts = array();
        foreach ($assets as $asset) {
            if ('css' === ($asset['kind'] ?? null) || !empty($asset['binary']) || !self::isScriptLike($asset)) continue;
            // A script whose bytes are not inline could build any figure.
            if (!is_string($asset['content'] ?? null) || isset($asset['payload_reference'])) return $assets;
            // A script that names the figure class may build image figures.
            if (1 === preg_match('/(?<![\w-])' . self::FIGURE . '(?![\w-])/', $asset['content'])) return $assets;
            $scripts[] = $asset['content'];
        }
        $texts = array($markup);
        array_walk_recursive($records, static function (mixed $value) use (&$texts): void {
            if (is_string($value)) $texts[] = $value;
        });
        $pruner = self::read($texts, $scripts);
        if (null === $pruner) return $assets;
        foreach ($assets as &$asset) {
            if ('css' !== ($asset['kind'] ?? null) || 'editor' === ($asset['stylesheet_target'] ?? 'both') || !is_string($asset['content'] ?? null) || isset($asset['payload_reference']) || !str_contains($asset['content'], '.' . self::FIGURE)) continue;
            $content = $pruner->pruneStylesheet($asset['content']);
            if ($content === $asset['content']) continue;
            $asset = CompiledAssets::withRewrittenTextContent($asset, $content);
        }
        unset($asset);
        return $assets;
    }

    /**
     * Every element carrying the figure class, or null when the figure class
     * is named anywhere this reading cannot place on an element.
     *
     * @param list<string> $texts
     * @param list<string> $scripts
     */
    private static function read(array $texts, array $scripts): ?self
    {
        $pruner = new self();
        $pruner->texts = $texts;
        foreach ($texts as $text) {
            if (preg_match_all('/<[a-zA-Z][\w-]*\s[^<>]*?\bclass\s*=\s*(?:"([^"]*)"|\'([^\']*)\')[^<>]*>/', $text, $elements, PREG_SET_ORDER)) {
                foreach ($elements as $element) {
                    $classes = array_unique(preg_split('/\s+/', trim(($element[1] ?? '') . ($element[2] ?? '')), -1, PREG_SPLIT_NO_EMPTY) ?: array());
                    $figure = in_array(self::FIGURE, $classes, true);
                    foreach ($classes as $class) {
                        if ($figure) $pruner->unplaced[$class] = true;
                        else $pruner->offFigure[$class] = ($pruner->offFigure[$class] ?? 0) + 1;
                    }
                }
            }
            $named = preg_match_all('/(?<![\w-])' . self::FIGURE . '(?![\w-])/', $text);
            if (0 === $named) continue;
            $placed = 0;
            if (preg_match_all('/<[a-zA-Z][\w-]*\s[^<>]*?\bclass\s*=\s*(?:"([^"]*)"|\'([^\']*)\')[^<>]*>/', $text, $matches, PREG_SET_ORDER)) {
                foreach ($matches as $match) {
                    $classes = preg_split('/\s+/', trim(($match[1] ?? '') . ($match[2] ?? '')), -1, PREG_SPLIT_NO_EMPTY) ?: array();
                    if (!in_array(self::FIGURE, $classes, true)) continue;
                    ++$placed;
                    $pruner->figures[] = array_fill_keys($classes, true);
                }
            }
            if ($placed !== $named) return null;
        }
        foreach ($scripts as $script) {
            if (preg_match_all('/[\w-]+/', $script, $matches)) foreach ($matches[0] as $token) $pruner->scriptTokens[$token] = true;
        }
        return $pruner;
    }

    /** @param array<string,mixed> $asset */
    private static function isScriptLike(array $asset): bool
    {
        if (in_array($asset['kind'] ?? null, array('js', 'script', 'html', 'json'), true)) return true;
        return 1 === preg_match('/\.(?:m?js|html?|json)$/i', (string) ($asset['target_path'] ?? $asset['source_path'] ?? ''));
    }

    private function pruneStylesheet(string $css): string
    {
        $pruned = (new CssStylesheetTransformer())->transformStyleRules($css, function (string $prelude, string $body): string {
            if (!str_contains($prelude, '.' . self::FIGURE)) return $prelude . '{' . $body . '}';
            $arms = CssStylesheetTransformer::splitSelectorList($prelude);
            // A vendor pseudo can make the whole list invalid; removing an
            // arm must never revive a rule the browser drops.
            if (null === $arms || str_contains($prelude, ':-')) return $prelude . '{' . $body . '}';
            $kept = array_values(array_filter($arms, fn (string $arm): bool => !$this->dead(trim($arm))));
            if (count($kept) === count($arms)) return $prelude . '{' . $body . '}';
            // Comments ahead of the rule (licenses, source maps) stay in place.
            $comments = preg_match('#^(?:\s*/\*.*?\*/)+\s*#s', $prelude, $leading) ? $leading[0] : '';
            if (array() === $kept) return $comments;
            return $comments . implode(',', array_map(static fn (string $arm): string => trim((string) preg_replace('#^(?:\s*/\*.*?\*/)+#s', '', $arm)), $kept)) . '{' . $body . '}';
        });
        if ($pruned === $css) return $css;
        // Grouping rules whose every rule was dropped go with them.
        do {
            $pruned = (string) preg_replace('/@(?:-webkit-)?(?:media|supports|container|layer|document)\b[^{};]*\{\s*\}/i', '', $pruned, -1, $count);
        } while (0 < $count);
        return $pruned;
    }

    /** Whether a complex selector provably matches nothing. */
    private function dead(string $selector): bool
    {
        if (isset($this->verdicts[$selector])) return $this->verdicts[$selector];
        $verdict = false;
        $split = self::split($selector);
        if (null !== $split) {
            foreach ($split[0] as $compound) {
                if ($this->deadCompound($compound)) { $verdict = true; break; }
            }
        }
        return $this->verdicts[$selector] = $verdict;
    }

    private function deadCompound(string $compound): bool
    {
        $alternatives = $this->alternatives($compound);
        if (null === $alternatives) return false;
        if (array() === $alternatives) return true;
        foreach ($alternatives as $classes) {
            // An alternative without the figure class is not judged here.
            if (!isset($classes[self::FIGURE])) return false;
            $required = array_keys(array_filter($classes, fn (bool $value, string $class): bool => self::FIGURE === $class || !$this->exempt($class), ARRAY_FILTER_USE_BOTH));
            if ($this->carried($required)) return false;
            // Only a carrier restatement of a figure the markup shows is
            // provably absent: its source classes must sit on some figure.
            $source = array_values(array_filter($required, static fn (string $class): bool => !str_starts_with($class, self::ENGINE)));
            if (!$this->carried($source)) {
                // A source class the markup places only off image figures
                // proves no figure carries the compound.
                foreach ($source as $class) if (self::FIGURE !== $class && $this->offFigureOnly($class)) continue 2;
                return false;
            }
            if (count($source) === count($required)) return false;
        }
        return true;
    }

    /** @param list<string> $required */
    private function carried(array $required): bool
    {
        foreach ($this->figures as $figure) {
            foreach ($required as $class) if (!isset($figure[$class])) continue 2;
            return true;
        }
        return false;
    }

    /**
     * Whether every mention of a class in the emitted markup and records is
     * a class attribute of an element that is not an image figure.
     */
    private function offFigureOnly(string $class): bool
    {
        if (isset($this->unplaced[$class])) return false;
        $placed = $this->offFigure[$class] ?? 0;
        if (0 === $placed) return false;
        $named = 0;
        $pattern = '/(?<![\w-])' . preg_quote($class, '/') . '(?![\w-])/';
        foreach ($this->texts as $text) {
            $named += (int) preg_match_all($pattern, $text);
            if ($named > $placed) break;
        }
        if ($named !== $placed) $this->unplaced[$class] = true;
        return $named === $placed;
    }

    private function exempt(string $class): bool
    {
        return 1 === preg_match(self::EXEMPT, $class) || isset($this->scriptTokens[$class])
            || 1 === preg_match('/^' . SourceClassIdentity::REVEAL_STATE . '$/i', $class) || SourceClassIdentity::containsCurrentStateToken($class);
    }

    /**
     * The class sets one element must carry to match a compound: one per
     * alternative of its positive selector-list pseudos. An empty list when
     * a complex `:is()` argument provably matches nothing; null when the
     * compound cannot be read with certainty.
     *
     * @return list<array<string,true>>|null
     */
    private function alternatives(string $compound): ?array
    {
        $sets = array(array());
        $length = strlen($compound);
        for ($i = 0; $i < $length; ++$i) {
            $char = $compound[$i];
            if ('\\' === $char || '"' === $char || "'" === $char) return null;
            if ('[' === $char) {
                $close = strpos($compound, ']', $i);
                if (false === $close) return null;
                $i = $close;
                continue;
            }
            if ('.' === $char) {
                if (1 !== preg_match('/\G\.(-?[_a-zA-Z][\w-]*)/A', $compound, $match, 0, $i)) return null;
                foreach ($sets as &$set) $set[$match[1]] = true;
                unset($set);
                $i += strlen($match[0]) - 1;
                continue;
            }
            if (':' !== $char || 1 !== preg_match('/\G:{1,2}([\w-]+)\(/A', $compound, $match, 0, $i)) continue;
            $open = $i + strlen($match[0]);
            $depth = 1;
            for ($end = $open; $end < $length && $depth > 0; ++$end) {
                if ('\\' === $compound[$end]) return null;
                if ('(' === $compound[$end]) ++$depth;
                elseif (')' === $compound[$end]) --$depth;
            }
            if (0 !== $depth) return null;
            $i = $end - 1;
            if (!in_array(strtolower($match[1]), self::SELECTOR_PSEUDO, true)) continue;
            $arguments = CssStylesheetTransformer::splitSelectorList(substr($compound, $open, $end - 1 - $open));
            if (null === $arguments || array() === $arguments) continue;
            $inner = array();
            $complex = false;
            foreach ($arguments as $argument) {
                $argument = trim($argument);
                $split = self::split($argument);
                if (null === $split) { $complex = true; break; }
                if (1 < count($split[0])) {
                    $complex = true;
                    continue;
                }
                $argumentSets = $this->alternatives($argument);
                if (null === $argumentSets) { $complex = true; break; }
                array_push($inner, ...$argumentSets);
            }
            if ($complex) {
                // A complex argument is judged as a selector of its own; the
                // pseudo matches nothing when no argument can match.
                foreach ($arguments as $argument) if (!$this->dead(trim($argument))) continue 2;
                return array();
            }
            $product = array();
            foreach ($sets as $set) foreach ($inner as $argumentSet) $product[] = $set + $argumentSet;
            if (count($product) > self::MAX_ALTERNATIVES) return null;
            $sets = $product;
        }
        return $sets;
    }

    /**
     * A complex selector's compounds and the combinators between them, or
     * null when it cannot be read with certainty.
     *
     * @return array{0:list<string>,1:list<string>}|null
     */
    private static function split(string $selector): ?array
    {
        $compounds = array();
        $combinators = array();
        $current = '';
        $pending = null;
        $depth = 0;
        $length = strlen($selector);
        for ($i = 0; $i < $length; ++$i) {
            $char = $selector[$i];
            if ('\\' === $char || '"' === $char || "'" === $char) return null;
            if ('(' === $char || '[' === $char) ++$depth;
            elseif (')' === $char || ']' === $char) --$depth;
            if (0 === $depth && (ctype_space($char) || '>' === $char || '+' === $char || '~' === $char)) {
                if (!ctype_space($char)) $pending = $char;
                elseif (null === $pending) $pending = ' ';
                continue;
            }
            if (null !== $pending && '' !== $current) {
                $compounds[] = $current;
                $combinators[] = $pending;
                $current = '';
            }
            $pending = null;
            $current .= $char;
        }
        if (0 !== $depth || '' === $current || null !== $pending) return null;
        $compounds[] = $current;
        return array($compounds, $combinators);
    }
}
