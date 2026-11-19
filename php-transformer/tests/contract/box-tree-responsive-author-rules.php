<?php
declare(strict_types=1);

require __DIR__ . '/../../vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\PayloadReader;
use Automattic\BlocksEngine\PhpTransformer\Contract\BoxTree;
use Automattic\BlocksEngine\PhpTransformer\Css\CssFluidLength;
use Automattic\BlocksEngine\PhpTransformer\Css\CssSelectorMatcher;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\ThemeBootstrap;

// Issue #6580: a route with box-tree evidence delivers only engine-owned CSS
// (EvidenceRouteStylesheets retires its author sheets), so the carriers must
// reproduce the author's breakpoint rules at every captured viewport. The
// source sizes images with breakpoint rules; the evidence captures it at
// two widths. The delivered CSS is cascaded against the compiled markup at
// each width, and sizes are resolved at viewports whose heights differ from
// the captures'.
$assert = static function (bool $condition, string $message, string $detail = ''): void {
    if (!$condition) throw new RuntimeException($message . ('' === $detail ? '' : "\n" . $detail));
    echo "PASS: $message\n";
};

$author = '.cta{background:#111;color:#fff;display:inline-flex;gap:24px;padding:17px 30px;font-size:10px;line-height:17px}'
    . '@media (max-width:850px){.cta{gap:12px}}'
    . 'footer p{font-size:10px;line-height:17px;margin:0}'
    // A plan card: a flex column whose CTA is spaced from the price by its own
    // margin, and whose price carries a smaller `<small>` prefix.
    . '.plan{display:flex;flex-direction:column}.plan p{margin:0}.price{font-size:31.67px;line-height:1.2}.price small{font-size:11px}'
    . '.buy{background:#111;color:#fff;display:inline-flex;align-self:flex-start;margin-top:22px;padding:17px 30px;font-size:10px;line-height:17px}'
    // A legal line: a bare span in a footer with a smaller strut, whose line
    // height a breakpoint restates in another unit (same computed value).
    // The footer is a template part, a direct child of the body: its media
    // variants lift like page content's. The narrowest variant is a
    // percentage (the source's `8%`), proven against the body's own box.
    . 'footer{font-size:12px;padding:0 115px}@media (max-width:850px){footer{padding:0 61px}}@media (max-width:520px){footer{padding:0 8%}}'
    // An add-on line: a block-level `<b>` beside bare text, so it becomes the
    // only content of a boxless inline-layout carrier paragraph.
    . '.addon{font-size:12px}.addon b{display:block;font:26px "Playfair Display";margin-top:15px}'
    . '.legal{font-size:10px;line-height:17px}@media (max-width:1000px){.legal{line-height:1.7}}'
    . '.hero{height:min(880px,100svh);min-height:680px}'
    . '@media (max-width:520px){.hero{min-height:650px}}'
    . '.story figure{margin:0}.story img{display:block;width:100%;max-height:650px;object-fit:cover}'
    . '.intro img{display:block;width:100%;max-height:650px;object-fit:cover}'
    . '.approach{display:grid;grid-template-columns:1fr 1fr;column-gap:40px}'
    . '.approach-image img{display:block;width:100%;height:680px;object-fit:cover}'
    . '.portfolio{display:grid;grid-template-columns:repeat(12,1fr)}'
    . '.pic:nth-child(n){grid-column:span 5;height:640px}.pic:nth-child(2){grid-column:span 7;height:610px}'
    . '@media (max-width:850px){.approach{grid-template-columns:1fr}.approach-image img{height:450px}'
    . '.pic:nth-child(n){grid-column:span 6;height:340px}.pic:nth-child(1){grid-column:span 12;height:500px}}'
    . '@media (max-width:520px){.pic:nth-child(n){grid-column:span 12;height:280px}}';
$markup = '<!doctype html><html><head><title>Responsive</title><style>' . $author . '</style></head><body><main>'
    . '<section class="hero"><h1>Hero</h1></section>'
    . '<section class="story"><figure><img src="https://example.com/story.jpg" alt="Story"></figure></section>'
    . '<section class="intro"><h1>Intro</h1><img src="https://example.com/intro.jpg" alt="Intro"></section>'
    . '<section class="approach"><div class="approach-text"><h2>Approach</h2><p>How we work.</p></div>'
    . '<div class="approach-image"><img src="https://example.com/approach.jpg" alt="Approach"></div></section>'
    . '<section class="portfolio"><div class="pic"><p>First picture</p></div><div class="pic"><p>Second picture</p></div></section>'
    . '<section class="contact"><a class="cta" href="/contact" style="display:inline-flex;padding:17px 30px;font-size:10px">Contact us</a></section>'
    . '<section class="plan"><p class="price">RM 99 <small>FROM RM</small></p><a class="buy" href="/buy">Buy</a></section>'
    . '<section class="pricing"><div class="addon">EXTRA HOUR<b>RM150/hr</b></div></section>'
    . '</main><footer><p>Footer <span>inline text</span></p><span class="legal">2024 Studio</span></footer>'
    . '</body></html>';
$viewports = array('desktop' => array('width' => 1440, 'height' => 900), 'mobile' => array('width' => 390, 'height' => 640));

// Used values the browser reports for the boxes the author rules size. Every
// other box fills the viewport width; the intro and story images are 2:3,
// clamped to 650px. The hero is min(880px, 100svh) tall, at least 680px (650px
// at 520px and below): at the 640px-tall mobile capture its min-height wins.
$column = array('desktop' => array('width' => '700px'), 'mobile' => array('width' => '390px'));
$clampedImage = array(
    'desktop' => array('display' => 'block', 'width' => '1440px', 'height' => '650px', 'max-height' => '650px', 'object-fit' => 'cover'),
    'mobile' => array('display' => 'block', 'width' => '390px', 'height' => '585px', 'max-height' => '650px', 'object-fit' => 'cover'),
);
$buttonStyles = array('display' => 'inline-flex', 'width' => '110px', 'height' => '51px', 'padding-top' => '17px', 'padding-right' => '30px', 'padding-bottom' => '17px', 'padding-left' => '30px', 'font-size' => '10px', 'line-height' => '17px', 'row-gap' => '24px', 'column-gap' => '24px');
$footerStyles = array('display' => 'block', 'height' => '17px', 'font-size' => '10px', 'line-height' => '17px');
$buyStyles = array('display' => 'inline-flex', 'width' => '77.23px', 'height' => '51px', 'margin-top' => '22px', 'align-self' => 'flex-start', 'padding-top' => '17px', 'padding-right' => '30px', 'padding-bottom' => '17px', 'padding-left' => '30px', 'font-size' => '10px', 'line-height' => '17px');
$priceStyles = array('display' => 'block', 'height' => '38px', 'font-size' => '31.67px', 'line-height' => '38px');
$smallStyles = array('display' => 'inline', 'font-size' => '11px', 'line-height' => '13.2px');
$addonStyles = array('display' => 'block', 'height' => '30px', 'font-size' => '26px', 'font-family' => '"Playfair Display"', 'line-height' => 'normal', 'margin-top' => '15px');
$legalStyles = array('display' => 'inline', 'font-size' => '10px', 'line-height' => '17px');
$observed = array(
    '//section[@class="plan"]' => array('desktop' => array('display' => 'flex', 'flex-direction' => 'column', 'height' => '111px'), 'mobile' => array('display' => 'flex', 'flex-direction' => 'column', 'height' => '111px')),
    '//a[@class="buy"]' => array('desktop' => $buyStyles, 'mobile' => $buyStyles),
    '//p[@class="price"]' => array('desktop' => $priceStyles, 'mobile' => $priceStyles),
    '//p[@class="price"]/small' => array('desktop' => $smallStyles, 'mobile' => $smallStyles),
    '//footer' => array(
        'desktop' => array('display' => 'block', 'height' => '34px', 'font-size' => '12px', 'padding-left' => '115px', 'padding-right' => '115px'),
        'mobile' => array('display' => 'block', 'height' => '34px', 'font-size' => '12px', 'padding-left' => '31.2px', 'padding-right' => '31.2px'),
    ),
    '//div[@class="addon"]' => array('desktop' => array('font-size' => '12px'), 'mobile' => array('font-size' => '12px')),
    '//div[@class="addon"]/b' => array('desktop' => $addonStyles, 'mobile' => $addonStyles),
    '//span[@class="legal"]' => array('desktop' => $legalStyles, 'mobile' => $legalStyles),
    '//a[@class="cta"]' => array('desktop' => $buttonStyles, 'mobile' => array_replace($buttonStyles, array('row-gap' => '12px', 'column-gap' => '12px'))),
    '//footer/p' => array('desktop' => $footerStyles, 'mobile' => $footerStyles),
    '//footer/p/span' => array('desktop' => array_replace($footerStyles, array('display' => 'inline')), 'mobile' => array_replace($footerStyles, array('display' => 'inline'))),
    '//section[@class="hero"]' => array(
        'desktop' => array('display' => 'block', 'height' => '880px', 'min-height' => '680px'),
        'mobile' => array('display' => 'block', 'height' => '650px', 'min-height' => '650px'),
    ),
    '//section[@class="story"]/figure' => array(
        'desktop' => array('display' => 'block', 'height' => '650px'),
        'mobile' => array('display' => 'block', 'height' => '585px'),
    ),
    '//section[@class="story"]/figure/img' => $clampedImage,
    '//section[@class="intro"]/img' => $clampedImage,
    '//section[@class="approach"]' => array(
        'desktop' => array('display' => 'grid', 'grid-template-columns' => '700px 700px', 'column-gap' => '40px'),
        'mobile' => array('display' => 'grid', 'grid-template-columns' => '390px', 'column-gap' => '40px'),
    ),
    '//div[@class="approach-text"]' => $column,
    '//div[@class="approach-image"]' => $column,
    '//div[@class="approach-image"]/img' => array(
        'desktop' => array('display' => 'block', 'width' => '700px', 'height' => '680px', 'object-fit' => 'cover'),
        'mobile' => array('display' => 'block', 'width' => '390px', 'height' => '450px', 'object-fit' => 'cover'),
    ),
);

foreach (array(1, 2) as $index) {
    $observed['//div[@class="pic"][' . $index . ']'] = array(
        'desktop' => array('height' => 1 === $index ? '640px' : '610px', 'grid-column-start' => 1 === $index ? 'span 5' : 'span 7'),
        'mobile' => array('height' => '280px', 'grid-column-start' => 'span 12'),
    );
}
$observed['//section[@class="portfolio"]'] = array(
    'desktop' => array('display' => 'grid', 'grid-template-columns' => implode(' ', array_fill(0, 12, '120px'))),
    'mobile' => array('display' => 'grid', 'grid-template-columns' => implode(' ', array_fill(0, 12, '32.5px'))),
);

$fixture = json_decode((string) file_get_contents(__DIR__ . '/../fixtures/box-tree/index.json'), true, 32, JSON_THROW_ON_ERROR);
$defaults = $fixture['nodes']['n5']['viewports']['desktop']['used_styles'];
foreach ($defaults as $property => $value) {
    if ('none' === $value && preg_match('/^(?:margin|padding)-/', $property)) $defaults[$property] = '0px';
}
$defaults = array_replace($defaults, array(
    'overflow-x' => 'visible', 'overflow-y' => 'visible', 'row-gap' => 'normal', 'column-gap' => 'normal',
    'flex-direction' => 'row', 'flex-wrap' => 'nowrap', 'justify-content' => 'normal', 'align-items' => 'normal', 'align-content' => 'normal',
    'flex-grow' => '0', 'flex-shrink' => '1', 'flex-basis' => 'auto', 'align-self' => 'auto', 'order' => '0',
    'grid-auto-columns' => 'auto', 'grid-auto-rows' => 'auto', 'grid-auto-flow' => 'row',
    'grid-column-start' => 'auto', 'grid-column-end' => 'auto', 'grid-row-start' => 'auto', 'grid-row-end' => 'auto',
    'justify-items' => 'normal', 'justify-self' => 'auto', 'border-radius' => '0px',
    'border-top' => '0px none rgb(0, 0, 0)', 'border-right' => '0px none rgb(0, 0, 0)', 'border-bottom' => '0px none rgb(0, 0, 0)', 'border-left' => '0px none rgb(0, 0, 0)',
    'min-width' => 'auto', 'min-height' => 'auto',
));

$dom = new DOMDocument();
$dom->loadHTML($markup, LIBXML_NOERROR);
$xpath = new DOMXPath($dom);
$id = 0;
foreach ($xpath->query('//*') as $element) $element->setAttribute(BoxTree::NODE_ATTRIBUTE, 'n' . ++$id);
$targets = array();
foreach ($observed as $query => $values) $targets[$xpath->query($query)->item(0)->getAttribute(BoxTree::NODE_ATTRIBUTE)] = $values;
$source = '<!doctype html>' . $dom->saveHTML($dom->documentElement);
$nodes = array();
foreach ($xpath->query('//*') as $element) {
    $node = $element->getAttribute(BoxTree::NODE_ATTRIBUTE);
    $children = array();
    foreach ($element->childNodes as $child) if ($child instanceof DOMElement) $children[] = $child->getAttribute(BoxTree::NODE_ATTRIBUTE);
    $captures = array();
    foreach ($viewports as $name => $viewport) {
        $styles = array_replace($defaults, array('width' => $viewport['width'] . 'px', 'height' => '100px'));
        if (in_array($element->tagName, array('head', 'title', 'style'), true)) $styles['display'] = 'none';
        $styles = array_replace($styles, $targets[$node][$name] ?? array());
        $captures[$name] = array('border_box' => array('x' => 0, 'y' => 0, 'width' => (float) $styles['width'], 'height' => (float) $styles['height']), 'used_styles' => $styles);
    }
    $nodes[$node] = array('children' => $children, 'viewports' => $captures);
}
$tree = array('schema' => BoxTree::SCHEMA, 'source_path' => 'index.html', 'source_hash' => hash('sha256', $source), 'viewports' => $viewports, 'nodes' => $nodes);

// The public staged path: shared -> pages -> serialized plans -> worker -> compose.
$files = array('index.html' => $source, 'box-tree/index.json' => json_encode($tree, JSON_THROW_ON_ERROR));
$reader = new class implements PayloadReader {
    public array $payloads = array();
    public function read(array $reference): string { return $this->payloads[$reference['id']]; }
};
$artifact = array('entrypoint' => 'index.html', 'files' => array());
foreach ($files as $path => $bytes) {
    $reader->payloads[$path] = $bytes;
    $artifact['files'][] = array('path' => $path, 'payload_reference' => array(
        'schema' => 'blocks-engine/payload-reference/v1', 'id' => $path, 'bytes' => strlen($bytes), 'sha256' => hash('sha256', $bytes),
    ));
}
$compiler = new ArtifactCompiler();
$shared = $compiler->prepareShared($artifact, $reader);
$plans = $compiler->preparePages($artifact, $shared, $reader);
$shared = json_decode(json_encode($shared, JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);
$plans = json_decode(json_encode($plans, JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);
$worker = new ArtifactCompiler();
$composed = $worker->compose($shared, array_values($worker->compilePreparedPages($shared, array_values($plans), $reader)), $reader);
$result = $composed->toArray();
$plan = $composed->toWordPressSitePlanView()['wordpress_site_plan'];

$assert(count($nodes) === ($result['source_reports']['box_tree']['index.html']['nodes_with_evidence'] ?? null), 'The staged compile accepts the multi-viewport evidence.');
$assert(!empty($plan['pages'][0]['document_metadata']['engine_owned_styles']), 'The route is engine-owned, so its author stylesheets are retired.');

// The stylesheets the route delivers, after the core/image base rule the
// destination always loads first.
$css = '.wp-block-image img{box-sizing:border-box;height:auto;max-width:100%;vertical-align:bottom}';
$assets = array_column($plan['assets'], null, 'target_path');
foreach (ThemeBootstrap::documentStylesheets($plan, 'index.html') as $row) $css .= "\n" . ($assets[$row['target_path']]['content'] ?? '');
$assert(!str_contains($css, '.approach-image img') && !str_contains($css, '.pic') && !str_contains($css, '.portfolio'), 'No author selector is delivered on the evidence route.');
$assert(2 === count($tree['viewports']), 'Only desktop and mobile captures are available.');
$assert(str_contains($css, '@media (max-width:850px)') && str_contains($css, '@media (max-width:520px)'), 'Engine rules retain both author media conditions.');
$compiled = new DOMDocument();
$compiled->loadHTML('<!doctype html><html><body>' . $result['serialized_blocks'] . '</body></html>', LIBXML_NOERROR);
$compiledPath = new DOMXPath($compiled);
$element = static fn(string $query): DOMElement => $compiledPath->query($query)->item(0) ?? throw new RuntimeException("No compiled element for $query\n" . $result['serialized_blocks']);
$introImage = $element('//img[@src="https://example.com/intro.jpg"]');
$approachImage = $element('//img[@src="https://example.com/approach.jpg"]');
$approach = $element('//*[contains(concat(" ", @class, " "), " approach ")]');
$hero = $element('//*[contains(concat(" ", @class, " "), " hero ")]');
$storyImage = $element('//img[@src="https://example.com/story.jpg"]');

/** @return list<array{0: string, 1: string}> [media condition, rule] in source order. */
$rules = static function (string $css, string $media = '') use (&$rules): array {
    $found = array();
    $length = strlen($css);
    for ($offset = 0; $offset < $length;) {
        $open = strpos($css, '{', $offset);
        if (false === $open) break;
        $depth = 0;
        for ($close = $open; $close < $length; ++$close) {
            if ('{' === $css[$close]) ++$depth;
            if ('}' === $css[$close] && 0 === --$depth) break;
        }
        $prelude = trim(substr($css, $offset, $open - $offset));
        $body = substr($css, $open + 1, $close - $open - 1);
        if (str_starts_with($prelude, '@media')) {
            $condition = trim(substr($prelude, 6));
            $found = array_merge($found, $rules($body, '' === $media ? $condition : $media . ' and ' . $condition));
        } elseif (str_starts_with($prelude, '@layer') || str_starts_with($prelude, '@supports')) {
            $found = array_merge($found, $rules($body, $media));
        } elseif (!str_starts_with($prelude, '@')) {
            $found[] = array($media, $prelude, $body);
        }
        $offset = $close + 1;
    }
    return $found;
};
$applies = static function (string $media, int $width): bool {
    if ('' === $media) return true;
    foreach (explode(' and ', strtolower($media)) as $part) {
        $part = trim($part);
        if (in_array($part, array('screen', 'all', 'only screen'), true)) continue;
        if (!preg_match('/^\((max|min)-width:\s*(\d+(?:\.\d+)?)px\)$/', $part, $match)) return false;
        if ('max' === $match[1] ? $width > (float) $match[2] : $width < (float) $match[2]) return false;
    }
    return true;
};
$split = static function (string $list, string $separator): array {
    $parts = array();
    $depth = 0;
    $part = '';
    foreach (str_split($list) as $character) {
        if ('(' === $character) ++$depth;
        if (')' === $character) --$depth;
        if (0 === $depth && $separator === $character) {
            $parts[] = trim($part);
            $part = '';
            continue;
        }
        $part .= $character;
    }
    $parts[] = trim($part);
    return array_values(array_filter($parts, static fn(string $value): bool => '' !== $value));
};
$parsedRules = $rules($css);
/** The cascaded value of a property on an element at one viewport width. */
$cascaded = static function (DOMElement $target, string $property, int $width) use ($parsedRules, $applies, $split): ?string {
    $winner = null;
    $rank = null;
    foreach ($parsedRules as $order => [$media, $selectors, $body]) {
        if (!$applies($media, $width)) continue;
        $specificity = null;
        foreach ($split($selectors, ',') as $selector) {
            if (str_contains($selector, '::')) continue;
            $parsed = CssSelectorMatcher::parse($selector);
            if (CssSelectorMatcher::matches($target, $parsed)['matches']) $specificity = max($specificity ?? 0, CssSelectorMatcher::specificity($parsed));
        }
        if (null === $specificity) continue;
        foreach ($split($body, ';') as $declaration) {
            [$name, $value] = array_map('trim', explode(':', $declaration, 2)) + array(1 => '');
            if (strtolower($name) !== $property) continue;
            $important = (bool) preg_match('/!\s*important$/i', $value);
            $candidate = array($important ? 2 : 0, $specificity, $order);
            if (null === $rank || $candidate > $rank) {
                $rank = $candidate;
                $winner = trim((string) preg_replace('/!\s*important$/i', '', $value));
            }
        }
    }
    foreach ($split($target->getAttribute('style'), ';') as $declaration) {
        [$name, $value] = array_map('trim', explode(':', $declaration, 2)) + array(1 => '');
        if (strtolower($name) === $property && (null === $rank || $rank[0] < 2)) $winner = $value;
    }
    return $winner;
};

$expect = static function (DOMElement $target, string $label, string $property, array $byWidth) use ($cascaded, $assert, $css, $result): void {
    foreach ($byWidth as $width => $expected) {
        $actual = $cascaded($target, $property, $width);
        $assert($expected === $actual, "At {$width}px the $label gets $property:$expected.", 'Got ' . var_export($actual, true) . "\n" . $result['serialized_blocks'] . "\n" . $css);
    }
};

$buttonLink = $element('//a[@href="/contact"]');
$buttonWrapper = $buttonLink->parentNode;
$assert($buttonWrapper instanceof DOMElement && str_contains($buttonWrapper->getAttribute('class'), 'wp-block-button'), 'The CTA becomes core/button.');
foreach (array(1440, 768, 390) as $width) {
    $assert(in_array($cascaded($buttonWrapper, 'padding', $width), array(null, '0', '0px'), true), 'The button wrapper has no padding.');
    $assert(null === $cascaded($buttonWrapper, 'padding-top', $width), 'No captured padding lands on the button wrapper.');
}
$expect($buttonLink, 'button link', 'padding', array(1440 => '17px 30px', 768 => '17px 30px', 390 => '17px 30px'));
$expect($buttonLink, 'button link', 'display', array(1440 => 'inline-flex', 768 => 'inline-flex', 390 => 'inline-flex'));
$expect($buttonLink, 'button link', 'gap', array(1440 => '24px', 768 => '12px', 390 => '12px'));
$footerParagraph = $element('//footer//p');
$expect($footerParagraph, 'footer paragraph line box', 'line-height', array(1440 => '17px', 768 => '17px', 390 => '17px'));

// The footer renders from its template part. Its author variants apply there
// exactly as on page content: 61px at the uncaptured 768px, and the 8% band
// at the narrowest capture, whose basis is the body's captured box.
$parts = array_column($plan['template_parts'], null, 'slug');
$assert(isset($parts['footer']['canonical_block_markup']), 'The footer is a template part.');
$footerPart = new DOMDocument();
$footerPart->loadHTML('<!doctype html><html><body>' . $parts['footer']['canonical_block_markup'] . '</body></html>', LIBXML_NOERROR);
$footer = (new DOMXPath($footerPart))->query('//footer')->item(0) ?? throw new RuntimeException('No footer in the footer template part.');
foreach (array('padding-left', 'padding-right') as $property) {
    $expect($footer, 'footer template part', $property, array(1440 => '115px', 851 => '115px', 850 => '61px', 768 => '61px', 521 => '61px', 520 => '8%', 390 => '8%'));
}

// A block-level `<b>` that is the only content of a boxless inline-layout
// carrier paragraph is the box the source laid out: it keeps every captured
// declaration, display and margin as well as its typography.
$addon = $element('//p[contains(concat(" ", @class, " "), " blocks-engine-inline-layout-carrier ")]/b');
$fontFamilies = array_column($plan['theme']['design_system']['font_families'] ?? array(), 'name', 'slug');
$family = static function (?string $value) use ($fontFamilies): string {
    if (null !== $value && preg_match('/^var\(--wp--preset--font-family--([\w-]+)\)$/', $value, $match)) return (string) ($fontFamilies[$match[1]] ?? $value);
    return trim((string) $value, '"\'');
};
$marginTop = static function (?string $margin, ?string $longhand): ?string {
    if (null !== $longhand) return $longhand;
    return null === $margin ? null : (preg_split('/\s+/', trim($margin))[0] ?? null);
};
foreach (array(1440, 768, 390) as $width) {
    $assert('block' === $cascaded($addon, 'display', $width), "At {$width}px the add-on's <b> keeps display:block.", (string) $cascaded($addon, 'display', $width) . "\n" . $result['serialized_blocks']);
    $assert(str_contains((string) $cascaded($addon, 'font-size', $width), '26px'), "At {$width}px the add-on's <b> keeps its font-size.", (string) $cascaded($addon, 'font-size', $width));
    $assert('Playfair Display' === $family($cascaded($addon, 'font-family', $width)), "At {$width}px the add-on's <b> keeps its font-family.", (string) $cascaded($addon, 'font-family', $width) . ' ' . json_encode($fontFamilies));
    $top = $marginTop($cascaded($addon, 'margin', $width), $cascaded($addon, 'margin-top', $width));
    $assert('15px' === $top, "At {$width}px the add-on's <b> keeps its margin-top.", (string) $top);
}

// A carrier splits by role: the CTA's margin and flex-item placement stay on
// the block's outermost box, the flex item of the plan card; its padding,
// display and typography go to the link.
$buyLink = $element('//a[@href="/buy"]');
$buyButtons = $buyLink->parentNode?->parentNode;
$assert($buyButtons instanceof DOMElement && str_contains($buyButtons->getAttribute('class'), 'wp-block-buttons'), 'The plan CTA becomes core/buttons.');
$expect($buyButtons, 'plan CTA buttons wrapper', 'margin', array(1440 => '22px 0px 0px', 768 => '22px 0px 0px', 390 => '22px 0px 0px'));
$expect($buyButtons, 'plan CTA buttons wrapper', 'align-self', array(1440 => 'flex-start', 390 => 'flex-start'));
$expect($buyLink, 'plan CTA link', 'padding', array(1440 => '17px 30px', 390 => '17px 30px'));
foreach (array(1440, 768, 390) as $width) {
    foreach (array('margin', 'margin-top') as $property) {
        $assert(in_array($cascaded($buyLink, $property, $width), array(null, '0', '0px', 'initial'), true), "At {$width}px the plan CTA link has no $property of its own.");
    }
    $assert(null === $cascaded($buyButtons, 'padding', $width), "At {$width}px the plan CTA wrapper has no padding.");
}

// An inline phrasing element keeps its own captured typography on its own
// box, not on a nested carrier: otherwise `<small>` takes the price's size.
$small = $element('//small');
foreach (array(1440, 768, 390) as $width) {
    $assert(str_contains((string) $cascaded($small, 'font-size', $width), '11px'), "At {$width}px the price's <small> keeps its own font-size.", (string) $cascaded($small, 'font-size', $width));
    $assert('13.2px' === $cascaded($small, 'line-height', $width), "At {$width}px the price's <small> keeps its own line-height.", (string) $cascaded($small, 'line-height', $width));
}

// Only 1440 and 390 were captured; 768 exercises the unobserved author variant.
$widths = array(1440, 1000, 800, 768, 390);
$expect($introImage, 'intro image', 'max-height', array_fill_keys($widths, '650px'));
$expect($introImage, 'intro image', 'object-fit', array_fill_keys($widths, 'cover'));
// No author rule sizes the intro image's height: it is the 2:3 image's own
// ratio at its width, clamped. The captures' heights (650px, 585px) must not
// freeze, or a 343px-wide image at an uncaptured width keeps 585px (#6580).
$expect($introImage, 'intro image', 'height', array_fill_keys($widths, 'auto'));
foreach (array(1, 2) as $index) {
    $pic = $element('//*[contains(concat(" ", @class, " "), " pic ")][' . $index . ']');
    $expect($pic, 'portfolio item ' . $index, 'grid-column-start', array(1440 => 1 === $index ? 'span 5' : 'span 7', 851 => 1 === $index ? 'span 5' : 'span 7', 850 => 1 === $index ? 'span 12' : 'span 6', 768 => 1 === $index ? 'span 12' : 'span 6', 521 => 1 === $index ? 'span 12' : 'span 6', 520 => 'span 12', 390 => 'span 12'));
    $expect($pic, 'portfolio item ' . $index, 'height', array(1440 => 1 === $index ? '640px' : '610px', 768 => 1 === $index ? '500px' : '340px', 390 => '280px'));
}
$expect($approachImage, 'approach image', 'height', array(1440 => '680px', 1000 => '680px', 800 => '450px', 768 => '450px', 390 => '450px'));
$expect($approachImage, 'approach image', 'object-fit', array_fill_keys($widths, 'cover'));
$expect($approach, 'approach grid', 'grid-template-columns', array(1440 => '1fr 1fr', 1000 => '1fr 1fr', 800 => '1fr', 768 => '1fr', 390 => '1fr'));

// The source has no box between the section and its image: a wrapper the
// engine adds must not generate one with its own geometry.
foreach (array('intro image' => $introImage, 'approach image' => $approachImage) as $label => $image) {
    $figure = $image->parentNode;
    $assert($figure instanceof DOMElement && 'contents' === $cascaded($figure, 'display', 1440), "The core/image figure around the $label generates no box.");
}

// An authored figure keeps its own box, but the image it wraps is sized by the
// image's own declarations, not core/image's `height:auto;max-width:100%`.
$storyFigure = $storyImage->parentNode;
$assert($storyFigure instanceof DOMElement && 'figure' === $storyFigure->tagName, 'The authored story figure is kept.');
$assert('contents' !== $cascaded($storyFigure, 'display', 1440), 'The authored story figure keeps its own box.');
$expect($storyImage, 'story image', 'max-height', array_fill_keys($widths, '650px'));
$expect($storyImage, 'story image', 'object-fit', array_fill_keys($widths, 'cover'));
$expect($storyImage, 'story image', 'width', array_fill_keys($widths, '100%'));

/**
 * The used block size of an element in a viewport: its specified height
 * clamped by its max-height, then its min-height.
 */
$usedHeight = static function (DOMElement $target, int $width, int $height) use ($cascaded): ?float {
    // The fixture's images are 2:3 and fill the viewport width.
    $auto = 'img' === $target->tagName ? $width * 1.5 : 0.0;
    $resolve = static function (string $property, float $fallback) use ($cascaded, $target, $width, $height): ?float {
        $value = $cascaded($target, $property, $width);
        if (null === $value || in_array($value, array('auto', 'none'), true)) return $fallback;
        return CssFluidLength::evaluate($value, $width, $height);
    };
    [$specified, $minimum, $maximum] = array($resolve('height', $auto), $resolve('min-height', 0.0), $resolve('max-height', INF));
    return null === $specified || null === $minimum || null === $maximum ? null : max($minimum, min($maximum, $specified));
};
$expectHeight = static function (DOMElement $target, string $label, array $cases) use ($usedHeight, $assert, $css, $result): void {
    foreach ($cases as [$width, $height, $expected]) {
        $actual = $usedHeight($target, $width, $height);
        $assert(null !== $actual && abs($actual - $expected) < 0.5, "In a {$width}x{$height} viewport the $label is {$expected}px tall.", 'Got ' . var_export($actual, true) . "\n" . $result['serialized_blocks'] . "\n" . $css);
    }
};
foreach (array(1 => 500, 2 => 340) as $index => $height) {
    $pic = $element('//*[contains(concat(" ", @class, " "), " pic ")][' . $index . ']');
    $expectHeight($pic, 'portfolio item ' . $index, array(array(768, 1024, $height)));
    $span = (int) str_replace('span ', '', (string) $cascaded($pic, 'grid-column-start', 768));
    $assert((1 === $index ? 768 : 384) === 768 / 12 * $span, 'At uncaptured 768px the portfolio item ' . $index . ' occupies its authored grid width.');
}
// Viewport heights the captures (900, 640) never saw.
$expectHeight($hero, 'hero', array(
    array(1440, 1000, 880), array(1440, 760, 760), array(1440, 600, 680),
    array(768, 700, 700), array(600, 660, 680), array(500, 660, 660),
    array(390, 844, 844), array(390, 600, 650),
));
$expectHeight($storyImage, 'story image', array(array(1440, 700, 650), array(768, 600, 650), array(390, 844, 585)));
$expectHeight($introImage, 'intro image', array(array(1440, 900, 650), array(768, 1024, 650), array(390, 844, 585), array(343, 700, 514.5)));

// Compare actual browser boxes, including the line box around inline text.
$chrome = getenv('CHROME_PATH') ?: trim((string) shell_exec('command -v chromium || command -v google-chrome'));
if ('' === $chrome) throw new RuntimeException('Browser required for source-node box contract.');
$directory = sys_get_temp_dir() . '/box-tree-source-node-' . bin2hex(random_bytes(6));
mkdir($directory);
try {
    // Source and compiled elements, in the order the comparison below lists them.
    $probes = array(
        'source' => '[document.querySelector("a[href=\"/contact\"]"),document.querySelector("footer p"),document.querySelector(".buy"),document.querySelector(".plan"),document.querySelector(".price small"),document.querySelector("footer")]',
        'compiled' => '[document.querySelector("a[href=\"/contact\"]"),document.querySelector("footer p"),document.querySelector("a[href=\"/buy\"]").closest(".wp-block-buttons"),document.querySelector(".plan"),document.querySelector("small"),document.querySelector("footer")]',
    );
    $probe = static fn(string $document): string => '<script>document.body.dataset.boxes=JSON.stringify(' . $probes[$document]
        . '.map(e=>{const b=e.getBoundingClientRect();return [b.width,b.height]}));</script>';
    $baseline = '<style>body{font-family:Arial;font-size:16px}a{box-sizing:border-box}</style>';
    file_put_contents($directory . '/source.html', $markup . $baseline . $probe('source'));
    file_put_contents($directory . '/compiled.html', '<!doctype html><style>' . $css . '</style>' . $baseline . $result['serialized_blocks'] . $probe('compiled'));
    foreach (array(1440, 768, 390) as $width) {
        $boxes = array();
        foreach (array('source', 'compiled') as $document) {
            $output = shell_exec(\Automattic\BlocksEngine\PhpTransformer\Tests\Support\HeadlessChrome::tmpdirPrefix() . escapeshellarg($chrome)
                . ' --headless --no-sandbox --disable-gpu --user-data-dir=' . escapeshellarg($directory . '/profile')
                . ' --window-size=' . $width . ',900 --dump-dom --virtual-time-budget=1000 '
                . escapeshellarg('file://' . $directory . '/' . $document . '.html') . ' 2>/dev/null');
            if (!preg_match('/data-boxes="([^"\n]+)"/', (string) $output, $match)) throw new RuntimeException('Missing browser boxes: ' . $output);
            $boxes[$document] = json_decode(html_entity_decode($match[1], ENT_QUOTES), true, 32, JSON_THROW_ON_ERROR);
        }
        $compared = array(
            0 => array('button', array(0, 1)),
            1 => array('footer paragraph', array(1)),
            // The CTA's outermost box is the link's border box: its margin sits outside it.
            2 => array('plan CTA outer box', array(0, 1)),
            3 => array('plan card', array(1)),
            4 => array("price's <small>", array(0, 1)),
            // The bare legal span's line box keeps its 17px line height over the footer's strut.
            5 => array('footer with its legal line', array(1)),
        );
        foreach ($compared as $index => [$label, $axes]) {
            foreach ($axes as $axis) $assert(abs($boxes['source'][$index][$axis] - $boxes['compiled'][$index][$axis]) < 0.5,
                "At {$width}px the $label keeps its source " . (0 === $axis ? 'width' : 'line box height') . '.', json_encode($boxes, JSON_THROW_ON_ERROR));
        }
    }
} finally {
    shell_exec('rm -rf ' . escapeshellarg($directory));
}
