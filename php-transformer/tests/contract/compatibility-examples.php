<?php
declare(strict_types=1);

$root = dirname( __DIR__, 2 );
$examples = glob( $root . '/examples/compatibility/*.php' );

if ( false === $examples || array() === $examples ) {
    fwrite( STDERR, "No compatibility examples found.\n" );
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

fwrite( STDOUT, "Compatibility examples linted.\n" );
