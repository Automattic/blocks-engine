<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\Css\ClassAttributeSelectorProjection;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;
use Automattic\BlocksEngine\PhpTransformer\VisualParity\StaticCssCascade;
use Automattic\BlocksEngine\PhpTransformer\VisualParity\StaticStyleParityRunner;

// Reduced page from #3437. Bootstrap 2 keys its grid on a class substring;
// engine tokens such as blocks-engine-subject-tag-span must not satisfy it.
$source = <<<'HTML'
<!DOCTYPE html>
<html lang="en"><head><meta charset="utf-8"><title>Home | Studio</title>
<style>
body{margin:0;font-family:Arial,sans-serif}
/* Grid columns keyed on a class substring, as in Bootstrap 2 */
[class*="span"]{float:left;min-height:1px;margin-left:20px}
.span4{width:300px}
header{overflow:hidden;padding:20px;background:#2d2924}
header h1{float:left;margin:0;color:#fff}
.menu{float:right}
.menu ul{list-style:none;margin:0;padding:0}
.menu li{float:left}
.menu li a{display:block;padding:20px;color:#fff;font:bold 18px Arial,sans-serif;text-decoration:none}
.menu li a span{display:block;font:10px Arial,sans-serif;color:#aaa}
</style></head>
<body>
<header><h1>Studio</h1><nav class="menu"><ul>
<li><a href="/">Home<span>Welcome</span></a></li>
<li><a href="/about.html">About<span>Our story</span></a></li>
<li><a href="/contact.html">Contact<span>Find us</span></a></li>
</ul></nav></header>
<main><div class="span4"><p>Column.</p></div></main>
</body></html>
HTML;

$check = static function (string $path, string $serialized, string $css): void {
    $native = StaticStyleParityRunner::candidateHtmlFromSerializedBlocks($serialized);
    $dom = new DOMDocument();
    $previous = libxml_use_internal_errors(true);
    $dom->loadHTML('<?xml encoding="utf-8" ?>' . $native);
    libxml_clear_errors();
    libxml_use_internal_errors($previous);
    $cascade = new StaticCssCascade($dom, $css);
    $properties = array('float', 'margin-left', 'width', 'font');

    $subtitles = 0;
    $engineTokens = 0;
    foreach ($dom->getElementsByTagName('span') as $span) {
        if (!in_array(trim($span->textContent), array('Welcome', 'Our story', 'Find us'), true)) {
            continue;
        }
        ++$subtitles;
        if (preg_match('/(?:^|\s)blocks-engine-[^\s]*span/', $span->getAttribute('class'))) {
            ++$engineTokens;
        }
        $values = $cascade->resolve($span, $properties, array());
        if ('' !== ($values['float'] ?? '') && 'none' !== $values['float']) {
            throw new RuntimeException($path . ': [class*="span"] floats a menu subtitle through an engine class token: ' . json_encode($values));
        }
        if ('20px' === ($values['margin-left'] ?? '')) {
            throw new RuntimeException($path . ': [class*="span"] adds margin-left to a menu subtitle through an engine class token: ' . json_encode($values));
        }
        if (!str_contains($values['font'] ?? '', '10px')) {
            throw new RuntimeException($path . ': menu subtitle lost its own author rule: ' . json_encode($values));
        }
    }
    if (3 !== $subtitles || 0 === $engineTokens) {
        throw new RuntimeException($path . ': expected three subtitle spans carrying an engine token containing "span", found ' . $subtitles . '/' . $engineTokens);
    }

    $columns = 0;
    foreach ($dom->getElementsByTagName('*') as $element) {
        if (!preg_match('/(?:^|\s)span4(?:\s|$)/', $element->getAttribute('class'))) {
            continue;
        }
        ++$columns;
        $values = $cascade->resolve($element, $properties, array());
        if ('left' !== ($values['float'] ?? '') || '20px' !== ($values['margin-left'] ?? '') || '300px' !== ($values['width'] ?? '')) {
            throw new RuntimeException($path . ': .span4 lost its [class*="span"] grid declarations: ' . json_encode($values));
        }
    }
    if (1 !== $columns) {
        throw new RuntimeException($path . ': expected the .span4 column in the projected markup.');
    }
};

// Site imports compile through ArtifactCompiler, including the WordPress
// compatibility replay; every emitted stylesheet applies to the page.
$site = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array(array('path' => 'index.html', 'kind' => 'html', 'content' => $source))))->toArray();
$siteCss = implode("\n", array_map(static fn (array $asset): string => (string) ($asset['content'] ?? ''), array_values(array_filter($site['assets'] ?? array(), static fn (array $asset): bool => 'css' === ($asset['kind'] ?? '')))));
if (!str_contains($siteCss, 'class*="span"')) {
    throw new RuntimeException('Site compile dropped the authored [class*="span"] rule.');
}
$check('ArtifactCompiler', (string) ($site['serialized_blocks'] ?? ''), $siteCss);

$result = (new HtmlTransformer())->transform($source, array())->toArray();
$check('HtmlTransformer', (string) $result['serialized_blocks'], implode("\n", array_column($result['assets'], 'content')));

// The other class attribute operators follow the same contract, and a source
// token that only satisfies them on another element does not join.
$projection = new ClassAttributeSelectorProjection(array('icon-home', 'x icon-star', 'row col-6', 'lang-en', 'blocks-engine-subject-tag-span'));
$expected = array(
    '[class^="icon-"]' => ':is([class^="icon-"]:where(:not([class*="blocks-engine-"])),.icon-home)',
    '[class$=\'-6\'] p' => ':is([class$=\'-6\']:where(:not([class*="blocks-engine-"])),.col-6) p',
    'a:not([class|=lang])' => 'a:not(:is([class|=lang]:where(:not([class*="blocks-engine-"])),.lang-en))',
    '[class*="span"]' => ':is([class*="span"]:where(:not([class*="blocks-engine-"])))',
    '[class*="a b"]' => '[class*="a b"]',
    '[data-x*="span"]' => '[data-x*="span"]',
);
foreach ($expected as $selector => $projected) {
    $actual = $projection->selector($selector);
    if ($projected !== $actual || $actual !== $projection->selector($actual)) {
        throw new RuntimeException('Unexpected class attribute projection of ' . $selector . ': ' . $actual);
    }
}

echo "Author class substring selectors ignore engine class tokens and keep source matches\n";
