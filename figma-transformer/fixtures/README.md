# Local Figma Fixtures

This directory is for local `.fig` files used while developing the Figma transformer.

The fixture files themselves are gitignored because they can be large and may contain private or unreleased designs. Keep small synthetic fixtures in tests instead.

Real-design readiness evidence must materialize fixtures explicitly on the Lab worker, or point the fixture matrix at an out-of-tree absolute path supplied by the operator. Do not replace a missing remote fixture with a checked-in or ad-hoc local surrogate; run the matrix with `--fixture=/absolute/path/to/file.fig` or `--fixture-dir=/absolute/path/to/fixtures` once the Lab fixture assets are available.
