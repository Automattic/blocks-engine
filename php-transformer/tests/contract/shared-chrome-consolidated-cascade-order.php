<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\Css\CssStylesheetTransformer;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\CssCascade;
use Automattic\BlocksEngine\PhpTransformer\VisualParity\StaticCssCascade;
use Automattic\BlocksEngine\PhpTransformer\VisualParity\StaticStyleParityRunner;

$assert = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};

/*
 * A site builder writes one stylesheet per breakpoint, all under the same
 * media condition. The phone row carries two classes: the base sheet shows
 * it (`.frame .bar{display:flex}`) and a later sheet hides it at desktop
 * width (`.frame .hide-wide{display:none}`). Equal specificity, so the later
 * hide wins. The detached footer also carries `.bar`, so a re-anchored copy
 * of the base rule is lifted into shared chrome, while the hide stays on the
 * route. Another breakpoint sheet after the hide lifts chrome rules under the
 * same condition. Folding both lifted slices into one shared stylesheet would
 * load the base copy after the route's hide, and the row would show.
 */
$frame = static function (string $suffix, string $title): string {
    return '<div id="frame' . $suffix . '" class="frame">'
        . '<header id="top' . $suffix . '"><a href="/">Example</a><nav><a href="/">Home</a><a href="/about/">About</a><a href="/events/">Events</a></nav></header>'
        . '<main id="pages' . $suffix . '"><h1>' . $title . '</h1><div class="bar hide-wide"><p>Phone row on ' . $title . '</p></div><p>' . $title . ' body copy.</p></main>'
        . '<footer id="bottom' . $suffix . '"><div class="bar"><p>Footer note</p></div></footer></div>';
};
$files = array('site.css' => '.data-blocks-engine-capture-mobile-document{display:none}@media(max-width:767px){.data-blocks-engine-capture-desktop-document{display:none}.data-blocks-engine-capture-mobile-document{display:block}}');
foreach (array('index.html' => 'Home', 'about/index.html' => 'About', 'events/index.html' => 'Events') as $path => $title) {
    $body = '';
    foreach (array('desktop' => '', 'mobile' => '--data-blocks-engine-capture-mobile') as $device => $suffix) {
        $body .= '<div class="data-blocks-engine-capture-' . $device . '-document" data-blocks-engine-capture-document-scope="">' . $frame($suffix, $title) . '</div>';
    }
    $files[$path] = '<!doctype html><html><head><meta charset="utf-8"><title>' . $title . '</title><link rel="stylesheet" href="/site.css">'
        . '<style media="(min-width:1024px)">.frame .bar{display:flex;gap:8px}</style>'
        . '<style media="(min-width:1024px)">.frame .hide-wide{display:none}</style>'
        . '<style media="(min-width:1024px)">.frame .bar{padding:2px}</style>'
        . '</head><body>' . $body . '</body></html>';
}
$plan = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => $files))->toArray()['source_reports']['wordpress_site_plan'];

$footer = array_values(array_filter($plan['template_parts'] ?? array(), static fn (array $part): bool => 'footer' === ($part['area'] ?? null)))[0] ?? array();
$assert('shared_shell' === ($footer['placement']['kind'] ?? null), 'The footer is hoisted into a detached shared part.');
$shared = array_filter($plan['assets'] ?? array(), static fn (array $asset): bool => 'css' === ($asset['kind'] ?? null) && str_contains((string) ($asset['target_path'] ?? ''), 'shared-chrome-') && str_contains((string) $asset['content'], 'display:flex'));
$assert(array() !== $shared, 'The base rule for the footer class is lifted into shared chrome.');

foreach ($plan['pages'] as $page) {
    $route = (string) $page['source_path'];
    $dom = new DOMDocument();
    @$dom->loadHTML('<div class="frame">' . StaticStyleParityRunner::candidateHtmlFromSerializedBlocks((string) $page['canonical_block_markup']) . '</div>');
    $row = (new DOMXPath($dom))->query('//*[contains(concat(" ", normalize-space(@class), " "), " hide-wide ")]')->item(0);
    $assert($row instanceof DOMElement, $route . ' keeps the phone row.');
    $enqueued = array();
    foreach ($plan['assets'] ?? array() as $asset) {
        if ('css' !== ($asset['kind'] ?? null)) continue;
        foreach ($asset['stylesheet_instances'] ?? array() as $instance) {
            if ($route !== ($instance['source_path'] ?? null)) continue;
            $media = trim((string) ($instance['media'] ?? ''));
            $enqueued[(int) $instance['order']] = '' === $media || 'all' === $media ? $asset['content'] : '@media ' . $media . '{' . $asset['content'] . '}';
        }
    }
    ksort($enqueued);
    foreach (array(1440 => 'none', 1024 => 'none') as $width => $display) {
        $css = '';
        // StaticCssCascade has a fixed viewport: keep only rules whose media
        // applies at $width, in enqueue order.
        (new CssStylesheetTransformer())->visitStyleRules(implode("\n", $enqueued), static function (string $selector, string $body, array $conditions) use (&$css, $width): void {
            foreach ($conditions as $condition) {
                if (str_starts_with($condition, '@media ') && !CssCascade::mediaConditionApplies(substr($condition, 7), $width, 16)) return;
            }
            $css .= $selector . '{' . $body . '}';
        });
        $resolved = (new StaticCssCascade($dom, $css))->resolve($row, array('display'), array());
        $assert($display === ($resolved['display'] ?? null), $route . ' phone row at ' . $width . 'px: expected display ' . $display . ', got ' . json_encode($resolved));
    }
}
echo "Shared chrome consolidated cascade order passed.\n";
