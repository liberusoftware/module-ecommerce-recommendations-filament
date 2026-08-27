# ecommerce-recommendations-filament

The operator's view of a recommender. A Filament panel over
[`liberusoftware/ecommerce-recommendations`](https://github.com/liberusoftware/module-ecommerce-recommendations):
whether a signal source is bound, when each strategy last generated and what that run asserted and
withheld, which claims stand and which were superseded, and why any one placement came out the way
it did.

It is a one-to-one adapter. It contains no business rules: every decision is a published domain
action, query or policy, and every state, strategy, refusal and exclusion on every screen comes from
a domain backed enum.

## The fault it is shaped around

**A recommender ran in the host for two years while nothing wrote a signal, nothing scheduled the
generator and nothing displayed a result — and no screen anywhere would have shown any of the
three.** The product page's call to the recommender was commented out; two of the three signal tables
had no writer; `recommendations:generate` was never in the schedule. Worse, all three produce the
same output: `getUserRecommendations` fell through to trending, trending joined a table nothing ever
wrote, and the caller could not tell "this shopper is new" from "nothing has ever been recorded"
from "the generator has never run".

A recommender's failure mode is silence, and silence is indistinguishable from an empty result. So
this panel's first screen is **Readiness**, and it names each silence separately. Every other screen
carries the same rule: no figure is a zero it did not measure, and every empty answer says which
precondition produced it.

## What it publishes

| | |
|---|---|
| `RecommendationsPlugin` | The entry point. The host attaches it to the panels it means to. |
| **Readiness** | Which seams are bound and what each unbound one removes; how many signals are held and of what kind; when each strategy last succeeded and what it asserted, retracted and withheld; standing claims against superseded ones; whether anything is asking at all. Pull signals in from the bound source. |
| **Claims** | Every affinity this merchant makes or used to make, with the evidence and the distinct-shopper count behind each score, and the append-only history of every move it has made. Curate one, withdraw one. |
| **Generation** | Every run, what it read and what came of it, and — where it failed — why, by name. Run one. |
| **Placements** | What a surface asked for, what it was given, and every candidate considered: shown with its position, or removed with the exclusion that removed it. |

## What it does not do

- **No create, no edit, no delete, anywhere.** Every row on these screens is evidence of something
  that happened. An affinity's history is append-only in the domain and raises from a `deleting`
  hook, so a delete offered here would fatal rather than refuse — and an ability that fatals is
  still an ability that was offered. A claim is retracted by being **withdrawn**, which supersedes it
  and writes the row saying when it stopped being true.
- **No free text where the domain has an enum.** The host's rule `type` was a free string. Every
  select and filter here walks `cases()`, and a curated claim's score is bounded at nought and one on
  the form as well as on the model.
- **No curated strategy in the generation select.** `RunGeneration` refuses it by name, and a control
  that writes a failed run offers a move the domain cannot make.
- **No serving a placement from the panel.** A placement is what a surface was given and it is
  recorded before it is returned; serving one here would write a row saying a shopper was shown
  something nobody was shown.
- **No prune and no erasure screen.** `PruneExpiredSignals` takes no tenant and `ForgetSubject` walks
  one person across every merchant on the deployment. A merchant panel is the wrong place for either
  — see [`docs/panel.md`](docs/panel.md).
- **No shopper reference on any listing.**

## Installing

```bash
composer require liberusoftware/ecommerce-recommendations-filament
```

Nothing boots on install: the module manager registers the provider when the module is named in
`MODULES_ENABLED`. Attaching the panel is one call — see [`docs/adoption.md`](docs/adoption.md).

Why every screen is shaped the way it is, including the shapes that were rejected and the gaps this
package found in the domain, is in [`docs/panel.md`](docs/panel.md). What breaks and what to do about
it is in [`docs/runbook.md`](docs/runbook.md).
