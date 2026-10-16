<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\Support\HtmlMarkupParser;

$count = 0;
$assert = static function (bool $condition, string $message) use (&$count): void {
    ++$count;
    if ( ! $condition ) {
        throw new RuntimeException($message);
    }
};

// Detecting a comment or CDATA section at a tag must not copy the rest of the
// document: a page with many tags would otherwise cost O(tags × bytes).
$text = str_repeat('Plain text between tags. ', 320000);
$document = '<main><p>' . $text . '</p><!-- note --><![CDATA[x]]><p>' . $text . '</p><img src="a.png"></main>';
$before = memory_get_usage();
memory_reset_peak_usage();
$nodes = HtmlMarkupParser::htmlMarkupNodes($document);
$growth = memory_get_peak_usage() - $before;
$assert($growth < intdiv(strlen($document), 4), sprintf('parsing does not copy the remaining document (peak growth %d bytes for %d bytes)', $growth, strlen($document)));

$kinds = array_map(static fn (array $node): string => $node['kind'] . ':' . ($node['name'] ?? ''), $nodes);
$assert(array( 'tag:main', 'tag:p', 'comment:', 'tag:p', 'tag:img' ) === $kinds, 'node sequence is unchanged');
$assert(' note ' === $nodes[2]['content'], 'comment content is unchanged');
$assert('a.png' === $nodes[4]['attributes']['src'], 'attributes after a CDATA section still parse');
$assert(array( array( 'kind' => 'comment', 'content' => 'x' ) ) === HtmlMarkupParser::htmlMarkupNodes('<!--x-->'), 'a comment at the end of input still parses');
$assert(array() === HtmlMarkupParser::htmlMarkupNodes('<!-'), 'a truncated comment opener is not a comment');

echo "HTML markup parser linear scan tests passed ({$count} assertions)\n";
