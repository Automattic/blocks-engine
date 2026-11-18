# Layout Geometry Proof Contract

`layout_geometry_proof` is optional artifact input for a producer that has measured an otherwise nonsemantic wrapper removal. The normalizer ignores the complete proof if any row is stale, malformed, unsafe, or contradictory. Without accepted proof, conversion follows its existing byte- and shape-compatible path.

The envelope uses `blocks-engine/layout-geometry-proof/v1`. Each node supplies a unique `id`, normalized `source_path`, source-file SHA-256 `source_hash`, and the transformer's stable structural `selector` (`tag:nth-of-type(n)` segments joined by ` > `). Nodes carry one to eight viewport/state boxes. A reduction's target boxes must match between the source and wrapper-free simulation; the removed wrapper's own simulated box may differ because that node does not survive.

A producer may inline the envelope as the artifact's `layout_geometry_proof` key or ship it as the declared root capture report `layout-geometry-proof.json` (listed in the artifact's `reports`). An inline key wins; the report goes through the same all-or-nothing validation.

Each reduction names its wrapper and direct target nodes and sets `selectors`, `runtime`, `semantics`, and `viewports` invariants to `true`. It also supplies bounded `corrective_css.declarations` rows, which may be empty when target geometry is already exact. The transformer owns non-empty declarations by assigning a deterministic `be-layout-proof-*` carrier to the emitted target and placing the generated rule in its engine-support CSS asset. It never transfers the wrapper's author class or selector identity to another node.

Node hashes bind the proof to the source bytes the producer measured. Engine projections that run before conversion (route-state, captured-interaction projectors) may rewrite a measured document's attributes; a reduction stays bound to such a rewritten document only while its element tree, the only input to its structural selectors, is unchanged. Validation records that tree's digest as each reduction's `source_structure`.

Applied reductions are reported in `source_reports.html.layout_geometry_proof` for direct HTML transforms and `source_reports.layout_geometry_proof` for artifact compiles, including the source path, source hash, wrapper selector, target selector, and corrective CSS. This provenance lets callers correlate the exact pre-emission wrapper decision with their measured evidence.
