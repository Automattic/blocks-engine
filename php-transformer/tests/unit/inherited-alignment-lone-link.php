<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

/**
 * #6684: a link that is the only content of a `text-align` wrapper keeps its
 * source alignment on a box that renders.
 *
 * The wrapper's alignment is lifted onto the block that carries the link. A
 * synthetic paragraph carrier is `display:contents` (or `display:inline`)
 * by default, and a layout-neutral core/buttons wrapper is
 * `display:contents`, so the lifted `textAlign` / `justifyContent` used to
 * apply to no box and the link rendered at the left edge. A carrier that
 * fills an aligned line alone now keeps its block box (the source-box
 * paragraph class, which the boxless rules exclude) and the buttons wrapper
 * keeps the flex box that holds `justifyContent`.
 *
 * Links that share the line with siblings, flex items and unaligned
 * wrappers keep today's boxless carriers. The browser half
 * (inherited-alignment-lone-link-browser.mjs) measures the rendered centre.
 */

const SOURCE_BOX = 'blocks-engine-source-box-paragraph';
const NEUTRAL_BUTTONS = 'blocks-engine-layout-neutral-buttons';

$compile = static function ( string $source ): array {
	$result = ( new ArtifactCompiler() )->compile( array( 'entrypoint' => 'index.html', 'files' => array( 'index.html' => $source ) ) )->toArray();
	$css    = array_filter( $result['assets'] ?? array(), static fn ( array $asset ): bool => 'css' === ( $asset['kind'] ?? '' ) && 'editor' !== ( $asset['stylesheet_target'] ?? '' ) );
	return array(
		'markup' => (string) $result['serialized_blocks'],
		'css'    => implode( "\n", array_column( $css, 'content' ) ),
	);
};

/**
 * The nearest $name block opened before the link to $href.
 *
 * @return array{attrs: array<string, mixed>, open: string}|null
 */
$blockFor = static function ( string $markup, string $name, string $href ): ?array {
	$link = strpos( $markup, 'href="' . $href . '"' );
	if ( false === $link ) {
		return null;
	}
	preg_match_all( '/<!-- wp:' . preg_quote( $name, '/' ) . ' (\{.*?\}) -->(<[^>]+>)/', substr( $markup, 0, $link ), $matches, PREG_SET_ORDER );
	$match = end( $matches );
	return false === $match ? null : array( 'attrs' => json_decode( $match[1], true, 512, JSON_THROW_ON_ERROR ), 'open' => $match[2] );
};

$classes = static fn ( ?array $block ): array => preg_split( '/\s+/', (string) ( $block['attrs']['className'] ?? '' ) ) ?: array();

$failures = array();
$check    = static function ( bool $condition, string $message ) use ( &$failures ): void {
	if ( ! $condition ) {
		$failures[] = $message;
	}
};

$fixture = (string) file_get_contents( dirname( __DIR__ ) . '/fixtures/inherited-alignment-lone-link.html' );
[ 'markup' => $markup, 'css' => $css ] = $compile( $fixture );

// The boxless rules exist and spare the source-box carrier.
$check( str_contains( $css, ':where(p.blocks-engine-synthetic-paragraph:not(.' . SOURCE_BOX . '):has(>a)){display:contents}' ), 'the display:contents carrier rule must exclude source-box paragraphs: ' . $css );

foreach ( array(
	'https://example.com/register' => 'center',
	'https://example.com/schedule' => 'right',
) as $href => $align ) {
	$paragraph = $blockFor( $markup, 'paragraph', $href );
	$check( null !== $paragraph, "{$href} lowers to a paragraph carrier: {$markup}" );
	$check( $align === ( $paragraph['attrs']['style']['typography']['textAlign'] ?? '' ), "{$href} carries the wrapper's {$align} alignment: {$markup}" );
	$check( in_array( SOURCE_BOX, $classes( $paragraph ), true ), "{$href}: the aligned carrier keeps a block box: {$markup}" );
	$check( ! in_array( 'blocks-engine-inline-anchor-carrier', $classes( $paragraph ), true ), "{$href}: the aligned carrier is not display:inline: {$markup}" );
	$check( str_contains( (string) $paragraph['open'], 'has-text-align-' . $align ), "{$href}: the alignment class sits on the boxed paragraph: {$markup}" );
}

foreach ( array(
	'https://example.com/tickets' => 'center',
	'https://example.com/venue'   => 'right',
) as $href => $align ) {
	$buttons = $blockFor( $markup, 'buttons', $href );
	$check( null !== $buttons, "{$href} lowers to core/buttons: {$markup}" );
	$check( $align === ( $buttons['attrs']['layout']['justifyContent'] ?? '' ), "{$href} restates {$align} as justifyContent: {$markup}" );
	$check( ! in_array( NEUTRAL_BUTTONS, $classes( $buttons ), true ), "{$href}: the justifying buttons wrapper keeps its flex box: {$markup}" );
}

// Controls: today's boxless carriers stay where text-align never placed the
// link on a line of its own.
$controls = array(
	'shared line' => '<div style="text-align:center"><a class="btn" href="/a">one</a> <a class="btn" href="/b">two</a></div>',
	'flex item'   => '<div style="text-align:center;display:flex"><a class="btn" href="/a">one</a></div>',
	'unaligned'   => '<div><a class="btn" href="/a">one</a></div>',
);
foreach ( $controls as $label => $body ) {
	[ 'markup' => $controlMarkup ] = $compile( '<style>.btn{display:inline-block}</style>' . $body );
	$paragraph = $blockFor( $controlMarkup, 'paragraph', '/a' );
	$check( null !== $paragraph && ! in_array( SOURCE_BOX, $classes( $paragraph ), true ), "{$label}: the carrier stays boxless: {$controlMarkup}" );
}
[ 'markup' => $sharedButtons ] = $compile( '<style>.cta{display:inline-block;padding:15px 32px;background:#E1E4E6}</style><p>Lead</p><div style="text-align:center">Join us <a class="cta" href="/a">one</a></div>' );
$shared = $blockFor( $sharedButtons, 'buttons', '/a' );
$check( null === $shared || in_array( NEUTRAL_BUTTONS, $classes( $shared ), true ), "a button sharing its line with text keeps the neutral wrapper: {$sharedButtons}" );

if ( array() !== $failures ) {
	fwrite( STDERR, implode( "\n", $failures ) . "\n" );
	exit( 1 );
}

if ( in_array( '--fixture', $argv, true ) ) {
	echo json_encode( array( 'source' => $fixture, 'markup' => $markup, 'css' => $css ), JSON_THROW_ON_ERROR );
} else {
	echo "Lone links keep their inherited alignment on a rendered box.\n";
}
