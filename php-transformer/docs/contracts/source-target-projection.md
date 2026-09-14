# Source-Target Projection

`SourceTargetProjectionState` records a bounded stable source selector, native-target selector, and CSS declarations for source presentation that a core block cannot serialize. The compiler emits those rules after author CSS and exposes the retained correspondence rows as `source_reports.html.source_target_projections`.

Multiple source elements may require the same native-target rule. Their correspondence rows remain independently inspectable while the emitted CSS is deduplicated by exact target selector and declarations.

The source selector is correspondence identity, not an emitted block attribute. Source-local residue therefore remains scoped CSS and does not fragment shared template-part markup. Browser evidence remains the optional, hash-bound `layout_geometry_proof` contract; this projection seam neither accepts nor infers browser snapshots.
