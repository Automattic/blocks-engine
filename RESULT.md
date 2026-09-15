# Modern Nest Navigation Result

Date: 2026-09-14
Candidate: `790d5b4c0766`
Runtime receipt: Studio import `b2abc6a8a9a616cefad06e0c9bf22725299e444b30765b6af70b54708cf93831` at `http://localhost:8939/`

Passed runtime checks at 390px: one visible Core trigger at `x=330,y=14`,
`44x44`; opening works; Escape closes the overlay. Passed desktop check at
1440px: no visible trigger and five visible navigation links. Import completed
with zero HTML fallbacks.

Not accepted: the opened overlay contains `Inicio`, `Propiedades`, `Servicios`,
`Nosotros`, and `Contacto`, but not source CTA `Publicar mi propiedad`. The
source behavior is not represented in the immutable static capture as an
opened-menu DOM state or explicit interaction contract. No generic transformer
rule may treat an adjacent internal button as menu content, because the same
structure also includes unrelated header actions.

No draft PR was published because the six-link source-content acceptance
criterion and editor validation remain incomplete. Remaining visual gap: the
mobile overlay lacks the source CTA.
