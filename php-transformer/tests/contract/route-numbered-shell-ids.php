<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\WordPressSitePlan;

$assert = static function (bool $condition, string $message): void { if (! $condition) throw new RuntimeException($message); };

// Builder-generated sites number chrome element ids per route
// (top-101 versus top-202). A header that differs only by such an
// unreferenced id is one design repeated across routes, so its candidates
// cluster and one shared header part is extracted; no page keeps its own
// copy. Unattributed and identical ids already extract and must not regress.

$css = '.bar{display:flex;padding:16px;background:#222;color:#fff}';
$page = static function (string $id, string $title, string $extra = '', string $head = ''): string {
    return '<!doctype html><html><head><meta charset="utf-8"><title>' . $title . '</title>' . $head . '<link rel="stylesheet" href="/style.css"></head><body>'
        . '<header' . ('' === $id ? '' : ' id="' . $id . '"') . ' class="bar"><a href="/">Acme</a>' . $extra . '<nav><a href="/">Home</a><a href="/about/">About</a></nav></header>'
        . '<main class="content"><h1>' . $title . '</h1><p>' . $title . ' body text.</p></main>'
        . '<footer class="foot"><p>Copyright 2026 Acme.</p></footer></body></html>';
};

$cases = array(
    'unattributed' => array('', ''),
    'same id' => array('top', 'top'),
    'route-numbered ids' => array('top-101', 'top-202'),
);
$numberedPlan = null;
foreach ($cases as $label => $ids) {
    $plan = (new ArtifactCompiler())->compile(array(
        'entrypoint' => 'index.html',
        'files' => array(
            'index.html' => $page($ids[0], 'Home'),
            'about/index.html' => $page($ids[1], 'About'),
            'style.css' => $css,
        ),
    ))->toArray()['source_reports']['wordpress_site_plan'];
    if ('route-numbered ids' === $label) $numberedPlan = $plan;
    WordPressSitePlan::assertValid($plan);
    $codes = array_column($plan['diagnostics'], 'code');
    $headers = array_values(array_filter($plan['template_parts'], static fn(array $part): bool => 'header' === ($part['area'] ?? null)));
    $assert(1 === count($headers), "{$label}: the repeated header is one shared part: " . json_encode($codes));
    $ambiguous = array_values(array_filter($plan['diagnostics'], static fn(array $diagnostic): bool => 'wordpress_site_plan_shell_retained_ambiguous' === ($diagnostic['code'] ?? null) && 'header' === ($diagnostic['area'] ?? null)));
    $assert(array() === $ambiguous, "{$label}: the header is not retained as ambiguous: " . json_encode(array_column($plan['diagnostics'], 'provenance', 'code')));
    foreach (array_column($plan['pages'], null, 'source_path') as $source => $row) {
        $markup = (string) $row['canonical_block_markup'];
        $assert(!str_contains($markup, 'Acme'), "{$label}: {$source} keeps no header copy in its content: {$markup}");
        $assert(str_contains($markup, 'body text.'), "{$label}: {$source} keeps its content.");
    }
    $templates = array_map(static fn(array $template): string => (string) ($template['canonical_block_markup'] ?? ''), $plan['templates'] ?? array());
    $assert(array() !== $templates && array_reduce($templates, static fn(bool $all, string $markup): bool => $all && str_contains($markup, '"slug":"header"'), true), "{$label}: every template renders the shared header.");
}

// The emitted part keeps the selected candidate's real markup: the entry
// page's authored id survives and no canonicalized placeholder id leaks.
$header = (string) (array_values(array_filter($numberedPlan['template_parts'], static fn(array $part): bool => 'header' === ($part['area'] ?? null)))[0]['canonical_block_markup'] ?? '');
$assert(str_contains($header, '"anchor":"top-101"') && str_contains($header, 'wp:navigation'), 'The shared header part keeps the selected candidate markup: ' . $header);
$assert(!str_contains($header, '__route__'), 'The shared header part carries no canonicalization placeholder: ' . $header);

// Builders also glue the route number straight onto the stem
// (header_stickynav75444) or join it with an underscore. Any trailing digit
// run after the same non-digit stem is a route number; ids that differ in
// their non-numeric part are different elements and do not merge.
$wrapped = static function (string $id, string $title): string {
    return '<!doctype html><html><head><meta charset="utf-8"><title>' . $title . '</title><link rel="stylesheet" href="/style.css"></head><body>'
        . '<header class="bar"><div id="' . $id . '"><a href="/">Acme</a><nav><a href="/">Home</a><a href="/about/">About</a></nav></div></header>'
        . '<main class="content"><h1>' . $title . '</h1><p>' . $title . ' body text.</p></main>'
        . '<footer class="foot"><p>Copyright 2026 Acme.</p></footer></body></html>';
};
$suffixCases = array(
    'glued route number' => array('header_stickynav75444', 'header_stickynav236888', true),
    'underscore route number' => array('stickynav_75444', 'stickynav_236888', true),
    'different stems' => array('stickynav-a75444', 'stickynav-b236888', false),
);
foreach ($suffixCases as $label => [$homeId, $aboutId, $extracts]) {
    $suffixPlan = (new ArtifactCompiler())->compile(array(
        'entrypoint' => 'index.html',
        'files' => array(
            'index.html' => $wrapped($homeId, 'Home'),
            'about/index.html' => $wrapped($aboutId, 'About'),
            'style.css' => $css,
        ),
    ))->toArray()['source_reports']['wordpress_site_plan'];
    WordPressSitePlan::assertValid($suffixPlan);
    $suffixHeaders = array_values(array_filter($suffixPlan['template_parts'], static fn(array $part): bool => 'header' === ($part['area'] ?? null)));
    $suffixCodes = array_column($suffixPlan['diagnostics'], 'code');
    if (!$extracts) {
        $assert(array() === $suffixHeaders, "{$label}: ids differing outside the route number keep the header page-owned: " . json_encode($suffixCodes));
        continue;
    }
    $assert(1 === count($suffixHeaders), "{$label}: the repeated header is one shared part: " . json_encode($suffixCodes));
    $suffixHeader = (string) ($suffixHeaders[0]['canonical_block_markup'] ?? '');
    $assert(str_contains($suffixHeader, $homeId) && !str_contains($suffixHeader, '__route__'), "{$label}: the shared header keeps the entry page's authored id: " . $suffixHeader);
    foreach (array_column($suffixPlan['pages'], null, 'source_path') as $source => $row) {
        $assert(!str_contains((string) $row['canonical_block_markup'], 'Acme'), "{$label}: {$source} keeps no header copy in its content.");
    }
}

// An id referenced inside the shell is a real per-route navigation target:
// those shells keep differing identities and stay page-owned.
$referencedPlan = (new ArtifactCompiler())->compile(array(
    'entrypoint' => 'index.html',
    'files' => array(
        'index.html' => $page('top-101', 'Home', '<a href="#top-101">Jump</a>'),
        'about/index.html' => $page('top-202', 'About', '<a href="#top-202">Jump</a>'),
        'style.css' => $css,
    ),
))->toArray()['source_reports']['wordpress_site_plan'];
WordPressSitePlan::assertValid($referencedPlan);
$referencedHeaders = array_values(array_filter($referencedPlan['template_parts'], static fn(array $part): bool => 'header' === ($part['area'] ?? null)));
$referencedCodes = array_column(array_filter($referencedPlan['diagnostics'], static fn(array $diagnostic): bool => 'wordpress_site_plan_shell_retained_ambiguous' === ($diagnostic['code'] ?? null) && 'header' === ($diagnostic['area'] ?? null)), 'code');
$assert(array() === $referencedHeaders && array() !== $referencedCodes, 'Referenced route-numbered ids keep the header page-owned: ' . json_encode(array_column($referencedPlan['diagnostics'], 'code')));
foreach (array_column($referencedPlan['pages'], null, 'source_path') as $source => $row) {
    $assert(str_contains((string) $row['canonical_block_markup'], 'Acme') && str_contains((string) $row['canonical_block_markup'], 'href="#top-'), "{$source} keeps its referenced header.");
}

// An id an author stylesheet names carries per-route presentation: the shared
// part keeps one page's id, so the other page would lose the styling written
// for its own (About's `#top-202` border). Any id form counts — `#…` and
// `[id=…]` — in any stylesheet that applies to a candidate page. A stylesheet
// naming an unrelated id leaves the extraction alone.
$styleCases = array(
    'inline id selector' => array('<style>#top-202{border-top:4px solid red}</style>', false),
    'inline id attribute selector' => array('<style>[id="top-202"]{border-top:4px solid red}</style>', false),
    'unrelated id selector' => array('<style>#elsewhere{border-top:4px solid red}</style>', true),
);
foreach ($styleCases as $label => [$head, $extracts]) {
    $stylePlan = (new ArtifactCompiler())->compile(array(
        'entrypoint' => 'index.html',
        'files' => array(
            'index.html' => $page('top-101', 'Home'),
            'about/index.html' => $page('top-202', 'About', '', $head),
            'style.css' => $css,
        ),
    ))->toArray()['source_reports']['wordpress_site_plan'];
    WordPressSitePlan::assertValid($stylePlan);
    $styleHeaders = array_values(array_filter($stylePlan['template_parts'], static fn(array $part): bool => 'header' === ($part['area'] ?? null)));
    $styleCodes = array_column(array_filter($stylePlan['diagnostics'], static fn(array $diagnostic): bool => 'wordpress_site_plan_shell_retained_ambiguous' === ($diagnostic['code'] ?? null) && 'header' === ($diagnostic['area'] ?? null)), 'code');
    if ($extracts) {
        $assert(1 === count($styleHeaders) && array() === $styleCodes, "{$label}: an unrelated stylesheet reference still extracts the header: " . json_encode(array_column($stylePlan['diagnostics'], 'code')));
    } else {
        $assert(array() === $styleHeaders && array() !== $styleCodes, "{$label}: a stylesheet-named route-numbered id keeps the header page-owned: " . json_encode(array_column($stylePlan['diagnostics'], 'code')));
        foreach (array_column($stylePlan['pages'], null, 'source_path') as $source => $row) {
            $assert(str_contains((string) $row['canonical_block_markup'], 'Acme') && str_contains((string) $row['canonical_block_markup'], 'body text.'), "{$label}: {$source} keeps its header copy and content.");
        }
    }
}
