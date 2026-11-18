<?php
declare(strict_types=1);

require __DIR__ . '/../../vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\PayloadReader;
use Automattic\BlocksEngine\PhpTransformer\Contract\BoxTree;
use Automattic\BlocksEngine\PhpTransformer\Css\CssSelectorMatcher;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\ThemeBootstrap;

// Issue #6580: a route with box-tree evidence delivers only engine-owned CSS
// (EvidenceRouteStylesheets retires its author sheets), so the carriers must
// reproduce the author's breakpoint rules at every captured viewport. The
// source sizes images with breakpoint rules; the evidence captures it at
// three widths. The delivered CSS is cascaded against the compiled markup at
// each width.
$assert = static function (bool $condition, string $message, string $detail = ''): void {
    if (!$condition) throw new RuntimeException($message . ('' === $detail ? '' : "\n" . $detail));
    echo "PASS: $message\n";
};

$author = '.intro img{display:block;width:100%;max-height:650px;object-fit:cover}'
    . '.approach{display:grid;grid-template-columns:1fr 1fr;column-gap:40px}'
    . '.approach-image img{display:block;width:100%;height:680px;object-fit:cover}'
    . '@media (max-width:900px){.approach{grid-template-columns:1fr}.approach-image img{height:450px}}';
$markup = '<!doctype html><html><head><title>Responsive</title><style>' . $author . '</style></head><body><main>'
    . '<section class="intro"><h1>Intro</h1><img src="https://example.com/intro.jpg" alt="Intro"></section>'
    . '<section class="approach"><div class="approach-text"><h2>Approach</h2><p>How we work.</p></div>'
    . '<div class="approach-image"><img src="https://example.com/approach.jpg" alt="Approach"></div></section>'
    . '</main></body></html>';
$viewports = array('desktop' => array('width' => 1440, 'height' => 900), 'tablet' => array('width' => 768, 'height' => 1024), 'mobile' => array('width' => 390, 'height' => 844));

// Used values the browser reports for the boxes the author rules size. Every
// other box fills the viewport width; the intro image is 2:3, clamped to 650px.
$column = array('desktop' => array('width' => '700px'), 'tablet' => array('width' => '768px'), 'mobile' => array('width' => '390px'));
$observed = array(
    '//section[@class="intro"]/img' => array(
        'desktop' => array('display' => 'block', 'width' => '1440px', 'height' => '650px', 'max-height' => '650px', 'object-fit' => 'cover'),
        'tablet' => array('display' => 'block', 'width' => '768px', 'height' => '650px', 'max-height' => '650px', 'object-fit' => 'cover'),
        'mobile' => array('display' => 'block', 'width' => '390px', 'height' => '585px', 'max-height' => '650px', 'object-fit' => 'cover'),
    ),
    '//section[@class="approach"]' => array(
        'desktop' => array('display' => 'grid', 'grid-template-columns' => '700px 700px', 'column-gap' => '40px'),
        'tablet' => array('display' => 'grid', 'grid-template-columns' => '768px', 'column-gap' => '40px'),
        'mobile' => array('display' => 'grid', 'grid-template-columns' => '390px', 'column-gap' => '40px'),
    ),
    '//div[@class="approach-text"]' => $column,
    '//div[@class="approach-image"]' => $column,
    '//div[@class="approach-image"]/img' => array(
        'desktop' => array('display' => 'block', 'width' => '700px', 'height' => '680px', 'object-fit' => 'cover'),
        'tablet' => array('display' => 'block', 'width' => '768px', 'height' => '450px', 'object-fit' => 'cover'),
        'mobile' => array('display' => 'block', 'width' => '390px', 'height' => '450px', 'object-fit' => 'cover'),
    ),
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
$assert(!str_contains($css, '.approach-image img'), 'No author selector is delivered on the evidence route.');
$compiled = new DOMDocument();
$compiled->loadHTML('<!doctype html><html><body>' . $result['serialized_blocks'] . '</body></html>', LIBXML_NOERROR);
$compiledPath = new DOMXPath($compiled);
$element = static fn(string $query): DOMElement => $compiledPath->query($query)->item(0) ?? throw new RuntimeException("No compiled element for $query\n" . $result['serialized_blocks']);
$introImage = $element('//img[@src="https://example.com/intro.jpg"]');
$approachImage = $element('//img[@src="https://example.com/approach.jpg"]');
$approach = $element('//*[contains(concat(" ", @class, " "), " approach ")]');

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

// The captures (1440, 768, 390) and widths either side of the author's 900px breakpoint.
$widths = array(1440, 1000, 800, 768, 390);
$expect($introImage, 'intro image', 'max-height', array_fill_keys($widths, '650px'));
$expect($introImage, 'intro image', 'object-fit', array_fill_keys($widths, 'cover'));
$expect($introImage, 'intro image', 'height', array(1440 => '650px', 768 => '650px', 390 => '585px'));
$expect($approachImage, 'approach image', 'height', array(1440 => '680px', 1000 => '680px', 800 => '450px', 768 => '450px', 390 => '450px'));
$expect($approachImage, 'approach image', 'object-fit', array_fill_keys($widths, 'cover'));
$expect($approach, 'approach grid', 'grid-template-columns', array(1440 => '1fr 1fr', 1000 => '1fr 1fr', 800 => '1fr', 768 => '1fr', 390 => '1fr'));

// The source has no box between the section and its image: a wrapper the
// engine adds must not generate one with its own geometry.
foreach (array('intro image' => $introImage, 'approach image' => $approachImage) as $label => $image) {
    $figure = $image->parentNode;
    $assert($figure instanceof DOMElement && 'contents' === $cascaded($figure, 'display', 1440), "The core/image figure around the $label generates no box.");
}
