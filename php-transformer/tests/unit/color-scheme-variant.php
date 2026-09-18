<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\ColorSchemeVariant;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\CssCascade;

$failures = 0;
$passes = 0;
$assert = static function (bool $condition, string $message, string $detail = '') use (&$failures, &$passes): void {
    if ( $condition ) {
        ++$passes;
        return;
    }
    ++$failures;
    fwrite(STDERR, 'FAIL: ' . $message . ( '' !== $detail ? ' - ' . $detail : '' ) . PHP_EOL);
};

$lifted = ColorSchemeVariant::liftSelector('.dark\:text-gray-100:where([data-mode=dark],[data-mode=dark] *)');
$assert('dark' === $lifted['scheme'] && '.dark\:text-gray-100' === $lifted['prelude'], 'an attribute color-scheme gate lifts off the remaining selector');

$gradient = '.dark\:bg-\[radial-gradient\(circle_at_top\,\#202124_0\%\,\#151619_48\%\,\#101113_100\%\)\]:where([data-mode=dark],[data-mode=dark] *)';
$liftedGradient = ColorSchemeVariant::liftSelector($gradient);
$assert(
    'dark' === $liftedGradient['scheme']
        && '.dark\:bg-\[radial-gradient\(circle_at_top\,\#202124_0\%\,\#151619_48\%\,\#101113_100\%\)\]' === $liftedGradient['prelude'],
    'an arbitrary-value gradient class keeps its escaped commas while the gate lifts'
);

$liftedClass = ColorSchemeVariant::liftSelector('.copy:where(.dark,.dark *)');
$assert('dark' === $liftedClass['scheme'] && '.copy' === $liftedClass['prelude'], 'a class color-scheme gate lifts the same way');

$liftedHover = ColorSchemeVariant::liftSelector('.copy:hover:where([data-mode=dark],[data-mode=dark] *)');
$assert('dark' === $liftedHover['scheme'] && '.copy:hover' === $liftedHover['prelude'], 'a trailing color-scheme gate leaves a pseudo-state in place');

$untouched = ColorSchemeVariant::liftSelector('.text-gray-900');
$assert(null === $untouched['scheme'] && '.text-gray-900' === $untouched['prelude'], 'an ungated selector is unchanged');

$spacing = ColorSchemeVariant::liftSelector(':where(.space-y-8>:not(:last-child))');
$assert(null === $spacing['scheme'], 'an unrelated :where() wrapper is not a color-scheme gate');

$assert(
    ColorSchemeVariant::cssContainsClassSelector('.dark\:text-gray-100{color:red}', 'dark:text-gray-100'),
    'a CSS-escaped class selector still names the source class'
);
$assert(
    ! ColorSchemeVariant::cssContainsClassSelector('.text-gray-900{color:red}', 'dark:text-gray-100'),
    'an unrelated class is not treated as present'
);

$assert(
    ColorSchemeVariant::wrap('.copy{color:red}', 'dark') === '@media (prefers-color-scheme: dark){.copy{color:red}}',
    'a lifted scheme wraps the projected rule'
);
$assert(
    '.copy{color:red}' === ColorSchemeVariant::wrap('.copy{color:red}', 'dark', array( '@media (prefers-color-scheme: dark)' )),
    'an existing prefers-color-scheme ancestor is not wrapped again'
);

$assert(CssCascade::mediaConditionApplies('(prefers-color-scheme: light)', 1440.0), 'the reference cascade is the light color scheme');
$assert(! CssCascade::mediaConditionApplies('(prefers-color-scheme: dark)', 1440.0), 'a dark color-scheme query does not apply at the reference cascade');

if ( 0 < $failures ) {
    fwrite(STDERR, "color-scheme variant: {$failures} failed, {$passes} passed\n");
    exit(1);
}
fwrite(STDOUT, "color-scheme variant passed\n");
