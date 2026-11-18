<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan;

use Automattic\BlocksEngine\PhpTransformer\Css\CssStylesheetTransformer;
use Automattic\BlocksEngine\PhpTransformer\Support\EngineMarker;

/**
 * Projection keeps an authored rule's source arm for elements conversion left
 * alone, guarded so it skips the carriers it converted:
 * `.cta:not(:where(.blocks-engine-role-control))` beside the control arm.
 * Once markup is final, a source class whose every carrier is a converted
 * role carrier (or that conversion moved entirely onto subject classes)
 * leaves that guarded arm matching nothing on any route. It is dropped
 * rather than shipped in every theme stylesheet.
 *
 * An arm is dropped only on proof: one of its compounds requires a source
 * class, excludes role carriers, and every emitted element naming the class,
 * or its subject class, is one of those carriers. A class no emitted markup
 * names at all must still be proven converted by its subject class, and must
 * not appear in any shipped script, which could add it at runtime.
 */
final class ConvertedSourceClassSelectors
{
    private const ROLE_PREFIX = 'blocks-engine-role-';
    private const MINTED = '/^(?:wp-|is-|has-|blocks-engine-|be-|route-class-|editor-|block-editor-|components-)/';

    /** @var list<array<string,true>> */
    private array $classSets = array();
    /** @var array<string,true> */
    private array $tokens = array();
    /** @var array<string,true> */
    private array $scriptTokens = array();
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
            // A script whose bytes are not inline could add any class.
            if (!is_string($asset['content'] ?? null) || isset($asset['payload_reference'])) return $assets;
            $scripts[] = $asset['content'];
        }
        $texts = array($markup);
        array_walk_recursive($records, static function (mixed $value) use (&$texts): void {
            if (is_string($value)) $texts[] = $value;
        });
        $pruner = new self($texts, $scripts);
        foreach ($assets as &$asset) {
            if ('css' !== ($asset['kind'] ?? null) || !is_string($asset['content'] ?? null) || isset($asset['payload_reference']) || !str_contains($asset['content'], ':not(:where(.' . self::ROLE_PREFIX)) continue;
            $content = $pruner->pruneStylesheet($asset['content']);
            if ($content === $asset['content']) continue;
            $asset = CompiledAssets::withRewrittenTextContent($asset, $content);
        }
        unset($asset);
        return $assets;
    }

    /** @param list<string> $texts @param list<string> $scripts */
    private function __construct(array $texts, array $scripts)
    {
        foreach ($texts as $text) {
            if (preg_match_all('/\bclass\s*=\s*(?:"([^"]*)"|\'([^\']*)\')/i', $text, $matches, PREG_SET_ORDER)) {
                foreach ($matches as $match) $this->addClassSet(($match[1] ?? '') . ($match[2] ?? ''));
            }
            if (preg_match_all('/"className"\s*:\s*"((?:[^"\\\\]|\\\\.)*)"/', $text, $matches)) {
                foreach ($matches[1] as $classes) $this->addClassSet(stripcslashes($classes));
            }
            // Every token outside a class list too: a class named anywhere
            // else may be applied in a way this reading does not see.
            // Set keys in place: a union (`+=`) copies the whole set per
            // text, which grows with texts × distinct tokens.
            if (preg_match_all('/[\w-]+/', $text, $matches)) foreach ($matches[0] as $token) $this->tokens[$token] = true;
        }
        foreach ($scripts as $script) {
            if (preg_match_all('/[\w-]+/', $script, $matches)) foreach ($matches[0] as $token) $this->scriptTokens[$token] = true;
        }
    }

    private function addClassSet(string $classes): void
    {
        $set = preg_split('/\s+/', trim($classes), -1, PREG_SPLIT_NO_EMPTY);
        if (is_array($set) && array() !== $set) $this->classSets[] = array_fill_keys($set, true);
    }

    /** @param array<string,mixed> $asset */
    private static function isScriptLike(array $asset): bool
    {
        if (in_array($asset['kind'] ?? null, array('js', 'script', 'html', 'json'), true)) return true;
        return 1 === preg_match('/\.(?:m?js|html?|json)$/i', (string) ($asset['target_path'] ?? $asset['source_path'] ?? ''));
    }

    private function pruneStylesheet(string $css): string
    {
        return (new CssStylesheetTransformer())->transformStyleRules($css, function (string $prelude, string $body): string {
            if (!str_contains($prelude, ':not(:where(.' . self::ROLE_PREFIX)) return $prelude . '{' . $body . '}';
            $arms = CssStylesheetTransformer::splitSelectorList($prelude);
            // A vendor pseudo can make the whole list invalid; removing an
            // arm must never revive a rule the browser drops.
            if (null === $arms || str_contains($prelude, ':-')) return $prelude . '{' . $body . '}';
            $kept = array_values(array_filter($arms, fn (string $arm): bool => !$this->deadArm(trim($arm))));
            if (count($kept) === count($arms)) return $prelude . '{' . $body . '}';
            return array() === $kept ? '' : implode(',', array_map('trim', $kept)) . '{' . $body . '}';
        });
    }

    private function deadArm(string $arm): bool
    {
        foreach (self::compounds($arm) ?? array() as $compound) {
            [$required, $excluded] = $compound;
            if (array() === $excluded) continue;
            foreach ($required as $class) {
                if ($this->convertedAway($class, $excluded)) return true;
            }
        }
        return false;
    }

    /** @param list<string> $excluded */
    private function convertedAway(string $class, array $excluded): bool
    {
        $key = $class . '|' . implode(' ', $excluded);
        if (isset($this->verdicts[$key])) return $this->verdicts[$key];
        if (1 === preg_match(self::MINTED, $class) || isset($this->scriptTokens[$class])) return $this->verdicts[$key] = false;
        $subject = EngineMarker::subjectClassClass($class);
        $carried = false;
        foreach ($this->classSets as $set) {
            $named = isset($set[$class]);
            $excludedHere = false;
            foreach ($excluded as $role) if (isset($set[$role])) { $excludedHere = true; break; }
            if ($named && !$excludedHere) return $this->verdicts[$key] = false;
            if (($named || isset($set[$subject])) && $excludedHere) $carried = true;
        }
        // The class names no emitted element, but text elsewhere may still
        // apply it: only a class absent from every emitted token is gone.
        if ($carried && !$this->namedInClassSet($class) && isset($this->tokens[$class])) return $this->verdicts[$key] = false;
        return $this->verdicts[$key] = $carried;
    }

    private function namedInClassSet(string $class): bool
    {
        foreach ($this->classSets as $set) if (isset($set[$class])) return true;
        return false;
    }

    /**
     * Each compound's required classes (outside any functional pseudo) and
     * the role classes a `:not(:where(...))` or `:not(...)` of plain role
     * classes excludes. Null when the arm cannot be read with certainty.
     *
     * @return list<array{0:list<string>,1:list<string>}>|null
     */
    private static function compounds(string $arm): ?array
    {
        $compounds = array();
        $required = array();
        $excluded = array();
        $length = strlen($arm);
        $flush = static function () use (&$compounds, &$required, &$excluded): void {
            if (array() !== $required || array() !== $excluded) $compounds[] = array(array_values(array_unique($required)), array_values(array_unique($excluded)));
            $required = array();
            $excluded = array();
        };
        for ($i = 0; $i < $length; ++$i) {
            $char = $arm[$i];
            if ('\\' === $char || '"' === $char || "'" === $char) return null;
            if ('[' === $char) {
                $close = strpos($arm, ']', $i);
                if (false === $close) return null;
                $i = $close;
                continue;
            }
            if (ctype_space($char) || '>' === $char || '+' === $char || '~' === $char) {
                $flush();
                continue;
            }
            if ('.' === $char && 1 === preg_match('/\G\.(-?[_a-zA-Z][\w-]*)/A', $arm, $match, 0, $i)) {
                $required[] = $match[1];
                $i += strlen($match[0]) - 1;
                continue;
            }
            if (':' === $char && 1 === preg_match('/\G:{1,2}([\w-]+)\(/A', $arm, $match, 0, $i)) {
                $open = $i + strlen($match[0]);
                $depth = 1;
                for ($end = $open; $end < $length && $depth > 0; ++$end) {
                    if ('(' === $arm[$end]) ++$depth;
                    elseif (')' === $arm[$end]) --$depth;
                }
                if (0 !== $depth) return null;
                if ('not' === strtolower($match[1])) {
                    $argument = trim(substr($arm, $open, $end - 1 - $open));
                    if (1 === preg_match('/^:where\((.*)\)$/s', $argument, $inner)) $argument = $inner[1];
                    $classes = CssStylesheetTransformer::splitSelectorList($argument) ?? array();
                    $roles = array();
                    foreach ($classes as $class) {
                        if (1 !== preg_match('/^\.(' . preg_quote(self::ROLE_PREFIX, '/') . '[a-z]+)$/', trim($class), $role)) { $roles = array(); break; }
                        $roles[] = $role[1];
                    }
                    array_push($excluded, ...$roles);
                }
                $i = $end - 1;
                continue;
            }
        }
        $flush();
        return $compounds;
    }
}
