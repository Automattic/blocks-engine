<?php
declare(strict_types=1);

/**
 * Wrapper folding on resolved styles (#7379): a builder-style chain
 * `section#a > div.lane.skin > div.col > p` under a stylesheet with media
 * rules, descendant and child combinators and an `#id` rule folds the padded
 * `.lane` wrapper into the `.col` content group. Their padding, paint, inherited text and every
 * ancestor-scoped declaration still resolve on the merged paragraph at 390
 * and 1440 pixels wide.
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\Css\CssSelectorMatcher;
use Automattic\BlocksEngine\PhpTransformer\Css\CssStylesheetTransformer;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\SourceDeclarations;

function rwc_assert(bool $condition, string $message): void
{
    if (! $condition) {
        fwrite(STDERR, "resolved-wrapper-composition: {$message}\n");
        exit(1);
    }
}

/**
 * Flat style rules with at most one enclosing `@media` prelude.
 *
 * @return list<array{media: string, selector: string, declarations: array<string, string>}>
 */
function rwc_rules(string $css): array
{
    $rules = array();
    $walk = static function (string $block, string $media) use (&$rules, &$walk): void {
        $position = 0;
        $size = strlen($block);
        while ($position < $size) {
            $open = strpos($block, '{', $position);
            if (false === $open) return;
            $prelude = trim(substr($block, $position, $open - $position));
            $depth = 1;
            for ($cursor = $open + 1; $cursor < $size && $depth > 0; ++$cursor) {
                if ('{' === $block[$cursor]) ++$depth;
                if ('}' === $block[$cursor]) --$depth;
            }
            $body = substr($block, $open + 1, $cursor - $open - 2);
            $position = $cursor;
            if (str_starts_with($prelude, '@media')) {
                $walk($body, trim(substr($prelude, 6)));
                continue;
            }
            if (str_starts_with($prelude, '@')) continue;
            foreach (CssStylesheetTransformer::splitSelectorList($prelude) ?? array() as $selector) {
                $rules[] = array('media' => $media, 'selector' => trim($selector), 'declarations' => SourceDeclarations::parse($body));
            }
        }
    };
    $walk($css, '');
        return $rules;
}

function rwc_media_applies(string $media, int $width): bool
{
    if ('' === $media) return true;
    if (preg_match('/max-width:\s*(\d+)px/', $media, $match) && $width > (int) $match[1]) return false;
    if (preg_match('/min-width:\s*(\d+)px/', $media, $match) && $width < (int) $match[1]) return false;
    return true;
}

/**
 * The winning value of each property on one element at one viewport width.
 *
 * @param list<array{media: string, selector: string, declarations: array<string, string>}> $rules
 * @return array<string, string>
 */
function rwc_cascade(DOMElement $element, array $rules, int $width): array
{
    $winners = array();
    foreach ($rules as $order => $rule) {
        if (! rwc_media_applies($rule['media'], $width)) continue;
        $parsed = CssSelectorMatcher::parse($rule['selector']);
        if (! $parsed['supported'] || ! CssSelectorMatcher::matches($element, $parsed)['matches']) continue;
        $counts = CssSelectorMatcher::specificityCounts($parsed);
        foreach ($rule['declarations'] as $property => $value) {
            $important = (bool) preg_match('/!\s*important\s*$/i', $value);
            $rank = array($important ? 1 : 0, 0, $counts['ids'], $counts['classes'], $counts['types'], $order);
            if (! isset($winners[$property]) || $rank >= $winners[$property]['rank']) {
                $winners[$property] = array('rank' => $rank, 'value' => trim((string) preg_replace('/\s*!\s*important\s*$/i', '', $value)));
            }
        }
    }
    foreach (SourceDeclarations::parse($element->getAttribute('style')) as $property => $value) {
        if (! isset($winners[$property]) || 0 === $winners[$property]['rank'][0]) $winners[$property] = array('rank' => array(0, 1), 'value' => $value);
    }
    $values = array_map(static fn (array $winner): string => strtolower($winner['value']), $winners);
    // Box shorthands lose to a later-winning longhand only by rank; the
    // fixture declares shorthands alone, so expand them after the cascade.
    foreach (array('padding', 'margin') as $box) {
        if (! isset($values[$box])) continue;
        $parts = preg_split('/\s+/', $values[$box]) ?: array();
        $values[$box . '-top'] ??= $parts[0];
        $values[$box . '-left'] ??= $parts[3] ?? $parts[1] ?? $parts[0];
    }
    return $values;
}

/**
 * What the reader sees on the paragraph: inherited text, total padding and
 * the nearest paint of the boxes between the section and the paragraph.
 *
 * @param list<array{media: string, selector: string, declarations: array<string, string>}> $rules
 * @return array<string, string>
 */
function rwc_rendered(DOMElement $paragraph, DOMElement $section, array $rules, int $width): array
{
    $chain = array();
    for ($node = $paragraph; $node instanceof DOMElement && $node !== $section; $node = $node->parentNode) $chain[] = $node;
    $rendered = array('padding-top' => 0.0, 'padding-left' => 0.0, 'margin-top' => 0.0);
    foreach ($chain as $node) {
        $values = rwc_cascade($node, $rules, $width);
        foreach (array('padding-top', 'padding-left', 'margin-top') as $property) {
            $rendered[$property] += 'auto' === ($values[$property] ?? '') ? 0.0 : (float) ($values[$property] ?? 0);
        }
        foreach (array('color', 'line-height', 'letter-spacing') as $property) {
            if (! isset($rendered[$property]) && isset($values[$property])) $rendered[$property] = $values[$property];
        }
        $paint = $values['background-color'] ?? $values['background'] ?? null;
        if (! isset($rendered['paint']) && null !== $paint && 'transparent' !== $paint) $rendered['paint'] = $paint;
    }
    return array_map('strval', $rendered);
}

$html = '<section id="a"><div class="lane skin"><div class="col"><p>Hello</p><p>World</p></div></div></section>';
$css = 'p{margin:0}'
    . '.lane{padding:24px;background:#eeeeee}'
    . '@media (max-width:767px){.lane{padding:8px}}'
    . '#a>.lane{margin:0 auto}'
    . '.skin p{line-height:1.8}'
    . '.lane>.col{letter-spacing:1px}'
    . '#a .col{color:#123456}';

$result = (new ArtifactCompiler())->compile(array('files' => array(
    array('path' => 'index.html', 'kind' => 'html', 'content' => '<html><head><link rel="stylesheet" href="s.css"></head><body>' . $html . '</body></html>'),
    array('path' => 's.css', 'kind' => 'css', 'content' => $css),
)))->toArray();

$output = new DOMDocument();
@$output->loadHTML('<html><body>' . $result['serialized_blocks'] . '</body></html>');
$xpath = new DOMXPath($output);
$paragraph = $xpath->query('//p[normalize-space(.)="Hello"]')->item(0);
rwc_assert($paragraph instanceof DOMElement, 'paragraph missing from output');
$merged = $paragraph->parentNode;
$section = $merged?->parentNode;
rwc_assert($merged instanceof DOMElement && $section instanceof DOMElement && 'section' === $section->tagName && 'a' === $section->getAttribute('id'), 'the wrapper did not fold into the content group: ' . $result['serialized_blocks']);
$classes = preg_split('/\s+/', $merged->getAttribute('class')) ?: array();
foreach (array('lane', 'skin', 'col') as $class) {
    rwc_assert(in_array($class, $classes, true), "folded wrapper class {$class} is not on the merged group");
}

$outputCss = '';
foreach ($result['assets'] as $asset) {
    if ('css' === ($asset['kind'] ?? '') && is_string($asset['content'] ?? null) && ! str_contains((string) ($asset['path'] ?? ''), 'editor-')) {
        $outputCss .= $asset['content'] . "\n";
    }
}
$outputRules = rwc_rules($outputCss);

$source = new DOMDocument();
@$source->loadHTML('<html><body>' . $html . '</body></html>');
$sourceParagraph = $source->getElementsByTagName('p')->item(0);
$sourceSection = $source->getElementsByTagName('section')->item(0);
$sourceRules = rwc_rules($css);

foreach (array(390, 1440) as $width) {
    $expected = rwc_rendered($sourceParagraph, $sourceSection, $sourceRules, $width);
    $actual = rwc_rendered($paragraph, $section, $outputRules, $width);
    foreach ($expected as $property => $value) {
        rwc_assert(($actual[$property] ?? null) === $value, "{$property} at {$width}px is " . var_export($actual[$property] ?? null, true) . ", expected {$value}");
    }
}

echo "resolved-wrapper-composition: passed\n";
