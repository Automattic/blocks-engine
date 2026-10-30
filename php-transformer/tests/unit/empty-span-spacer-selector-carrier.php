<?php
declare(strict_types=1);

/**
 * An author rule that names an empty painted span must keep matching after the
 * span lowers to a standalone spacer block.
 *
 * Outside RichText, an empty, sized and painted span becomes its own
 * `core/spacer` block (InlineContentElementConverter), not a `<mark>` carrier.
 * The selector preparer used to give that span a rich-text marker anyway, so a
 * tag-qualified rule (`span.dot`, `span.dot:hover`, or one inside a media query)
 * was rewritten to `:where(mark[style*=marker],mark[style$=marker],span[data-…])`,
 * which no element on the page carries. The rule was dead: it could not reach
 * the emitted `div.wp-block-spacer`, and dead-marker pruning would remove it.
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\Css\CssSelectorMatcher;

$failures = 0;
$passes = 0;
$assert = static function (bool $condition, string $message) use (&$failures, &$passes): void {
    if ( $condition ) {
        ++$passes;
        return;
    }
    ++$failures;
    fwrite(STDERR, 'FAIL: ' . $message . PHP_EOL);
};

$plan = ( new ArtifactCompiler() )->compile(array('entrypoint' => 'website/index.html', 'files' => array(
    'website/index.html' => '<link rel="stylesheet" href="css/site.css"><main><span class="plain"></span><span class="dot"></span></main>',
    'website/css/site.css' => '.plain,.dot{display:block;width:24px;height:4px}.plain{background:#163}'
        . 'span.dot{background:#f86}span.dot:hover{background:#0a0}@media (max-width:600px){span.dot{background:#00a}}',
)))->toArray()['source_reports']['wordpress_site_plan'] ?? array();

$markup = (string) ($plan['pages'][0]['canonical_block_markup'] ?? '');
$css = implode("\n", array_map(
    static fn (array $write): string => str_ends_with((string) ($write['target_path'] ?? ''), '.css') ? (string) ($write['payload']['data'] ?? '') : '',
    $plan['writes'] ?? array()
));

$document = new DOMDocument();
$previous = libxml_use_internal_errors(true);
$document->loadHTML('<?xml encoding="UTF-8"><body>' . $markup . '</body>', LIBXML_HTML_NODEFDTD | LIBXML_NONET);
libxml_clear_errors();
libxml_use_internal_errors($previous);
$spacer = null;
foreach ( $document->getElementsByTagName('div') as $div ) {
    if ( preg_match('/(?:^|\s)wp-block-spacer(?:\s|$)/', $div->getAttribute('class')) && preg_match('/(?:^|\s)dot(?:\s|$)/', $div->getAttribute('class')) ) {
        $spacer = $div;
    }
}
$assert($spacer instanceof DOMElement, 'the empty painted span lowers to a spacer block');

$splitSelectorList = static function (string $list): array {
    $parts = array();
    $depth = 0;
    $current = '';
    foreach ( str_split($list) as $char ) {
        if ( '(' === $char || '[' === $char ) {
            ++$depth;
        } elseif ( ')' === $char || ']' === $char ) {
            --$depth;
        }
        if ( ',' === $char && 0 === $depth ) {
            $parts[] = trim($current);
            $current = '';
            continue;
        }
        $current .= $char;
    }
    $parts[] = trim($current);
    return $parts;
};

$reaches = static function (string $paint) use ($css, $spacer, $splitSelectorList): bool {
    if ( ! $spacer instanceof DOMElement || 0 === preg_match_all('/([^{}]+)\{[^{}]*' . preg_quote($paint, '/') . '[^{}]*\}/', $css, $rules) ) {
        return false;
    }
    foreach ( $rules[1] as $selectorList ) {
        foreach ( $splitSelectorList($selectorList) as $selector ) {
            if ( CssSelectorMatcher::matches($spacer, CssSelectorMatcher::parse($selector), true)['matches'] ) {
                return true;
            }
        }
    }
    return false;
};

$assert($reaches('#f86'), 'the tag-qualified base paint rule has a selector that matches the emitted spacer');
$assert($reaches('#0a0'), 'the tag-qualified hover paint rule has a selector that matches the emitted spacer');
$assert($reaches('#00a'), 'the tag-qualified media-query paint rule has a selector that matches the emitted spacer');
$assert(! str_contains($markup, 'data-blocks-engine-richtext-marker'), 'no rich-text marker is minted for a span that lowers to its own block');

if ( 0 < $failures ) {
    fwrite(STDERR, sprintf('empty span spacer selector carrier: %d passed, %d failed%s', $passes, $failures, PHP_EOL));
    exit(1);
}

fwrite(STDOUT, sprintf('empty span spacer selector carrier tests: %d passed%s', $passes, PHP_EOL));
