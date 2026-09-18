<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Diagnostics;

use Automattic\BlocksEngine\PhpTransformer\Contract\ConversionFindingContract;
use Automattic\BlocksEngine\PhpTransformer\Css\CssStylesheetTransformer;

/**
 * Reports author-selector projections whose binding identities appear in no
 * emitted document scope they were produced for.
 *
 * A projected selector that matches nothing is definitionally a bug: the
 * authored declaration disappears instead of degrading. Confirming each
 * projected prelude still binds is a lookup against identities the projector
 * already emitted and documents the compiler already holds — not a re-render.
 *
 * An authored selector is dead only when every projected form is unbound in
 * the scopes that form was emitted for. A class-bound shared-stylesheet rule
 * that keeps the authored class is live even if a document-local marker
 * sibling does not appear in a different document.
 *
 * Pure: no DOM, no I/O, no global state; the same inputs always yield the same
 * report.
 */
final class DeadProjectedSelectorReporter
{
    public const SCHEMA = 'blocks-engine/php-transformer/dead-projected-selectors/v1';

    public const CODE = 'dead_projected_author_selector';

    /**
     * @param list<array{authored: string, projected: string, stylesheet?: string, scope?: string}> $bindings
     * @param array<string, string> $haystacksByScope Serialized markup keyed by the
     *        document scope a projection was emitted for.
     */
    public function evaluate(array $bindings, array $haystacksByScope): DeadProjectedSelectorEvaluation
    {
        $groups = array();
        foreach ( $bindings as $binding ) {
            $authored = trim((string) ($binding['authored'] ?? ''));
            $projected = trim((string) ($binding['projected'] ?? ''));
            if ( '' === $authored || '' === $projected ) {
                continue;
            }
            $stylesheet = (string) ($binding['stylesheet'] ?? '');
            $scope = (string) ($binding['scope'] ?? '');
            $key = $stylesheet . "\0" . $authored . "\0" . $scope;
            $groups[$key] ??= array(
                'authored'    => $authored,
                'stylesheet'  => $stylesheet,
                'scope'       => $scope,
                'projected'   => array(),
            );
            $groups[$key]['projected'][$projected] = true;
        }

        $findings = array();
        foreach ( $groups as $group ) {
            $scope = $group['scope'];
            $haystack = $haystacksByScope[$scope] ?? '';
            $projectedSelectors = array_keys($group['projected']);
            $live = false;
            $checkable = false;
            foreach ( $projectedSelectors as $projected ) {
                $bound = $this->preludeIsBound($projected, $haystack);
                if ( null === $bound ) {
                    continue;
                }
                $checkable = true;
                if ( $bound ) {
                    $live = true;
                    break;
                }
            }
            if ( ! $checkable || $live ) {
                continue;
            }

            $scopes = array() === $scope ? array() : array($scope);
            $findings[] = ConversionFindingContract::withClassification(array(
                'code'            => self::CODE,
                'severity'        => 'warning',
                'source_selector' => $group['authored'],
                'selector'        => $projectedSelectors[0],
                'path'            => $group['stylesheet'],
                'summary'         => 'Author selector projection bound to no element in the emitted document scope(s).',
                'pattern_family'  => 'projected_author_selector',
                'repair_bucket'   => 'restore_projected_selector_binding',
                'context'         => array(
                    'stylesheet'          => $group['stylesheet'],
                    'projected_selectors' => $projectedSelectors,
                    'scopes'              => $scopes,
                ),
            ));
        }

        return new DeadProjectedSelectorEvaluation($findings);
    }

    /**
     * @param list<array{authored: string, projected: string, stylesheet?: string, scope?: string}> $bindings
     * @param array<string, string> $haystacksByScope
     * @return array<string, mixed>
     */
    public function report(array $bindings, array $haystacksByScope): array
    {
        return $this->evaluate($bindings, $haystacksByScope)->report();
    }

    /**
     * Whether any selector in a projected prelude still binds in $haystack.
     * Null when the prelude has no checkable binding identity.
     */
    private function preludeIsBound(string $prelude, string $haystack): ?bool
    {
        if ( array() === $this->hashedEngineMarkers($prelude) ) {
            return null;
        }

        $selectors = CssStylesheetTransformer::splitSelectorList($prelude);
        if ( null === $selectors ) {
            $selectors = array($prelude);
        }

        $checkable = false;
        foreach ( $selectors as $selector ) {
            $bound = $this->selectorIsBound($selector, $haystack);
            if ( null === $bound ) {
                continue;
            }
            $checkable = true;
            if ( $bound ) {
                return true;
            }
        }

        return $checkable ? false : null;
    }

    /**
     * Null when the selector has no identity we can look up in markup.
     */
    private function selectorIsBound(string $selector, string $haystack): ?bool
    {
        $positive = $this->stripNotSelectors($selector);
        $markers = $this->hashedEngineMarkers($positive);
        if ( array() !== $markers ) {
            foreach ( $markers as $marker ) {
                if ( str_contains($haystack, $marker) ) {
                    return true;
                }
            }

            return false;
        }

        $authored = $this->authoredClassesAndIds($positive);
        if ( array() !== $authored ) {
            foreach ( $authored as $token ) {
                if ( $this->tokenInHaystack($haystack, $token) ) {
                    return true;
                }
            }

            return false;
        }

        $engineClasses = $this->wellKnownEngineClasses($positive);
        if ( array() !== $engineClasses ) {
            foreach ( $engineClasses as $class ) {
                if ( str_contains($haystack, $class) ) {
                    return true;
                }
            }

            return false;
        }

        return null;
    }

    private function stripNotSelectors(string $selector): string
    {
        $out = '';
        $length = strlen($selector);
        for ( $index = 0; $index < $length; ) {
            if ( 0 === substr_compare($selector, ':not(', $index, 5, true) ) {
                $index += 5;
                $depth = 1;
                while ( $index < $length && $depth > 0 ) {
                    $character = $selector[$index];
                    if ( '(' === $character ) {
                        ++$depth;
                    } elseif ( ')' === $character ) {
                        --$depth;
                    }
                    ++$index;
                }
                continue;
            }
            $out .= $selector[$index];
            ++$index;
        }

        return $out;
    }

    /** @return list<string> */
    private function hashedEngineMarkers(string $selector): array
    {
        if ( ! preg_match_all('/blocks-engine-(?:semantic|richtext|control|attribute(?:-state)?|root-child|table|native-button|source-[a-z0-9]+)-[a-f0-9]+-\d+/', $selector, $matches) ) {
            return array();
        }

        return array_values(array_unique($matches[0]));
    }

    /** @return list<string> */
    private function authoredClassesAndIds(string $selector): array
    {
        $tokens = array();
        if ( preg_match_all('/\.([A-Za-z_][\w-]*)/', $selector, $classes) ) {
            foreach ( $classes[1] as $class ) {
                if ( ! str_starts_with($class, 'blocks-engine-') && ! str_starts_with($class, 'wp-block') ) {
                    $tokens[] = $class;
                }
            }
        }
        if ( preg_match_all('/#([A-Za-z_][\w-]*)/', $selector, $ids) ) {
            foreach ( $ids[1] as $id ) {
                if ( ! str_starts_with($id, 'blocks-engine-') ) {
                    $tokens[] = $id;
                }
            }
        }

        return array_values(array_unique($tokens));
    }

    /** @return list<string> */
    private function wellKnownEngineClasses(string $selector): array
    {
        if ( ! preg_match_all('/\.(blocks-engine-(?:inline-layout-carrier|css-owned-layout|css-owned-grid|css-owned-flow|synthetic-paragraph|synthetic-anchor-undecorated|synthetic-anchor-block-display|empty-flex-item|empty-flex-column-item))/', $selector, $matches) ) {
            return array();
        }

        return array_values(array_unique($matches[1]));
    }

    private function tokenInHaystack(string $haystack, string $token): bool
    {
        return 1 === preg_match('/(?:^|[^A-Za-z0-9_-])' . preg_quote($token, '/') . '(?:[^A-Za-z0-9_-]|$)/', $haystack);
    }
}
