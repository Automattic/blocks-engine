<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\Css\CssSelectorMatcher;
use Automattic\BlocksEngine\PhpTransformer\Css\CssStylesheetTransformer;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

$assert = static function (bool $condition, string $message, string $detail = ''): void {
    if (! $condition) {
        throw new RuntimeException($message . ('' === $detail ? '' : "\n" . substr($detail, 0, 1500)));
    }
};

// Author rules that reach RichText carriers address them through the richtext
// role and subject classes: one constant arm per rule, and one role-class
// exclusion guard, however many carriers the page holds.
$css = static fn (array $result, string $source): string => implode('', array_map(
    static fn (array $asset): string => $source === ($asset['source'] ?? '') ? (string) ($asset['content'] ?? '') : '',
    $result['assets'] ?? array()
));
$transform = static function (int $runs): array {
    $html = '<style>*{margin:0}.hl{color:#c00;font-weight:700}</style><main>';
    for ($i = 0; $i < $runs; $i++) {
        $html .= '<p>Item ' . $i . ' <span class="hl">run ' . $i . '</span> tail.</p>';
    }
    return (new HtmlTransformer())->transform($html . '</main>')->toArray();
};

// Every rule that reaches $element, as its declaration bodies.
$paint = static function (DOMElement $element, string $stylesheet): array {
    $bodies = array();
    preg_match_all('/([^{}]+)\{([^{}]*)\}/', $stylesheet, $rules, PREG_SET_ORDER);
    foreach ($rules as $rule) {
        foreach (CssStylesheetTransformer::splitSelectorList($rule[1]) ?? array() as $selector) {
            $match = CssSelectorMatcher::matches($element, CssSelectorMatcher::parse(trim($selector)));
            if ($match['supported'] && $match['matches']) {
                $bodies[] = $rule[2];
                break;
            }
        }
    }
    return $bodies;
};

$sizes = array();
foreach (array(2, 4, 8) as $runs) {
    $result = $transform($runs);
    $markup = (string) ($result['serialized_blocks'] ?? '');
    $front = $css($result, 'author-css');
    $sizes[$runs] = strlen($front);

    $assert(! str_contains($front, 'data-blocks-engine-richtext-marker='), 'No author rule names a carrier by its marker attribute (' . $runs . ' runs).', $front);
    $assert(! str_contains($front, '--blocks-engine-richtext-marker:blocks-engine-richtext-'), 'No author rule names a carrier by its marker declaration (' . $runs . ' runs).', $front);
    $assert(0 === preg_match('/\.blocks-engine-richtext-[a-f0-9]+-\d/', $front), 'Exclusion guards name no per-carrier marker class (' . $runs . ' runs).', $front);
    $assert(str_contains($front, ':not(:where(.blocks-engine-role-richtext))'), 'The universal reset excludes RichText carriers through their role class.', $front);

    $document = new DOMDocument();
    $document->loadHTML('<!doctype html><html><body>' . $markup . '</body></html>', LIBXML_NOERROR | LIBXML_NOWARNING);
    $carriers = $document->getElementsByTagName('mark');
    $assert($runs === $carriers->length, 'Each run is one RichText carrier (' . $runs . ' runs).', $markup);
    foreach ($carriers as $carrier) {
        $classes = preg_split('/\s+/', (string) $carrier->getAttribute('class')) ?: array();
        $assert(in_array('hl', $classes, true) && in_array('blocks-engine-role-richtext', $classes, true) && in_array('blocks-engine-subject', $classes, true), 'Each carrier keeps its authored class and states its role and subject.', $markup);
        $bodies = implode(';', $paint($carrier, $front));
        $assert(str_contains($bodies, 'color:#c00;font-weight:700'), 'The authored rule still reaches every carrier.', $bodies);
        $assert(str_contains($bodies, 'margin:0'), 'The universal reset still reaches every carrier.', $bodies);
    }
    $paragraph = $document->getElementsByTagName('p')->item(0);
    $assert($paragraph instanceof DOMElement && ! str_contains(implode(';', $paint($paragraph, $front)), 'color:#c00'), 'The authored rule reaches nothing but its carriers.');
}
$assert(1 === count(array_unique($sizes)), 'Author stylesheet bytes stay constant as runs grow: ' . json_encode($sizes));

// The same holds across shell boundaries: every route's copy of a shared
// header seeds its own carrier, yet the author stylesheets (site and shared
// chrome, for visitors and the editor) stay one size.
$siteSizes = array();
foreach (array(2, 4, 6) as $routes) {
    $slugs = array_map(static fn (int $i): string => 0 === $i ? 'index' : 'p' . $i, range(0, $routes - 1));
    $files = array('site.css' => '*{margin:0}.sr-only{position:absolute;width:1px;height:1px;overflow:hidden}');
    foreach ($slugs as $slug) {
        $files[$slug . '.html'] = '<!doctype html><html><head><title>' . $slug . '</title><link rel="stylesheet" href="site.css"></head><body>'
            . '<header><a href="index.html">Acme</a><a class="cta" href="p1.html">Start<span class="sr-only"> a project</span></a></header>'
            . '<main><h1>' . $slug . '</h1><p>' . $slug . ' page.</p></main></body></html>';
    }
    $plan = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => $files))->toWordPressSitePlanView()['wordpress_site_plan'];
    $sheets = array_values(array_filter($plan['assets'] ?? array(), static fn (array $asset): bool => 'css' === ($asset['kind'] ?? null) && ! str_contains((string) $asset['target_path'], 'engine-support')));
    $assert(array() !== $sheets, 'The author stylesheets are emitted (' . $routes . ' routes).');
    $siteSizes[$routes] = array_sum(array_map(static fn (array $asset): int => strlen((string) $asset['content']), $sheets));
    $all = implode('', array_map(static fn (array $asset): string => (string) $asset['content'], $sheets));
    $assert(0 === preg_match('/blocks-engine-richtext-[a-f0-9]+-\d/', $all), 'No author stylesheet names a per-route carrier marker (' . $routes . ' routes).', $all);
    $assert(str_contains($all, ':where(.blocks-engine-role-richtext.blocks-engine-subject.'), 'The hidden label is reached through its subject arm (' . $routes . ' routes).', $all);
}
$assert(1 === count(array_unique($siteSizes)), 'Author stylesheet bytes stay constant as routes grow: ' . json_encode($siteSizes));

echo "richtext-subject-arm-scaling: ok\n";
