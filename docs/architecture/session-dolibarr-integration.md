# Session ↔ Dolibarr integration architecture

Status: Architecture direction only  
Branch: architecture/session-dolibarr-integration  
Scope: Session/Slot integration with native Dolibarr objects  
Schema changes: **none**

## 1. Purpose

This document fixes the ownership and integration boundaries before any SQL or PHP integration code is written.

The objective is to let Training/TMS use Dolibarr's native planning and project capabilities without creating a second project, agenda, resource, or event system inside the module.

The implementation sequence is therefore:

**A1–A6 audit → capability map → architecture decision → schema → implementation → CI**

This PR is only the architecture decision.

## 2. Ownership model

### TMS owns

TMS is the system of record for the training domain:

- Course/session identity
- Session lifecycle and business status
- Session capacity
- Session slots as teaching/operational blocks
- Trainers assigned in the TMS domain
- Participants/enrollments
- Attendance
- Training-specific business rules
- The decision that a session is scheduled, changed, cancelled, completed, or archived

A native Dolibarr object must never become an alternative source of truth for these facts.

### Dolibarr owns

Dolibarr remains the system of record for its native objects:

- Project
- ActionComm / Agenda events
- Standard resources
- Users, contacts, third parties
- Commercial and accounting objects

TMS integrates with these objects through explicit mappings and Dolibarr object APIs.

### Integration rule

A Dolibarr object created from TMS is an **integration projection**, not a second owner of the same business state.

Changing a Project or ActionComm manually must not silently change the authoritative TMS session or slot state unless a future, explicitly documented inbound synchronization rule says so.

## 3. Lifecycle rules

### Session

The TMS session lifecycle controls integration:

1. **Draft**
   - No Project is required.
   - No Agenda event is required.

2. **Planned / scheduled**
   - A Project may be created when project-level planning is needed.
   - Scheduled slots may create Agenda events.

3. **Open / active**
   - Existing mappings remain stable.
   - Slot changes update the mapped Agenda event rather than creating duplicates.

4. **Cancelled**
   - The TMS session becomes the authoritative cancelled state.
   - Mapped future Agenda events are cancelled/closed according to the supported Dolibarr ActionComm lifecycle.
   - The Project is closed/marked inactive where appropriate; it is not deleted.

5. **Completed**
   - Historical Project and Agenda records remain.
   - No new planning events are created for the completed session.

6. **Archived**
   - Historical mappings remain traceable.
   - No new native Dolibarr objects are created.
   - Archive is not implemented as destructive deletion.

### Slot

A Slot represents a real teaching/planning block.

- One Slot maps to at most one native Agenda event.
- Updating a Slot updates its mapped event.
- A removed Slot does not cause uncontrolled creation of a replacement event.
- Historical Agenda data must remain traceable when the underlying Slot is archived or cancelled.

## 4. Entity isolation

Entity boundaries are mandatory on every integration operation.

Session entity = Project entity = ActionComm entity.

A mapping is valid only when all participating objects belong to the same Dolibarr entity.

Rules:

- Never resolve a Project or ActionComm by global ID alone.
- Every lookup must be constrained by the owning TMS entity and the corresponding Dolibarr entity context.
- A Session from entity A must never attach to a Project or Agenda event from entity B.
- Cross-entity objects are treated as invalid mappings, not as candidates for automatic reassignment.
- Entity isolation applies equally to create, update, delete/archive, repair and idempotency operations.

This is an architectural invariant, not merely a UI permission check.

## 5. Session → Project mapping

### Decision

**Session → ordinary Dolibarr Project.**

A Project is an optional native projection of a TMS Session when project-level planning, task tracking, documents or related operational work benefits from Dolibarr Project.

The Project does **not** become the TMS session.

### Mapping semantics

- One TMS Session maps to zero or one Dolibarr Project.
- The mapping is stable for the lifetime of the integration.
- Re-running the synchronization for the same Session must resolve the existing Project rather than create another Project.
- The Project reference/title may be derived from the Session, but the TMS Session remains authoritative.
- Project status changes initiated by TMS follow the lifecycle rules above.
- Manual Project edits are not an implicit inbound synchronization channel.

### Why ordinary Project

The TMS object is a scheduled delivery instance, not a Dolibarr event campaign.

A Project gives a native place for operational work without making Dolibarr's Event Organization model the second domain model.

## 6. Slot → ActionComm mapping

### Decision

**Slot → native Dolibarr ActionComm / Agenda event.**

A Slot is the TMS scheduling fact. ActionComm is its calendar projection.

### Mapping semantics

- One Slot maps to zero or one ActionComm.
- The ActionComm start/end time, subject and relevant assignment data are projections of the Slot.
- Updating a Slot updates the mapped ActionComm.
- Re-running synchronization must update the existing ActionComm, not create another event.
- A Slot must never be represented by multiple equivalent ActionComm records as a normal operating state.
- Agenda integration is optional at the module/configuration level, but when enabled it uses native ActionComm rather than a TMS calendar table.

The ActionComm event is not authoritative for Slot duration, training status, capacity, attendance or enrollment.

## 7. Idempotency rules

Every integration operation must be safe to repeat.

### Required properties

- Same Session + same mapping intent → same Project.
- Same Slot + same mapping intent → same ActionComm.
- Repeated create/sync calls must converge on the existing native object.
- A failed operation may be retried without producing duplicates.
- Entity is part of the identity boundary.
- Updates are performed against the resolved mapping, never against a newly guessed object.

### No implicit matching

The implementation must not identify an existing Project or ActionComm merely because:

- title is equal,
- date/time is equal,
- customer is equal,
- or another non-unique business attribute happens to match.

The next implementation PR must define a deterministic mapping identity in schema/code before writes are introduced.

## 8. Delete and archive rules

### General rule

**Do not hard-delete native Dolibarr objects merely because a TMS object is removed or archived.**

The integration represents historical operational facts. Destructive deletion would break traceability and may destroy native Dolibarr history.

### Session archive

- Archive the TMS Session.
- Keep the Project mapping.
- Keep historical ActionComm records.
- No new synchronization is performed.

### Session cancellation

- Preserve the Session record and its identity.
- Cancel/close future Agenda events through the native ActionComm lifecycle.
- Close/inactivate the Project where appropriate.
- Do not delete the Project.

### Slot removal

- If the slot has no externally visible history yet, the implementation may cancel/remove its future calendar projection according to native Dolibarr rules.
- Once the ActionComm represents historical activity, preserve the record and mark the TMS Slot/mapping as cancelled or archived.
- The exact native status operation belongs to the implementation PR and must be tested against Dolibarr 24.0.2.

No deletion rule may bypass entity validation or idempotency.

## 9. Event Organization vs ordinary Project

### Decision

**Use ordinary Project, not Dolibarr Event Organization, for the Session integration.**

Reasoning:

- A TMS Session is primarily an operational training delivery object.
- Capacity, enrollment, attendance, training status and teaching structure belong to TMS.
- Event Organization would introduce a second event-oriented domain model and risk duplicating registration/capacity semantics already owned by TMS.
- Project is sufficient as a native operational container where Dolibarr project functionality is useful.
- Agenda/ActionComm already provides the calendar/event projection required for individual Slots.

Therefore:

**Session = TMS domain object**  
**Project = optional Dolibarr operational projection**  
**Slot = TMS scheduling object**  
**ActionComm = native calendar projection**

No parallel Event Organization subsystem is to be built inside TMS.

## 10. Agenda and Resource integration

### Agenda

**Yes — integrate with native Dolibarr Agenda through ActionComm.**

Agenda is the correct native projection for Slot timing and user-facing calendar visibility.

TMS remains authoritative for:

- teaching duration,
- session/slot status,
- enrollment,
- attendance,
- capacity,
- trainer/business rules.

### Resources

**Yes — integrate with native Dolibarr resources where resource planning is required.**

TMS must not introduce a second room/resource registry.

The rule is:

- Native Dolibarr resources own their native identity and resource metadata.
- TMS may reference/assign them to Slots through an explicit integration mapping.
- Resource availability/conflict handling should reuse Dolibarr's native capabilities where supported.
- Resource integration must not make a resource assignment a second source of truth for the Slot itself.

Resource integration is therefore an adapter/projection concern, not a new TMS resource domain.

## 11. Explicit non-goals for this PR

This architecture PR deliberately does **not** contain:

- SQL schema changes
- New tables
- New columns
- PHP integration code
- Migration scripts
- Event Organization implementation
- A custom TMS calendar
- A custom TMS resource registry
- Direct SQL writes to Dolibarr Project or ActionComm tables
- Automatic two-way synchronization
- Destructive cleanup of native Dolibarr history

## 12. Implementation gate for the next PR

The next PR may implement SQL + PHP only after this architecture direction is accepted.

That implementation PR must prove:

1. deterministic Session → Project identity;
2. deterministic Slot → ActionComm identity;
3. entity-safe resolution;
4. idempotent create/update;
5. lifecycle transitions;
6. archive/cancel behavior;
7. native Dolibarr API/object-method usage;
8. no duplicate Project or ActionComm records under repeated execution;
9. tests covering cross-entity rejection;
10. CI against the supported Dolibarr/MySQL test environment.

No schema or implementation work belongs in this PR.

## 13. Architectural outcome

The integration boundary is intentionally narrow:

TMS Session → optional native Project

TMS Slot → native ActionComm

TMS Slot → optional native Resource

The TMS domain remains the single source of truth for training operations.

Dolibarr remains the single source of truth for its native ERP objects.

This prevents the recovery from drifting into a second event-management system inside Training.
