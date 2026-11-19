<?php
/**
 * The capture's device-island gate survives evidence-route retirement in the
 * form the engine leaves it: narrowed around converted controls and projected
 * onto them. Evidence routes otherwise render both islands, since carriers
 * describe each island only in the viewport that rendered it.
 */
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\Contract\CaptureEvidence;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\EvidenceRouteStylesheets;

$failures = 0;
$assert = static function (bool $condition, string $message, string $detail = '') use (&$failures): void {
    if ($condition) {
        echo "PASS: $message\n";
        return;
    }
    $failures++;
    fwrite(STDERR, "FAIL: $message\n" . ('' !== $detail ? $detail . "\n" : ''));
};

$desktop = CaptureEvidence::DESKTOP_ONLY_CLASS;
$mobile = CaptureEvidence::MOBILE_ONLY_CLASS;
$markup = '<!-- wp:group {"className":"' . $desktop . '"} --><div class="wp-block-group ' . $desktop . '"><!-- wp:buttons {"className":"blocks-engine-control-0123456789ab-0 blocks-engine-role-control"} --><div class="wp-block-buttons blocks-engine-control-0123456789ab-0 blocks-engine-role-control"><!-- wp:button --><div class="wp-block-button"><a class="wp-block-button__link wp-element-button">Cart</a></div><!-- /wp:button --></div><!-- /wp:buttons --></div><!-- /wp:group -->'
    . '<!-- wp:group {"className":"' . $mobile . '"} --><div class="wp-block-group ' . $mobile . '"><p class="cart">Cart</p></div><!-- /wp:group -->';
$page = array('source_path' => 'index.html', 'document_metadata' => array('engine_owned_styles' => true), 'canonical_block_markup' => $markup);
$retire = static fn(string $css): string => EvidenceRouteStylesheets::retire(array(array('kind' => 'css', 'source' => 'author', 'source_path' => 'gate.css', 'content' => $css, 'scopes' => array(array('kind' => 'page', 'source_path' => 'index.html')))), array($page))[0]['content'];

$plain = '@media(min-width:403px){.' . $mobile . '{display:none!important}}';
$assert($plain === $retire($plain . '.cart{width:38px}'), 'the plain gate keeps its media context', $retire($plain));

// The engine narrows the gate around converted controls and projects it onto
// them: the rule is still the gate, and every arm of it is kept.
$narrowed = '@media(max-width:402px){.' . $desktop . ':not(:where(.blocks-engine-role-control)),:where(.blocks-engine-control-0123456789ab-0):not(.be-c-0)>:where(.wp-block-button__link){display:none!important}}';
$kept = $retire($narrowed . '.' . $desktop . ' .cart{color:red}');
$assert($narrowed === $kept, 'a gate narrowed and projected onto converted controls is kept whole', $kept);

$scoped = '.' . $desktop . ' .cart{width:38px}.' . $desktop . '.cart{width:38px}.' . $desktop . ':hover-ish{width:38px}';
$assert('' === trim($retire($scoped)), 'rules merely scoped under or compounded with an island are not gates', $retire($scoped));

exit($failures > 0 ? 1 : 0);
