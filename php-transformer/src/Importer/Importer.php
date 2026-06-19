<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\Importer;

final class Importer
{
    public function productBoundary(): string
    {
        return 'Reusable importer primitives live here; product UI remains in downstream integrations.';
    }
}
