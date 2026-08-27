# Changelog

## 0.1.0

First release. A Filament 5 operator surface over
`liberusoftware/ecommerce-recommendations` `0.1.0`.

There was nothing to extract. The host had no Filament resource, no policy, no widget and no Blade
partial mentioning a recommendation — the only grep hits outside its service layer were two
commented-out helper texts. This package is shaped by that absence rather than by an existing screen.

### The screens

- **Readiness.** Which seams are bound and what each unbound one removes; signals held, by kind, and
  when the most recent one happened; every strategy with when it last succeeded and what that run
  asserted, retracted and withheld; standing claims against superseded ones; whether any surface is
  asking at all. Pull signals in from the bound source.
- **Claims.** Every affinity, standing and superseded, with the evidence and distinct-shopper count
  behind each score and the append-only history of every move. Curate one, withdraw one.
- **Generation.** Every run, its counts in and out, and — where it failed — the reason by name.
  Run one.
- **Placements.** What a surface asked for, what it was given, and every candidate considered: shown
  with its position, or removed with the exclusion that removed it.

### Decisions

- **The three silences are three sentences.** Nothing bound, a bound source that offered nothing,
  and signals with no successful run are separate lines on the readiness screen and separate
  refusals on a placement. The host had one output for all of them.
- **No create, no edit, no delete, anywhere.** An affinity's history is append-only in the domain and
  raises from a `deleting` hook, so a delete here would fatal rather than refuse. A claim is
  retracted by being withdrawn, which supersedes it and writes the row.
- **A superseded claim stays on the list.** A retraction is not a deletion, and a store whose claims
  all fell away has a different problem from one that never had any.
- **Every option comes from a domain enum.** Strategy, affinity state, run state, refusal — every
  select and filter walks `cases()`, and a curated claim's score is bounded at nought and one on the
  form as well as on the model.
- **Curated is absent from the generation select**, because `RunGeneration` refuses it by name.
- **Every write re-reads the claim scoped to the panel's merchant**, so a control the screen hid is
  not the guard.
- **Another merchant's record and one that does not exist raise the same exception**, on all three
  resources.
- **Withheld counts are on every run**, so an operator sees the anonymity floor working rather than
  inferring it from an empty table.
- **No shopper reference on any listing.**

### Deliberately not shipped

- **No serving a placement from the panel.** A placement is recorded before it is returned; serving
  one here would write a row saying a shopper was shown something nobody was shown.
- **No prune, export or erasure screen.** `PruneExpiredSignals` takes no tenant and `ForgetSubject`
  walks one person across every tenant. Both are deployment operations, and a merchant panel is the
  wrong blast radius.
- **No signals list.** The readiness counts answer whether signals are arriving; a row-by-row list of
  one shopper's browsing is a privacy surface with no operator need behind it.
- **No health badge.** It would have to decide what healthy means, and it would collapse five
  independent facts into one — which is the fault this module exists to refuse.

### Found in the domain package

- **No readiness query.** `ListAffinities` answers one anchor and `ExplainPlacement` answers one
  placement; nothing answers whether a merchant's recommender is running. Every count on the
  readiness screen is made in `Support\State` here. None of it is a rule, and all of it belongs in
  the domain — see `docs/panel.md`.
