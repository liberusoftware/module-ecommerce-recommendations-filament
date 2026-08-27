# The panel

Why each screen is shaped the way it is, which shapes were rejected, and what this package found
missing in the domain.

## The shaping fact

Nothing in the host ever wrote an interaction, ran the generator, or displayed a recommendation. The
feature was inert end to end and its tests were its only caller — for two years. Each leg is separate
and each was invisible:

- `ProductController::show()` still injected `UserHistoryRecommender` on every product-page request
  and still imported `BrowsingHistory`, for a block that was entirely commented out.
- `browsing_histories` was written only by that commented-out block. `product_interactions` was
  written only by `ProductInteraction::track()`, whose three callers had no callers.
- `recommendations:generate` was not in the schedule, and the schedule had exactly two commands in it.

And all three faults produced one output. `getUserRecommendations` fell back to trending when a
shopper had no interactions; trending joined `product_interactions`, which was always empty; so the
fallback returned an empty collection and the caller could not tell *this shopper is new* from
*nothing has ever been recorded* from *the generator has never run*.

**A recommender's failure mode is silence, and silence is indistinguishable from an empty result.**
Every screen here exists to make one of those states nameable.

## Readiness

The first screen, and the one with no equivalent anywhere in the host. Five sections, one per thing
that can be silently absent:

1. **Where signals come from.** Each of the three seams, bound or not, and *what an unbound one
   removes*. Not a checkbox: an unbound catalogue reader does not break the module, it removes
   exactly three exclusions and fails content-similarity runs, and the sentence says so.
2. **What has been recorded.** The count, by kind, and when the most recent one *happened* —
   `occurred_at`, not `created_at`, because a signal pulled in late is not a recent one. Zero
   recorded with a source bound and zero recorded with nothing bound are two different sentences.
3. **When each strategy last generated.** All four, always, including ones that have never run. A
   strategy that has tried and failed reads differently from one that has never been asked. Curated
   reads as *recorded by hand, so there is no run* — it is not a gap, and `Strategy::isManual()` is
   what says so.
4. **What this merchant currently claims.** Standing against superseded. A store whose claims have
   all been retracted has a different problem from one that never had any, and one number cannot
   tell them apart.
5. **Whether anything is asking.** A placement is written before it is returned, so an empty
   placements table means no surface is calling. That was the host's third silence and the one
   nothing at all would have shown.

### Rejected: a single "healthy / unhealthy" badge

It would have to decide what healthy means, which is a business rule, and it would collapse five
independent facts into one — which is the fault this module exists to refuse.

## Claims

**No delete, and this is the load-bearing decision.** `AffinityEvent` raises
`AffinityHistoryIsAppendOnly` from both an `updating` and a `deleting` hook. A `DeleteAction` here
would fatal rather than refuse, and an ability that fatals is still an ability that was offered.
`Concerns\DeniesUnpublishedResourceAbilities` closes every ability the domain does not publish by
name, and `ModuleBoundaryRulesTest` fails if any create/edit/delete control class name appears
anywhere under `src/`.

**A retraction is not a deletion, and the screen says so.** Withdrawing supersedes the claim, writes
the audit row, and leaves it on the list beside the standing ones. The host's generator upserted a
score forever and retracted nothing, so nobody could say when a claim stopped being true.

**The withdrawal is hidden on a superseded claim and refused anyway.** Visibility exists so an
operator is not offered a move the claim cannot make; `Support\Apply` re-reads the claim scoped to
the panel's merchant before acting, because a control the panel hid is not a control — a run that
superseded the claim while the page sat open must refuse rather than transition a stale copy.

**Curating is a form with three fields and a bounded score.** `from_ref` and `to_ref` are free text
because they are opaque catalogue references: the module never joins the catalogue and may not share
a database with it, and `CatalogueReader::describe()` resolves refs rather than enumerating them, so
a searchable select is not buildable. The score is `numeric()->minValue(0)->maxValue(1)`, matching
the model's own guard — the host stored a frequency divided by an assumed maximum of 100 into a
`decimal(5,4)` column whose ceiling is 9.9999.

**An anchorless claim renders as "Store-wide".** Popularity is about the store rather than about a
product to sit beside, so its claims have no anchor; a blank there would read as a missing reference.

## Generation

**A failed run is a row, with the reason by name.** The host's command caught `\Exception`, wrote the
message to stdout and returned `FAILURE` — nothing logged, nothing rethrown, into a console nobody
read. `GenerationRunResource::failure()` maps `failure_reason` back through `RefusalReason` into a
sentence, and a run that failed having recorded no reason at all is named as exactly that.

**Withheld claims are counted on every run.** A claim about people that fewer than the configured
floor of distinct subjects stands behind is never asserted. Without the count, an operator sees an
empty table and infers a broken generator; with it, they see the anonymity floor working. The floor
is shown as it stood *when the run happened*, not as it stands now.

**Curated is absent from the strategy select.** `RunGeneration` refuses `Strategy::Manual` by name
and writes a failed run doing it. Offering it would be offering a move the domain cannot make.

## Placements

**Every empty answer carries its precondition.** All fourteen refusal reasons the domain publishes
have a sentence in `Support\Render`, over a `match` with no default arm, and `RenderTest` walks
`cases()` — so a fifteenth is a compile-time hole here rather than a blank an operator reads.

**Every candidate considered is on the screen.** A position and no reason, or a reason and no
position, never both and never neither. The host eager-loaded the recommended product, let the
soft-delete scope null it, `->filter()`ed the null away and `->take($limit)`ed what was left: ask for
ten, get four, and nothing anywhere says why. An unresolvable reference here is an exclusion with a
name.

**Whether the catalogue and the cart were read is stated.** An exclusion that was not applied is a
fact about the answer, not an absence of one.

**No shopper reference on the listing.** A placement is picked out of a list by its slot and its
anchor. Wave 11 shipped reviewer PII on a public listing; the record screen carries the subject
reference and the listing does not, and the boundary suite asserts it.

## What the domain does not publish, and this package needed

**A readiness query.** The domain publishes `ListAffinities` (one anchor at a time) and
`ExplainPlacement` (one placement). It publishes nothing that answers *is this merchant's recommender
running*. Every count on the readiness screen — signals held, signals by kind, most recent
occurrence, last run per strategy, last successful run per strategy, standing and superseded claim
counts, placements served, refusals by reason — is counted here, in `Support\State`, and nowhere
else. That is one file wide and the boundary suite pins the `->where(` count so it stays that way,
but it belongs in the domain: a second surface asking the same question would count them a second
time, and the two could disagree.

None of it is a rule — no threshold, no derived score, no judgement about what "running" means — so
none of it is a business rule leaking into a panel. It is still a query the domain should own.

## What is deliberately not here

**Serving a placement.** A placement is recorded before it is returned. Serving one from an operator
panel would write a row asserting that a shopper was shown something nobody was shown, and the
placement table is the audit trail for exactly that claim.

**Pruning.** `PruneExpiredSignals` takes no tenant and refuses outright when the retention window is
unset. Both make it a deployment operation. A merchant's operator pressing it would act on every
merchant's signals.

**Export and erasure.** `ExportSubjectRecord` and `ForgetSubject` walk one person across every
tenant, deliberately — a person is not a merchant's property. A merchant panel is the wrong place
for a control whose blast radius is the deployment.

**A signals list.** The counts on the readiness screen answer *are signals arriving*. A row-by-row
list of one shopper's browsing on a merchant panel is a privacy surface with no operator need behind
it, and the subject reference is the one field on these tables that identifies a person.
