<?php

declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style;

/**
 * Detects scroll-reveal scripts that add terminal classes to elements selected
 * by a trigger class, so the static render can apply the revealed state.
 */
final class StaticClassPromotions
{
    /**
     * @return array<string, array<int, string>>
     */
    public static function detect(string $html): array
    {
        if ( ! str_contains($html, 'classList.add') || ! str_contains($html, 'querySelectorAll') ) {
            return array();
        }

        if ( ! str_contains($html, 'IntersectionObserver') && ! str_contains($html, 'isIntersecting') ) {
            return array();
        }

        preg_match_all('/querySelectorAll\s*\(\s*([\'"`])\.([A-Za-z0-9_-]+)\1\s*\)/', $html, $selectorMatches);
        preg_match_all('/classList\.add\s*\(([^)]*)\)/', $html, $addMatches);

        $triggerClasses = array_values(array_unique($selectorMatches[2] ?? array()));
        $terminalClasses = array();
        foreach ( $addMatches[1] ?? array() as $args ) {
            preg_match_all('/[\'"`]([A-Za-z0-9_-]+)[\'"`]/', (string) $args, $classMatches);
            foreach ( $classMatches[1] ?? array() as $className ) {
                $terminalClasses[] = $className;
            }
        }

        $terminalClasses = array_values(array_unique($terminalClasses));
        if ( array() === $triggerClasses || array() === $terminalClasses ) {
            return array();
        }

        $promotions = array();
        foreach ( array_slice($triggerClasses, 0, 20) as $triggerClass ) {
            $promotions[$triggerClass] = array_values(array_diff(array_slice($terminalClasses, 0, 20), array( $triggerClass )));
        }

        return array_filter($promotions, static fn (array $classes): bool => array() !== $classes);
    }
}
