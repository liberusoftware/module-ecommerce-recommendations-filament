# Runbook

## The panel says no signal source is bound

Expected on a fresh install: nothing is bound by default, and the module is inert rather than wrong.
Bind `recommendations.seams.signal_source` to a class implementing `Contracts\SignalSource`, or have
the host call `Actions\RecordSignal` directly at the moment it already knows an interaction happened.

Both are legitimate. The seam is the pull half, for a host that already observes interactions
somewhere — analytics, for instance; the direct call is the push half. Analytics owns the observation
and this module owns the inference, and wiring the two together is the host's decision, not this
module's.

## The panel says a source is bound and has offered nothing

The seam answered with an empty iterable for this merchant and this window. Check that the adapter
scopes on the tenant id the panel resolves — in this host, the `store_id`, not the `team_id`.

## A strategy has never succeeded and the last attempt failed

Open the run. The reason is on the row, by name.

| Reason | What to do |
|---|---|
| Nothing is bound to resolve a product reference | Content similarity needs `recommendations.seams.catalogue`. The other three strategies do not. |
| A curated claim is recorded by hand | Something asked for a `manual` run. Nothing generates curated claims; record them on the Claims screen. |

A run that failed and recorded no reason at all is a bug in the domain package rather than an
operational fault: report it rather than rerunning.

## A run succeeded and asserted nothing

Look at **withheld** on the run row. A claim about people that fewer than the anonymity floor of
distinct subjects stands behind is never asserted, and a small store crosses that floor rarely. If
withheld is large and asserted is zero, the store's evidence is thinner than
`recommendations.k_anonymity.minimum_subjects` allows.

Lowering the floor produces more claims from thinner evidence and weakens the argument in the domain
package's ADR that an affinity is anonymous rather than a profile. It is an operator's decision, it
is recorded on every run, and a host that sets it to 1 has taken that argument away.

If withheld is zero and asserted is zero, there was nothing to read: check signals.

## Standing claims fell to zero

A generation run retracts what it did not reassert. If the newest successful run for a strategy read
a window with nothing in it, everything that strategy claimed is now superseded — correctly, and with
an audit row on each. Check the run's **considered** count and its window.

The claims are not gone. They are on the Claims screen marked superseded, with their evidence and
their whole history.

## Placements are being served and every one is empty

The refusal on each says which. The five a placement can carry:

| Refusal | Meaning |
|---|---|
| Nothing is bound to offer interactions | No source and no direct calls; nothing has ever been recorded |
| A signal source is bound and has offered nothing | Something is bound and the table is still empty |
| Signals are recorded and no generation run has ever succeeded | Schedule generation |
| Generation has succeeded and produced no claim about this anchor | An answer, not a failure |
| Every candidate was removed by an exclusion | Open the placement; the tally names which exclusion |

The host had one output for the first four of these. Splitting them is the module's whole purpose.

## A placement returned fewer than were asked for

Open it. Every candidate considered is there: shown with its position, or removed with the exclusion
that removed it. An unresolvable reference means the catalogue reader did not know a product this
merchant has a claim about — either the ref is stale, or the reader is scoped to the wrong tenant.

## Withdrawing a claim says nothing changed

It was already superseded — most likely by a generation run between the page being drawn and the
button being pressed. The panel re-reads the claim before acting, so nothing was written twice.

## A panel raises "could not resolve a tenant"

The plugin has no `tenantUsing` and the panel has no Filament tenant. There is no fallback: a query
with a null tenant compiles to `where tenant_id is null` and returns exactly the orphan rows a scope
exists to hide. Set the resolver in the panel provider.

## Nothing is asking for a placement at all

The readiness screen says *nothing has ever asked for a recommendation*. Nothing is wrong with this
package — no surface is calling `Actions\ServePlacement`. That was the host's state for two years,
and this line is the only place it becomes visible.
