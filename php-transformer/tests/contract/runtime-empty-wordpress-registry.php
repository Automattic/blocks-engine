<?php
declare(strict_types=1);

final class WP_Block_Type_Registry
{
    public static function get_instance(): self
    {
        return new self();
    }

    /** @return array<string, object> */
    public function get_all_registered(): array
    {
        return array();
    }
}

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\WordPress\Runtime;

$runtime = new Runtime();
if ( array() !== $runtime->availableCoreBlockNames() ) {
    throw new RuntimeException('An available but empty live registry must remain authoritative for runtime availability.');
}
if ( array() !== $runtime->runtimeRegisteredCoreBlockNames() ) {
    throw new RuntimeException('An empty live registry must be reported as an empty registered inventory.');
}
if ( 115 !== count($runtime->bundledCoreBlockNames()) ) {
    throw new RuntimeException('An empty live registry must not erase bundled snapshot knowledge.');
}

fwrite(STDOUT, "WordPress empty-registry contract passed.\n");
