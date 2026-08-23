# Layout Geometry Proof Contract

`layout_geometry_proof` is optional artifact input for a producer that has measured an otherwise nonsemantic wrapper removal. The normalizer ignores the complete proof if any row is stale, malformed, unsafe, or contradictory. Without accepted proof, conversion follows its existing byte- and shape-compatible path.

The envelope uses `blocks-engine/php-transformer/layout-geometry-proof/v1`. Each node supplies a unique `id`, normalized `source_path`, source-file SHA-256 `source_hash`, and the transformer's stable structural `selector` (`tag:nth-of-type(n)` segments joined by ` > `). Nodes carry one to eight viewport/state boxes with matching source and wrapper-free simulated geometry.

Each reduction names its wrapper and direct target nodes and sets `selectors`, `runtime`, `semantics`, and `viewports` invariants to `true`. It also supplies bounded `corrective_css.declarations` rows. The transformer owns those declarations by assigning a deterministic `be-layout-proof-*` carrier to the emitted target and placing the generated rule in its engine-support CSS asset. It never transfers the wrapper's author class or selector identity to another node.

Applied reductions are reported in `source_reports.html.layout_geometry_proof` for direct HTML transforms and `source_reports.layout_geometry_proof` for artifact compiles, including the source path, source hash, wrapper selector, target selector, and corrective CSS. This provenance lets callers correlate the exact pre-emission wrapper decision with their measured evidence.
