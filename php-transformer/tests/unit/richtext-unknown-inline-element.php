<?php
declare(strict_types=1);

// Issue #2176: an unknown or custom inline element inside paragraph text
// (`<bdt>`, or a hyphenated custom element like `<x-note>`) was kept in
// RichText `content` by the lowering, then rejected by the editability gate
// as structural HTML: one such paragraph aborted the whole import. Lowering
// and the gate now share one definition of the inline tags RichText content
// may carry (`Contract\RichTextInlineTags`), and the lowering normalizes
// unknown and custom elements: onto the RichText-safe `<mark>` carrier when
// they carry a class/style/data identity, unwrapped with their text kept
// otherwise.

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\Contract\EditabilityPolicy;
use Automattic\BlocksEngine\PhpTransformer\Contract\EditabilityReport;
use Automattic\BlocksEngine\PhpTransformer\Contract\RichTextInlineTags;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;
use Automattic\BlocksEngine\PhpTransformer\WordPress\BlockValidityValidator;

$failures = 0;
$passes = 0;
$assert = static function (bool $condition, string $message) use (&$failures, &$passes): void {
    if ( $condition ) {
        ++$passes;
        return;
    }
    ++$failures;
    fwrite(STDERR, "FAIL: {$message}\n");
};

$transform = static fn (string $html, string $css = ''): array =>
    ( new HtmlTransformer() )->transform($html, array( 'static_css' => $css ))->toArray();

/** @param array<int, array<string, mixed>> $blocks @return array<int, array<string, mixed>> */
$flatten = static function (array $blocks) use (&$flatten): array {
    $flat = array();
    foreach ( $blocks as $block ) {
        $flat[] = $block;
        $flat = array_merge($flat, $flatten(is_array($block['innerBlocks'] ?? null) ? $block['innerBlocks'] : array()));
    }
    return $flat;
};

$gate = static function (array $result, string $name): array {
    $report = ( new EditabilityReport() )->fromBlocks(
        is_array($result['blocks'] ?? null) ? $result['blocks'] : array(),
        $name,
        (string) ($result['serialized_blocks'] ?? '')
    );
    return array( $report, ( new EditabilityPolicy() )->evaluate($report) );
};

$paragraphs = static fn (array $result): array => array_values(array_filter(
    $flatten(is_array($result['blocks'] ?? null) ? $result['blocks'] : array()),
    static fn (array $block): bool => 'core/paragraph' === ($block['blockName'] ?? '')
));

$cases = array(
    'bdt' => array(
        '<p>Last updated <bdt class="question">December 16, 2021</bdt> by <bdt class="block-component"></bdt>the <strong>Company</strong>.</p>',
        'Last updated December 16, 2021 by the Company.',
    ),
    'hyphenated custom element' => array(
        '<p>See <x-note data-kind="aside">the footnote</x-note> and <x-note>this one</x-note>.</p>',
        'See the footnote and this one.',
    ),
);

foreach ( $cases as $label => list( $html, $text ) ) {
    $result = $transform($html);
    list( $report, $policy ) = $gate($result, $label);
    $paragraph = $paragraphs($result)[0] ?? array();
    $content = (string) ($paragraph['attrs']['content'] ?? '');

    $assert(array() !== $paragraph, "{$label}: converts to a core/paragraph");
    $assert(
        0 === (int) ($report['metrics']['structural_rich_text_attribute_count'] ?? -1),
        "{$label}: paragraph content carries no tag outside the shared RichText inline set; got: {$content}"
    );
    $assert('failed' !== ($policy['status'] ?? null), "{$label}: the editability policy accepts the converted paragraph");
    $assert(
        $text === html_entity_decode(strip_tags($content), ENT_QUOTES | ENT_HTML5, 'UTF-8'),
        "{$label}: the paragraph text is preserved; got: " . strip_tags($content)
    );
    $validity = ( new BlockValidityValidator() )->validateBlocks($result['blocks'] ?? array());
    $assert('pass' === ($validity['status'] ?? ''), "{$label}: the converted block is Gutenberg-valid");
}

// A classed unknown element keeps its class on the RichText-safe carrier, so
// class-scoped author rules keep styling it.
$styled = $transform(
    '<p>Contact <bdt class="question">us</bdt> today.</p>',
    '.question { color: rgb(200, 0, 0); }'
);
$styledContent = (string) ($paragraphs($styled)[0]['attrs']['content'] ?? '');
$assert(
    1 === preg_match('/<mark\b[^>]*class="[^"]*\bquestion\b[^"]*"[^>]*>us<\/mark>/', $styledContent),
    'a classed unknown element becomes a <mark> carrier that keeps its class; got: ' . $styledContent
);
$assert(str_contains($styledContent, 'role="none"'), 'the repurposed carrier does not announce a highlight role');

// The shared definition is the gate's and the lowering's single source.
$assert(RichTextInlineTags::isAllowed('strong') && RichTextInlineTags::isAllowed('SPAN'), 'known RichText formats are allowed inline');
$assert(! RichTextInlineTags::isAllowed('bdt') && ! RichTextInlineTags::isAllowed('x-note') && ! RichTextInlineTags::isAllowed('div'), 'unknown and block tags are not allowed inline');

// A genuinely structural tag inside RichText is still caught by the gate.
list( $structuralReport ) = $gate(
    array(
        'blocks' => array(
            array( 'blockName' => 'core/paragraph', 'attrs' => array( 'content' => 'Before <div class="inner">block</div> after' ), 'innerBlocks' => array() ),
        ),
    ),
    'structural'
);
$assert(
    1 === (int) ($structuralReport['metrics']['structural_rich_text_attribute_count'] ?? 0),
    'a <div> inside paragraph content is still reported as structural rich text'
);

// And a source <div> inside a <p> is still lowered structurally, never into
// paragraph content.
$nested = $transform('<p>Before <div class="inner">block</div> after</p>');
list( $nestedReport ) = $gate($nested, 'nested');
$assert(
    0 === (int) ($nestedReport['metrics']['structural_rich_text_attribute_count'] ?? -1),
    'a <div> inside a <p> is never emitted as paragraph RichText content'
);

if ( $failures > 0 ) {
    fwrite(STDERR, "{$failures} failure(s), {$passes} pass(es)\n");
    exit(1);
}

echo "richtext-unknown-inline-element: {$passes} passed\n";
