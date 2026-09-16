<?php
declare(strict_types=1);

/**
 * Unit coverage for marker-class engine-support CSS (#1778).
 *
 * Constructed with `new EngineSupportCss()` — no HtmlCompilation `$this`.
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Elements\ButtonLinkDispatcher;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlCompilation;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\EngineSupportCss;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\SourceBlockAttributeProjector;

$failures = 0;
$passes   = 0;

$assert = static function (bool $condition, string $message) use (&$failures, &$passes): void {
    if ( $condition ) {
        ++$passes;
        return;
    }

    ++$failures;
    fwrite(STDERR, 'FAIL: ' . $message . PHP_EOL);
};

$css = new EngineSupportCss();

$assert(array() === $css->beforeAuthorCss('', 'blocks-engine/layout-shell'), 'empty serialized blocks emit no before-author marker CSS');
$assert(array() === $css->afterAuthorEarlyCss(''), 'empty serialized blocks emit no after-author early CSS');
$assert(array() === $css->socialLinkCss(''), 'empty serialized blocks emit no social-link CSS');
$assert(array() === $css->listNavigationAfterAuthorPrefixCss('', ''), 'empty serialized blocks emit no list-navigation prefix CSS');
$assert(array() === $css->listNavigationAfterAuthorSuffixCss('', '#111'), 'empty serialized blocks emit no list-navigation suffix CSS');
$assert(array() === $css->afterAuthorLateCss(''), 'empty serialized blocks emit no after-author late CSS');

$synthetic = $css->beforeAuthorCss(SourceBlockAttributeProjector::SYNTHETIC_PARAGRAPH_CLASS, 'blocks-engine/layout-shell');
$assert(1 === count($synthetic), 'synthetic paragraph emits one before-author rule group');
$assert(str_contains($synthetic[0], ':root :where(.' . SourceBlockAttributeProjector::SYNTHETIC_PARAGRAPH_CLASS . '){margin-top:0;margin-bottom:0}'), 'synthetic paragraph margin reset is present');

$layoutShell = $css->beforeAuthorCss('<!-- wp:blocks-engine/layout-shell -->', 'blocks-engine/layout-shell');
$assert(1 === count($layoutShell), 'layout-shell comment emits one before-author rule');
$assert(':root :where(.wp-block-blocks-engine-layout-shell) .blocks-engine-layout-shell-editor-inner-blocks{display:contents}' === $layoutShell[0], 'layout-shell editor inner-blocks rule uses the passed block name');

$emptyVisual = $css->beforeAuthorCss(HtmlCompilation::EMPTY_VISUAL_GROUP_CLASS, 'blocks-engine/layout-shell');
$assert(1 === count($emptyVisual), 'empty visual group emits one before-author rule');
$assert(':where(.' . HtmlCompilation::EMPTY_VISUAL_GROUP_CLASS . '){pointer-events:none!important}' === $emptyVisual[0], 'empty visual group reuses HtmlCompilation::EMPTY_VISUAL_GROUP_CLASS');

$fragment = $css->beforeAuthorCss(ButtonLinkDispatcher::POSITIONED_FRAGMENT_LINK_CARRIER_CLASS, 'blocks-engine/layout-shell');
$assert(1 === count($fragment), 'positioned fragment link emits one before-author rule');
$assert(':where(.' . ButtonLinkDispatcher::POSITIONED_FRAGMENT_LINK_CARRIER_CLASS . '){display:contents!important}' === $fragment[0], 'positioned fragment link reuses ButtonLinkDispatcher constant');

$listNav = $css->listNavigationAfterAuthorPrefixCss('blocks-engine-list-navigation blocks-engine-native-responsive-navigation', '');
$assert(2 === count($listNav), 'list-navigation prefix emits responsive host and brand-carrier rules');
$assert('.wp-block-navigation.blocks-engine-list-navigation.blocks-engine-native-responsive-navigation{display:flex!important}' === $listNav[0], 'native-responsive list-navigation host is display:flex');

$compilation = new ReflectionClass(HtmlCompilation::class);
$method = $compilation->getMethod('materializeAuthorStylesheet');
$lines = array_slice(file((string) $compilation->getFileName()), $method->getStartLine() - 1, $method->getEndLine() - $method->getStartLine() + 1);
$body = implode('', $lines);
$assert(str_contains($body, 'new EngineSupportCss()'), 'materializeAuthorStylesheet orchestrates EngineSupportCss');
$assert(! str_contains($body, '{margin-top:0;margin-bottom:0}'), 'synthetic paragraph CSS literal left materializeAuthorStylesheet');
$assert(! str_contains($body, 'pointer-events:none!important'), 'empty visual group CSS literal left materializeAuthorStylesheet');
$assert(! str_contains($body, 'display:contents!important'), 'positioned fragment CSS literal left materializeAuthorStylesheet');
$assert(! str_contains($body, 'blocks-engine-source-social-item-spacing'), 'source-social-item-spacing CSS left materializeAuthorStylesheet');
$assert(! str_contains($body, 'blocks-engine-inline-navigation'), 'inline-navigation CSS left materializeAuthorStylesheet');

$collaborator = (string) file_get_contents((string) (new ReflectionClass(EngineSupportCss::class))->getFileName());
$assert(! str_contains($collaborator, '$this->'), 'EngineSupportCss has no instance collaborators');
$assert(! preg_match('/HtmlCompilation\s*\$/', $collaborator), 'EngineSupportCss has no HtmlCompilation $this parameter');

if ( $failures ) {
    fwrite(STDERR, $failures . " engine-support CSS test(s) failed\n");
    exit(1);
}

echo 'Engine support CSS tests: ' . $passes . " passed\n";
