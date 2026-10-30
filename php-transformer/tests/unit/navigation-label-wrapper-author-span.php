<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;
use Automattic\BlocksEngine\PhpTransformer\VisualParity\StaticCssCascade;
use Automattic\BlocksEngine\PhpTransformer\VisualParity\StaticStyleParityRunner;

// Reduced page from #3422. The label is Core chrome, not an authored span.
$source = <<<'HTML'
<!DOCTYPE html>
<html lang="en"><head><meta charset="utf-8"><title>Home | Studio</title>
<style>
body{margin:0;background:#2d2924;font-family:Arial,sans-serif}
header{padding:20px}
.nav{list-style:none;margin:0;padding:0;display:inline-block}
.nav li{float:left;text-transform:uppercase;border-top:9px solid transparent}
.nav li.active{border-top-color:#fbb153}
.nav > li > a{display:block;padding:20px;color:#fff;font:bold 18px Arial,sans-serif;text-decoration:none}
.nav > li > a span{display:block;font:10px Arial,sans-serif;color:#787165;text-align:left;margin-top:-3px}
</style></head>
<body>
<header><nav><ul class="nav">
<li class="active"><a href="/">Home<span>Welcome</span></a></li>
<li><a href="/about.html">About<span>Our story</span></a></li>
<li><a href="/contact.html">Contact<span>Find us</span></a></li>
</ul></nav></header>
<main><h1>Home</h1><p>Body text.</p></main>
</body></html>
HTML;

// A stylesheet can also contain a currently dormant descendant rule. Keep a
// source span elsewhere so the type remains live, but not in this menu.
$withoutSubtitles = preg_replace('/<span>[^<]*<\/span>/', '', $source);
$withoutSubtitles = str_replace('Body text.', '<span>Body text.</span>', $withoutSubtitles);
foreach (array('reduced' => $source, 'dormant' => $withoutSubtitles) as $case => $html) {
    $result = (new HtmlTransformer())->transform($html, array())->toArray();
    $css = implode("\n", array_column($result['assets'], 'content'));
    $native = StaticStyleParityRunner::candidateHtmlFromSerializedBlocks($result['serialized_blocks']);
    $dom = new DOMDocument();
    $previous = libxml_use_internal_errors(true);
    $dom->loadHTML('<?xml encoding="utf-8" ?>' . $native);
    libxml_clear_errors();
    libxml_use_internal_errors($previous);
    $cascade = new StaticCssCascade($dom, $css);
    $labels = 0;
    $subtitles = 0;
    foreach ($dom->getElementsByTagName('span') as $span) {
        $values = $cascade->resolve($span, array('display', 'font', 'color', 'margin-top'), array());
        if (str_contains($span->getAttribute('class'), 'wp-block-navigation-item__label')) {
            ++$labels;
            $inherited = $cascade->resolve($span, array('font'), array('font'));
            if ('block' === ($values['display'] ?? '') || !str_contains($inherited['font'] ?? '', '18px') || '-3px' === ($values['margin-top'] ?? '')) {
                throw new RuntimeException($case . ': author descendant span declarations reached Core label: ' . json_encode($values));
            }
        } elseif (in_array(trim($span->textContent), array('Welcome', 'Our story', 'Find us'), true)) {
            ++$subtitles;
            if ('block' !== ($values['display'] ?? '') || !str_contains($values['font'] ?? '', '10px') || '#787165' !== ($values['color'] ?? '') || '-3px' !== ($values['margin-top'] ?? '')) {
                throw new RuntimeException('Source subtitle lost author declarations: ' . json_encode($values));
            }
        }
    }
    if (3 !== $labels || ('reduced' === $case && 3 !== $subtitles)) {
        throw new RuntimeException('Expected native navigation labels and retained source subtitles.');
    }
}

// Site imports compile through ArtifactCompiler, whose WordPress compatibility
// pass maps `a` to the item content. Its `span` must skip the label wrapper too.
$site = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array(array('path' => 'index.html', 'kind' => 'html', 'content' => $source))))->toArray();
$siteCss = '';
array_walk_recursive($site, static function ($value) use (&$siteCss): void {
    if (is_string($value) && str_contains($value, 'wp-block-navigation-item__content')) {
        $siteCss .= $value . "\n";
    }
});
preg_match_all('/([^{}]*\.wp-block-navigation-item__content[^{}]*)\{[^}]*font:10px/', $siteCss, $subtitleRules);
if (array() === $subtitleRules[1]) {
    throw new RuntimeException('Site compile lost the source subtitle rule.');
}
foreach ($subtitleRules[1] as $selector) {
    if (preg_match('/__content[^,]*\bspan(?!:not\(:where\(\.wp-block-navigation-item__label[,)])/', $selector)) {
        throw new RuntimeException('Site compile lets an author span rule match the Core label: ' . trim($selector));
    }
}
echo "Navigation label wrappers exclude author span rules; source subtitles retain them\n";
