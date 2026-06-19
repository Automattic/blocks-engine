<?php
declare(strict_types=1);

$root = dirname( __DIR__, 2 );
$autoload = $root . '/vendor/autoload.php';
if ( is_readable( $autoload ) ) {
    require_once $autoload;
}

$examples = glob( $root . '/docs/consumer-prs/examples/*.php' );
$autoload = $root . '/vendor/autoload.php';

if ( false === $examples || array() === $examples ) {
    fwrite( STDERR, "No downstream migration examples found.\n" );
    exit( 1 );
}

foreach ( $examples as $example ) {
    $command = escapeshellarg( PHP_BINARY ) . ' -l ' . escapeshellarg( $example );
    exec( $command, $output, $status );

    if ( 0 !== $status ) {
        fwrite( STDERR, implode( "\n", $output ) . "\n" );
        exit( 1 );
    }
}

$smoke = static function (string $label, string $example, string $body) use ( $autoload ): void {
    $script = tempnam( sys_get_temp_dir(), 'blocks-engine-downstream-' );
    if ( false === $script ) {
        fwrite( STDERR, "Unable to create downstream migration smoke script for {$label}.\n" );
        exit( 1 );
    }

    $code = "<?php\ndeclare(strict_types=1);\nrequire " . var_export( $autoload, true ) . ";\nrequire " . var_export( $example, true ) . ";\n" . $body;
    file_put_contents( $script, $code );

    $command = escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( $script );
    exec( $command, $output, $status );
    unlink( $script );

    if ( 0 !== $status ) {
        fwrite( STDERR, "Downstream migration example smoke failed for {$label}.\n" . implode( "\n", $output ) . "\n" );
        exit( 1 );
    }
};

$assertions = <<<'PHP'
$assert = static function (bool $condition, string $message): void {
    if ( ! $condition ) {
        fwrite( STDERR, "FAIL: {$message}\n" );
        exit( 1 );
    }
};
PHP;

foreach ( $examples as $example ) {
    $name = basename( $example );

    if ( 'html-to-blocks-converter-wrapper.php' === $name ) {
        $smoke(
            $name,
            $example,
            $assertions . <<<'PHP'
$blocks = html_to_blocks_raw_handler( array( 'HTML' => '<h2>Hello</h2><p>World</p>' ) );
$assert( isset( $blocks[0]['blockName'] ) && 'core/heading' === $blocks[0]['blockName'], 'h2bc raw handler returns heading block' );
$converted = html_to_blocks_convert( '<p>Direct</p>' );
$assert( isset( $converted[0]['blockName'] ) && 'core/paragraph' === $converted[0]['blockName'], 'h2bc direct converter returns paragraph block' );
PHP
        );
        continue;
    }

    if ( 'block-format-bridge-wrapper.php' === $name ) {
        $smoke(
            $name,
            $example,
            $assertions . <<<'PHP'
$serialized = bfb_convert( '<h2>Hello</h2>', 'html', 'blocks' );
$assert( str_contains( $serialized, '<!-- wp:heading' ), 'BFB convert returns serialized heading block' );
$blocks = bfb_to_blocks( '<p>Hello</p>', 'html' );
$assert( isset( $blocks[0]['blockName'] ) && 'core/paragraph' === $blocks[0]['blockName'], 'BFB to_blocks returns block arrays' );
$assert( "# Hello\n" === bfb_normalize( "# Hello\r\n", 'markdown' ), 'BFB normalize maps markdown newlines' );
$fragment = bfb_convert_fragment( '<p>Scoped</p>', array( 'source_id' => 'fixture' ) );
$assert( true === $fragment['success'] && 'fixture' === $fragment['scope']['source_id'], 'BFB fragment wrapper returns scoped envelope' );
$capabilities = bfb_capabilities();
$assert( isset( $capabilities['formats']['html'] ), 'BFB capabilities report includes html format' );
PHP
        );
        continue;
    }

    if ( 'block-artifact-compiler-wrapper.php' === $name ) {
        $smoke(
            $name,
            $example,
            $assertions . <<<'PHP'
$compiled = bac_compile_website_artifact( array( 'generated_html' => '<main><h1>Hello</h1></main>' ) );
$assert( isset( $compiled['serialized_blocks'] ) && str_contains( $compiled['serialized_blocks'], '<!-- wp:html -->' ), 'BAC wrapper compiles generated HTML' );
$fragment = bac_compile_fragment( '<p>Fragment</p>', 'fragment', 'html' );
$assert( isset( $fragment['schema'] ), 'BAC fragment wrapper returns result envelope' );
$summary = bac_summarize_result( $compiled );
$assert( isset( $summary['diagnostic_count'] ), 'BAC summary wrapper returns counts' );
PHP
        );
        continue;
    }

    if ( 'static-site-importer-transformer-adapter.php' === $name ) {
        $smoke(
            $name,
            $example,
            $assertions . <<<'PHP'
$adapter = new Static_Site_Importer_Transformer_Adapter();
$fragment = $adapter->convert_fragment( '<main><h1>SSI Adapter</h1></main>', array( 'source_id' => 'main:index.html' ) );
$assert( 'html' === ( $fragment['from'] ?? '' ) && 'blocks' === ( $fragment['to'] ?? '' ), 'SSI adapter returns BFB-compatible conversion direction' );
$assert( 'main:index.html' === ( $fragment['scope']['source_id'] ?? '' ), 'SSI adapter preserves fragment scope' );
$compiled = $adapter->compile_website_artifact( array( 'generated_html' => '<main><h1>Hello</h1></main>' ) );
$assert( 'block-artifact-compiler/result/v1' === ( $compiled['schema'] ?? '' ), 'SSI adapter compiles website artifact to BAC schema' );
foreach ( array( 'block_markup', 'blocks', 'block_tree', 'block_types', 'components', 'documents', 'files' ) as $key ) {
    $assert( array_key_exists( $key, $compiled['wordpress_artifacts'] ?? array() ), "SSI adapter BAC mapping includes {$key}" );
}
$summary = $adapter->summarize_result( $compiled );
$assert( isset( $summary['block_count'] ), 'SSI adapter summarizes compiler result' );
$html = $adapter->blocks_to_html( (string) ( $compiled['wordpress_artifacts']['block_markup'] ?? '' ) );
$assert( '' !== trim( $html ), 'SSI adapter renders blocks to HTML' );
PHP
        );
    }
}

fwrite( STDOUT, "Downstream migration examples linted and smoke-called.\n" );
