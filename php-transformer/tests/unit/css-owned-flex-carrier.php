<?php
declare(strict_types=1);

/**
 * Contract for inline `display:flex` on containers demoted to CSS-owned layout.
 *
 * `cssOwnedGroupAttributes()` drops the native `layout` attribute when an author
 * flex/grid container is demoted to a css-owned core/group. Grids survive that
 * demotion because their inline declarations ride to the generated stylesheet on
 * a carrier class. Flex containers had no equivalent, so the declaration that
 * makes the container a flex container was dropped while the block was still
 * marked `blocks-engine-css-owned-flow` — the children then stacked.
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

$failures = 0;
$passes = 0;

$assert = static function (bool $condition, string $message, string $detail = '') use (&$failures, &$passes): void {
    if ( $condition ) {
        ++$passes;
        return;
    }

    ++$failures;
    fwrite(STDERR, 'FAIL: ' . $message . ('' !== $detail ? ' - ' . $detail : '') . PHP_EOL);
};

$cssFor = static function (array $result, string $source): string {
    return implode("\n", array_map(
        static fn (array $asset): string => (string) ($asset['content'] ?? ''),
        array_values(array_filter(
            is_array($result['assets'] ?? null) ? $result['assets'] : array(),
            static fn (array $asset): bool => $source === ($asset['source'] ?? '')
        ))
    ));
};

$transform = static fn (string $html): array => ( new HtmlTransformer() )->transform($html, array())->toArray();

/** Rule body for the geometry carrier class present in the markup. */
$carrierRule = static function (string $markup, string $css): string {
    if ( 1 !== preg_match('/\b(be-inline-geometry-[a-f0-9-]+)\b/', $markup, $match) ) {
        return '';
    }
    if ( 1 !== preg_match('/(?<![\w-])\.' . preg_quote($match[1], '/') . '\{([^}]*)\}/', $css, $rule) ) {
        return '';
    }

    return (string) $rule[1];
};

$columns = '<div><p>Alpha</p></div><div><p>Beta</p></div><div><p>Gamma</p></div>';

// -- Defect: a css-owned-flow footer loses its inline display:flex entirely.
$flex = $transform(
    '<footer style="display:flex;gap:2rem;justify-content:space-between;align-items:center">' . $columns . '</footer>'
);
$flexMarkup = (string) ($flex['serialized_blocks'] ?? '');
$flexEngineCss = $cssFor($flex, 'engine-support');
$flexRule = $carrierRule($flexMarkup, $flexEngineCss);

$assert(
    str_contains($flexMarkup, 'blocks-engine-css-owned-flow'),
    'inline flex: the container is still demoted to a css-owned-flow group',
    $flexMarkup
);
$assert(
    1 === preg_match('/\bbe-inline-geometry-[a-f0-9-]+\b/', $flexMarkup),
    'inline flex: the demoted container receives a geometry carrier class',
    $flexMarkup
);
$assert(
    str_contains($flexRule, 'display:flex'),
    'inline flex: the carrier keeps the container a flex container',
    '' !== $flexRule ? $flexRule : $flexEngineCss
);
$assert(
    str_contains($flexRule, 'gap:2rem'),
    'inline flex: the authored gap rides with the display declaration',
    '' !== $flexRule ? $flexRule : $flexEngineCss
);
$assert(
    str_contains($flexRule, 'justify-content:space-between'),
    'inline flex: the authored main-axis distribution rides with the display declaration',
    '' !== $flexRule ? $flexRule : $flexEngineCss
);
$assert(
    str_contains($flexRule, 'align-items:center'),
    'inline flex: the authored cross-axis alignment rides with the display declaration',
    '' !== $flexRule ? $flexRule : $flexEngineCss
);

// -- A vertical flex column keeps its direction rather than silently becoming a row.
$column = $transform(
    '<footer style="display:flex;flex-direction:column;flex-wrap:wrap;gap:1rem">' . $columns . '</footer>'
);
$columnMarkup = (string) ($column['serialized_blocks'] ?? '');
$columnRule = $carrierRule($columnMarkup, $cssFor($column, 'engine-support'));

$assert(
    str_contains($columnRule, 'display:flex') && str_contains($columnRule, 'flex-direction:column') && str_contains($columnRule, 'flex-wrap:wrap'),
    'inline flex column: direction and wrap ride with the display declaration',
    '' !== $columnRule ? $columnRule : $cssFor($column, 'engine-support')
);

// -- Control: no authored display gains no carrier and no flex declaration.
$plain = $transform('<footer class="plain">' . $columns . '</footer>');
$plainMarkup = (string) ($plain['serialized_blocks'] ?? '');
$plainEngineCss = $cssFor($plain, 'engine-support');

$assert(
    1 !== preg_match('/\bbe-inline-geometry-[a-f0-9-]+\b/', $plainMarkup),
    'no authored display control: no geometry carrier class is invented',
    $plainMarkup
);
$assert(
    ! str_contains($plainEngineCss, 'display:flex'),
    'no authored display control: no flex declaration is invented',
    $plainEngineCss
);
$assert(
    str_starts_with($plainMarkup, '<!-- wp:group {"className":"plain","tagName":"footer"} --><footer class="wp-block-group plain">'),
    'no authored display control: the container block is unchanged',
    substr($plainMarkup, 0, 200)
);

// -- Control: a class-owned flex container has nothing inline to carry, and the
// author stylesheet already retains the declaration.
$classFlex = $transform(
    '<style>.bar{display:flex;gap:2rem}</style><footer class="bar">' . $columns . '</footer>'
);
$classFlexMarkup = (string) ($classFlex['serialized_blocks'] ?? '');

$assert(
    1 !== preg_match('/\bbe-inline-geometry-[a-f0-9-]+\b/', $classFlexMarkup),
    'class-owned flex control: no carrier is generated for declarations the author stylesheet owns',
    $classFlexMarkup
);
$assert(
    str_contains($cssFor($classFlex, 'author-css'), '.bar{display:flex;gap:2rem}'),
    'class-owned flex control: the author rule stays the owner of the flex declaration',
    $cssFor($classFlex, 'author-css')
);

if ( $failures > 0 ) {
    fwrite(STDERR, "CSS-owned flex carrier contract: {$failures} failed, {$passes} passed\n");
    exit(1);
}

fwrite(STDOUT, "CSS-owned flex carrier contract passed: {$passes} assertions\n");
