<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\ShellLandmarkPolicy;

$failures = 0;
$passes   = 0;

$assert = static function (bool $condition, string $message, string $detail = '') use (&$failures, &$passes): void {
    if ( $condition ) {
        ++$passes;
        return;
    }

    ++$failures;
    fwrite(STDERR, 'FAIL: ' . $message . ('' !== $detail ? ' - ' . $detail : '') . PHP_EOL);
};

$assert(ShellLandmarkPolicy::isGlobalShellLandmarkTag('header'), 'header is global shell');
$assert(ShellLandmarkPolicy::isGlobalShellLandmarkTag('footer'), 'footer is global shell');
$assert(ShellLandmarkPolicy::isGlobalShellLandmarkTag('nav'), 'nav is global shell');
$assert(! ShellLandmarkPolicy::isGlobalShellLandmarkTag('main'), 'main remains page/content-local, not reusable shell');
$assert(! ShellLandmarkPolicy::isGlobalShellLandmarkTag('article'), 'article remains content-local');

$assert('footer' === ShellLandmarkPolicy::landmarkKind('footer'), 'plain footer is a footer landmark');
$assert('' === ShellLandmarkPolicy::landmarkKind('footer', '', true), 'blockquote/figure footer is content-local citation, not page footer');
$assert('header' === ShellLandmarkPolicy::landmarkKind('div', 'banner'), 'role banner maps to header landmark');
$assert('nav' === ShellLandmarkPolicy::landmarkKind('div', 'navigation'), 'role navigation maps to nav landmark');
$assert('main' === ShellLandmarkPolicy::landmarkKind('main'), 'main maps to main landmark');

$assert(ShellLandmarkPolicy::isSemanticGroupTag('main'), 'main can remain a semantic core/group tag');
$assert(ShellLandmarkPolicy::isWrapperPreservingTag('main'), 'main wrapper can preserve source style/structure');
$assert(ShellLandmarkPolicy::isInlineContentWrapperTag('footer'), 'footer can still be content-local phrasing wrapper');

$assert('header' === ShellLandmarkPolicy::templatePartArea('parts/header.html', ''), 'header template part area comes from path');
$assert('footer' === ShellLandmarkPolicy::templatePartArea('parts/site-shell.html', 'template-part footer'), 'footer template part area comes from role');
$assert('uncategorized' === ShellLandmarkPolicy::templatePartArea('parts/sidebar.html', ''), 'sidebar template parts use WordPress core uncategorized area');
$assert('aside' === ShellLandmarkPolicy::templatePartTagName('parts/sidebar.html', ''), 'sidebar template parts preserve an aside landmark');
$assert('div' === ShellLandmarkPolicy::templatePartTagName('parts/navigation.html', ''), 'navigation overlays use the core-supported div wrapper');
$assert('header' === ShellLandmarkPolicy::templatePartAreaTagName('header') && 'footer' === ShellLandmarkPolicy::templatePartAreaTagName('footer') && 'div' === ShellLandmarkPolicy::templatePartAreaTagName('uncategorized'), 'core template part area tags are centralized');
$assert('uncategorized' === ShellLandmarkPolicy::templatePartArea('pages/main.html', ''), 'main-named content is not promoted to a template part area');

if ( $failures > 0 ) {
    fwrite(STDERR, PHP_EOL . "ShellLandmarkPolicy unit tests: {$passes} passed, {$failures} FAILED" . PHP_EOL);
    exit(1);
}

fwrite(STDOUT, "ShellLandmarkPolicy unit tests: {$passes} passed" . PHP_EOL);
