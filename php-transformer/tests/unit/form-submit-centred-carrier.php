<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

/**
 * #5851: a centred submit carrier must keep its source position.
 *
 * The source centres its submit button with a relative shift
 * (`position:relative;left:50%;transform:translate(-50%)`). A form provider
 * (Static Site Importer's Jetpack form seeder) saves its own core/button
 * around the same control marker, so the emitted `.wp-block-button` wrapper
 * keeps a box, never carries the flattened
 * `.blocks-engine-layout-child-button` class, and re-applies the captured
 * source presentation itself through its provider overlay CSS.
 *
 * Since #5836 the wrapper-owned geometry (`position`, `left`) reaches the
 * link only through the flattened carrier arm. The placement prelude must
 * route `transform` the same way: on a plain arm the link applies the shift
 * a second time under the provider's wrapper box and the centred button
 * lands half a button width left of its source position.
 */

const CARRIER = '.blocks-engine-layout-child-button';
const CARRIER_CLASS = 'blocks-engine-layout-child-button';

$centredFormSource = <<<'HTML'
<style>*{margin:0;font-family:Georgia,serif}body{padding:20px}input{display:block}.cta{font-family:inherit;display:inline-block;padding:8px 16px;background:#222;color:#fff;border-radius:20px}.box{font-family:"Courier New",monospace;padding:12px;width:300px}.center{position:relative;left:50%;transform:translate(-50%)}</style><form class="box" action="https://example.com/s" method="post"><input type="email" name="email"><button class="cta center" type="submit">Submit</button></form>
HTML;

$compile = static function ( string $source ): array {
	$result = ( new ArtifactCompiler() )->compile( array( 'entrypoint' => 'index.html', 'files' => array( 'index.html' => $source ) ) )->toArray();
	return array(
		'result' => $result,
		'css'    => implode( "\n", array_column( array_filter( $result['assets'], static fn ( array $asset ): bool => 'css' === ( $asset['kind'] ?? '' ) ), 'content' ) ),
	);
};

$placementRules = static function ( string $css ): array {
	// Every projected rule that delivers a transform to the core/button link.
	preg_match_all( '/[^{}]*wp-block-button__link[^{}]*\{[^{}]*transform[^{}]*\}/', $css, $rules );
	return $rules[0];
};

[ 'result' => $form, 'css' => $formCss ] = $compile( $centredFormSource );

// The forms manifest must hand the provider the submit control's carrier
// classes: they are what the provider stamps on its own saved wrappers.
$carrierClasses = '';
foreach ( $form['source_reports']['wordpress_site_plan']['runtime_declarations'] as $row ) {
	if ( 'forms' !== ( $row['type'] ?? null ) ) {
		continue;
	}
	foreach ( $row['payload']['entities'] as $entity ) {
		foreach ( $entity['controls'] as $control ) {
			if ( 'submit' === ( $control['type'] ?? '' ) ) {
				$carrierClasses = implode( ' ', $control['control_carrier_classes'] ?? array() );
			}
		}
	}
}
if ( ! str_contains( $carrierClasses, 'blocks-engine-role-control' ) || ! str_contains( $carrierClasses, 'blocks-engine-control-' ) ) {
	throw new RuntimeException( 'The submit control must expose its carrier classes to the form provider.' );
}

// Provider form: the flattened carrier class must not appear in the markup a
// provider saves (it is engine output only), so every placement arm needs it.
$formRules = $placementRules( $formCss );
if ( array() === $formRules ) {
	throw new RuntimeException( 'The centred submit placement must be projected onto the button structure: ' . $formCss );
}
$unrouted = array_values( array_filter(
	$formRules,
	static fn ( string $rule ): bool => ! str_contains( $rule, CARRIER )
) );
if ( array() !== $unrouted ) {
	throw new RuntimeException( 'Placement reaches a core/button link only through a flattened save wrapper: ' . implode( "\n", $unrouted ) );
}

// The engine's own lowering (no provider wrapper kept) keeps the flattened
// carrier, so the centring placement and geometry must still be delivered.
$standaloneSource = <<<'HTML'
<style>*{margin:0}body{padding:20px}.cta{display:inline-block;padding:8px 16px;background:#222;color:#fff;border-radius:20px}.center{position:relative;left:50%;transform:translate(-50%)}</style><main style="width:500px"><button class="cta center" type="button">Submit</button></main>
HTML;

[ 'result' => $standalone, 'css' => $standaloneCss ] = $compile( $standaloneSource );
if ( ! preg_match( '/class="wp-block-button [^"]*' . CARRIER_CLASS . '/', $standalone['serialized_blocks'] ) || str_contains( $standalone['serialized_blocks'], '<!-- wp:html' ) ) {
	throw new RuntimeException( 'A centred native button outside a form must lower to a flattened core/button: ' . $standalone['serialized_blocks'] );
}
$standaloneRules = $placementRules( $standaloneCss );
if ( array() === array_filter(
	$standaloneRules,
	static fn ( string $rule ): bool => str_contains( $rule, CARRIER )
) ) {
	throw new RuntimeException( 'The flattened carrier must still receive the centring placement: ' . $standaloneCss );
}

if ( in_array( '--fixture', $argv, true ) ) {
	// The provider emulation: markup shaped like the provider's saved form
	// (core/buttons around the control marker, no flattened carrier class)
	// plus the overlay re-applying the captured source presentation to the
	// saved wrapper, the way Static Site Importer's form seeder does.
	$markup = '<form class="box be-provider-form" action="#"><input type="email" name="email"><div class="wp-block-buttons ' . $carrierClasses . '"><div class="wp-block-button ' . $carrierClasses . ' be-provider-submit-presentation"><button type="submit" class="wp-block-button__link wp-element-button">Submit</button></div></div></form>';
	$overlay = '.be-provider-form.be-provider-form .be-provider-submit-presentation{max-width:none;padding:0;border:0;background:transparent;line-height:inherit;display:inline-block;left:50%;margin:0;position:relative;transform:translate(-50%)}';
	echo json_encode(
		array(
			'form'       => array( 'source' => $centredFormSource, 'markup' => $markup, 'css' => $formCss . "\n" . $overlay ),
			'standalone' => array( 'source' => $standaloneSource, 'markup' => $standalone['serialized_blocks'], 'css' => $standaloneCss ),
		),
		JSON_THROW_ON_ERROR
	);
} else {
	echo "Centred submit carriers keep their source position.\n";
}
