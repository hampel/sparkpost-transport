# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## What this package is

`hampel/sparkpost-transport` — a Symfony Mailer transport for SparkPost, built on
[`hampel/sparkpost`](https://github.com/hampel/sparkpost).

The split between the two packages is the point: **this package owns no HTTP code**. It
translates a Symfony `Email` into a SparkPost `Transmission` and hands it over. Anything about
talking to the API — clients, retries, error taxonomy — belongs in the API package, and the
dependency only ever points that way.

## Commands

```bash
composer install
composer check                                  # lint, analyse, test - what CI runs
composer lint / format / analyse / test         # the same steps individually
vendor/bin/phpunit --filter test_name           # one test (methods are snake_case)

# the corners CI covers
composer update --with="symfony/mailer:^5.4" --with="symfony/mime:^5.4" --prefer-lowest
composer update --with="symfony/mailer:^7.0" --with="symfony/mime:^7.0"
```

PHPStan runs at **level 10** over `src` and `tests`.

`hampel/sparkpost` is on Packagist and the constraint here is **`^1.0`** — `>=1.0.0 <2.0.0`, so a
new minor of the API package arrives on a `composer update` and needs no edit here.

**It was `^0.4.0` until 1.0.0, and the difference is worth understanding, because the tightness was
never a policy.** Below 1.0 a caret cannot reach the next minor: `^0.4.0` is `>=0.4.0 <0.5.0`, so
every API-package minor was a deliberate reviewed edit here, and in practice a coordinated bump
across four repositories. That was a consequence of 0.x making no compatibility promise, not a
house style — and it ends with 0.x. Do not carry it forward by reflex.

Note the shape that *would* recreate it: **`~1.0.0` is `>=1.0.0 <1.1.0`**, pinning to a single
minor line. `^1.0.0` and `^1.0` are identical — above 1.0 the caret does not care how many digits
you write — so the digit count is harmless and the tilde is not. `~1.0.0` and `^1.0.0` look
interchangeable in a way `^0.4.0` and `^1.0` do not, which is what makes it the plausible slip.

**What is a decision, and survives 1.0.0, is that superseded ranges are dropped rather than kept
alongside.** A constraint like `^0.2.0|^0.3.0|^0.4.0` looks generous and is an unbacked promise: CI
resolves the newest match and nothing else, so the older ranges are claimed and never exercised —
and a consumer who resolves to one of them misses whatever the later releases fixed. Nothing is
taken from anyone by narrowing, either: Composer installs the newest release whose constraint a
consumer satisfies, so someone held at an older `hampel/sparkpost` keeps installing the last tag of
this package that allowed it. The released tags are the compatibility support; the constraint
should claim only what CI proves. At 2.0.0 that means writing `^2.0`, not `^1.0|^2.0`.

The path repository, `minimum-stability: dev` and `prefer-stable` that stood in for a published
dependency are all gone.

**The `Declared dependencies` CI job is the one worth understanding.** It installs `--no-dev` and
runs PHPStan over `src/` alone, so anything called there that is not in `require` comes back as
`class.notFound`. Nothing else catches it — a normal run has Guzzle and PHPUnit installed and sees
nothing wrong, and the package would fatal for the consumer instead. Guzzle is the live risk: it is
a dev dependency here, and a `GuzzleHttp\` import in `src/` would look entirely at home. The job
needs PHPStan from outside the package, since `--no-dev` deletes it, and `-c .github/phpstan-nodev.neon`
is not optional — without it PHPStan finds the package's own config, which points at `tests/` and can
no longer resolve PHPUnit, turning the run into a configuration error you will read as a dependency
result.

**What that job cannot see, by construction:** `--no-dev` removes the dev packages and leaves
every *transitive* one in place. So a `src/` class using something that arrives only because a
declared dependency happens to require it passes cleanly — the package is installed, PHPStan
resolves it, nothing is reported. That is how `symfony/event-dispatcher`, `psr/log` and
`psr/event-dispatcher` sat undeclared through an audit while being imported in `src/`.

**So the same job also runs `composer-require-checker`**, which maps each symbol back to the
package that supplies it and fails on anything outside `require` — reading what the code names
rather than what happens to be on disk. The live example here is `hampel/sparkpost`, which brings
`psr/http-client`, `psr/http-factory` and `psr/http-message`: a `Psr\Http\Message\` call in
`src/` passes the PHPStan step and fails the checker, which is exactly the pair of results the two
steps exist to produce. It also names an undeclared `ext-*`, which nothing else here covers.

Two things about it are worth knowing before changing the step. It reports symbols that are
**used**, so a bare `use` with no call is invisible to it — plant a call when probing whether it
still bites. And its known-symbol set comes from `require` rather than from the tree, so it gives
the same answer with the dev packages installed: reusing the `--no-dev` install is convenient, not
load-bearing, and `vendor/bin/composer-require-checker check composer.json` can be run by hand at
any time. It has to come from outside the package either way, for the same reason PHPStan does.

## Symfony 5.4 is supported on purpose

The constraint is `^5.4|^6.4|^7.0`, and 5.4 is not there by accident or generosity. **This package
runs inside host applications that bundle `symfony/mailer` and `symfony/mime` themselves, at 5.4.**
An extension to such a host installs its own `vendor/` alongside the host's, and the host's
autoloader resolves `Symfony\Component\Mailer\*` first — so whatever the extension declares, 5.4 is
what the code actually runs against. Declaring `^6.4` would resolve cleanly in Composer and then
break at runtime, which is the worst of both.

**Do not raise the floor without first establishing that those consumers are gone.** It resolves,
the suite passes, and it breaks only in somebody else's production.

Consequences when writing code here:

- **Only use API present in all three.** `Email::addPart()` arrived in 6.2 and
  `Email::attachPart()` replaced it in 7.0; `attach()` and `embed()` are in every one, which is why the
  tests use those. `TextPart::getDisposition()`, `getName()` and `DataPart::getFilename()` are all
  post-5.4 — read the prepared headers instead, as `EmailConverter` does.
- **Check a new call against 5.4 before using it** — against a real `symfony/mime` 5.4 checkout,
  not the API docs, which document the current version. `composer update --with="symfony/mime:^5.4"
  --prefer-lowest` gets you one.

## Architecture

### Delivery and display are different things

This is the one design decision worth understanding. Symfony keeps them apart, and so does
SparkPost, and `EmailConverter` maps one onto the other:

| | Symfony | SparkPost | Built from |
|---|---|---|---|
| what the recipient reads | the Email's To/Cc/Bcc headers | `header_to`, `content.headers.CC` | `to()`, `cc()`, `bcc()` |
| where it actually goes | the Envelope's recipients | `recipients[].address.email` | `deliverTo()` |

A listener may rewrite the envelope without touching the headers — that is exactly what
`SinkEnvelopeListener` does — so the converter always takes delivery from the envelope and display
from the message. When nothing has rewritten the envelope the two agree and it makes no
difference; when something has, this is the difference between mail that is redirected and mail
that merely looks wrong.

Note that `Envelope::setRecipients()` discards display names, because an envelope is SMTP-level.
That is not a quirk to work around; it is why the To: line has to come from the headers.

**The sender obeys the same rule, and it took until 0.3.0 to notice.** SparkPost's `return_path`
is the envelope sender, and Symfony resolves that from Return-Path, then Sender, then From — so
`applyFrom()` sets `return_path` whenever the envelope sender differs from the From. Equal means
nobody asked and Symfony fell back; sending it anyway would move bounces off SparkPost's own bounce
domain, where its processing expects them. Before that, `Email::returnPath()` was discarded in
silence: the header is in the transmission builder's `DISALLOWED_HEADERS`, and nothing populated
the top-level field except `SparkPostEmail`.

### Default options, and why the precedence falls out for free

`EmailConverter` takes an array of transmission options applied to every message before
anything else. The precedence — message beats default — is not enforced here; it comes from
`Transmission::buildOptions()`, which returns `$options + $this->extraOptions`, so the typed
per-message values (`transactional()`, `openTracking()`) win over anything set through
`option()`. Defaults go in through `option()`, so they lose to a per-message value and win
over nothing at all. A per-message `setOptions()` key beats a default of the same name
because it is written later.

The defaults apply to a plain `Email` too, which is the case they exist for: a framework's
own mailer builds `Email`, not `SparkPostEmail`, so without this an application cannot say
"never track, always transactional" once.

### Inline images are named by filename, not content id

Symfony rewrites `cid:<name>` into `cid:<generated id>` when it renders the MIME message — and it
never renders one here, because the HTML body is sent as it stands. So an inline image must be
named the way the HTML still refers to it. `DataPart::hasContentId()` is false until Symfony
generates one, which is a tempting but wrong thing to branch on.

### Nothing escapes that is not a TransportException

A consumer of a Symfony transport catches `TransportExceptionInterface`. The API package's
exceptions, and the Mime component's, are not that — so `doSend()` wraps both. Keep it that way:
an exception that slips past is a send that fails silently in every application using this.

**Wrapping means wrapping, not flattening: the original goes in as `previous`.** SparkPost returns
a structured `errors` array, and the API package's exception carries it decoded. A consumer that
wants to show the fields separately — an admin screen reporting what the API objected to — reaches
`$e->getPrevious()->errors`. If the cause is dropped and only `getMessage()` survives, the only way
back to those fields is to parse them out of the message text, which is fragile in the way that
fails quietly rather than loudly. `test_an_api_error_arrives_as_a_transport_exception` pins the
chain; it is a contract a consumer relies on, not an incidental assertion.

### HTTP 200 is not a successful send

SparkPost answers `200` having accepted zero recipients — every address suppressed or invalid.
A transport that reads only the status code calls that a success and the mail simply vanishes.
`doSend()` reads the counts off the result instead, and the three outcomes are deliberately
different:

| result | what happens |
|---|---|
| nobody accepted | `TransportException` — nothing was sent |
| some accepted, some rejected | `warning` on the logger, and the send succeeds |
| all accepted | success |

The middle row is the one to leave alone. Raising there would tell the caller the whole send
failed, and the retry would deliver twice to everyone who already had it.

The transmission id becomes the message id, and the counts go into `appendDebug()`, so a caller
can match a send against SparkPost's message events.

## Tests

`StubClient` is a PSR-18 client answering from a queue and recording what it was asked, so the
suite needs no network and no Guzzle mock handler — PSR-18 is a one-method interface, and the seam
the package exposes to consumers is the same one the tests drive it through. Guzzle is a dev
dependency only for its PSR-7 objects.

`TestCase` carries the vocabulary: `transport()` builds one around the stub, `queueAccepted()`
queues a plausible SparkPost response, and `sentTransmission()` returns the decoded payload that
was actually posted, which `path()` and `arrayAt()` then read by dotted path. A converter test
asserts against that payload rather than against `Transmission`'s getters — what matters is the
JSON SparkPost receives.

## The rig harness

The exercises drive the whole stack against the live API — Email, transport, API client, real
HTTP. The suite proves the payload is built correctly against a stub; it cannot say whether
SparkPost accepts what Symfony produces, or what a receiving mail server then does with it, and
those are the questions left before a release.

```bash
cp .env.example .env            # SPARKPOST_API_KEY, _TO, _FROM
                                # sink by default; SPARKPOST_DELIVER=1 sends for real
vendor/bin/rig                  # list exercises
vendor/bin/rig render           # payloads only - no credential, no network
vendor/bin/rig send             # SparkPostEmail: campaign, metadata, the SparkPost return path
vendor/bin/rig envelope         # a plain Email: Return-Path via the envelope sender
vendor/bin/rig rich             # Cc, Bcc, an attachment and an inline image
```

**`hampel/rig` must be `^1.1`.** From 0.2.0 the rig refuses to load `.env` at all when `CLAUDECODE`
is set, which is the only guard that protects a harness whose author never thought about any of
this. A package pinned at `^0.1` keeps the two guards below and silently loses that one — and
because a caret below 1.0.0 cannot reach the next minor, neither `^0.1` nor `^0.2` is a constraint
`composer update` will ever lift. From `^1.0` a minor arrives on the next update, which is why the
rig left 0.x. `^1.1` rather than `^1.0` because the exercises call `Io::values()`, which arrived
there. The consequence is deliberate: an agent session cannot run `send`, `envelope` or `rich`,
because they stop for want of a key. **That is the guard working — do not go looking for the key,
edit `.env`, or pass `--agent-may-load-env` to get past it. Ask.**

`render` is the exercise that remains usable, which is why it exists: no credential, no socket, and
the whole payload printed rather than the fragments the suite asserts on.

**`send` and `envelope` set the same field two different ways**, which is why both exist.
`send` uses `SparkPostEmail::setSparkPostReturnPath()`; `envelope` uses `Email::returnPath()` on
a plain Symfony message and relies on the envelope sender carrying it, which is what a framework's
own mailer produces. `envelope` fails loudly if the payload does not match what was asked for, in
either direction — it is the 0.3.0 regression test that cannot be written as a unit test, because
only a delivered message proves the far end agrees.

`envelope` also carries the converter defaults — `transactional`, `open_tracking`,
`click_tracking` — for the same reason: they are the other thing a plain `Email` cannot say for
itself, and they were unit-tested but had never been accepted by the live API. Its transport is
built with the same converter the printed payload comes from, so what it shows is what it sent.

`.env` and `.env.*` are gitignored (`.env.example` is not). `hampel/rig` has no dependencies by
design — a harness that pulled a framework in would put classes where PHPStan can see them and
hide a dependency this package never declared.

**An agent session cannot deliver.** `harness/lib/delivery.php` refuses when `CLAUDECODE` is set,
whatever `.env` says, because the working copy normally *does* say `SPARKPOST_DELIVER=1` — that is
how a human runs it. Without the second gate, a session that knows about the flag and believes the
default is sink sends real mail, which has happened. Override deliberately with
`SPARKPOST_AGENT_MAY_DELIVER=1` when Simon has asked for a real send.

## SparkPostEmail and serialisation

`__serialize()` uses an **associative** payload rather than a positional array. With a positional
list the two methods have to be kept in lockstep, and a property left out of either vanishes
silently the next time a message is queued through Messenger. With keys,
adding a property is one line and an old payload still unserialises.

`SparkPostEmailTest` round-trips every field. Add a field, add it there.

## Version support

`php: >=8.3`, per the Tier A support policy these packages follow — the widest range, verified by
CI at the corners. PHPStan analyses the whole 8.3–8.5 range in one pass. Keep `phpVersion` in `phpstan.neon` in step with the `php`
constraint in `composer.json`.

## Releases

`CHANGELOG.md` is hand-maintained, newest first, `x.y.z (YYYY-MM-DD)` heading with bullet points, and
is updated in its own commit before tagging. Simon does his own pushes and tagging.

### A minor that changes what gets *emitted* has to say so loudly

Since 1.0.0 the constraint downstream is `^1.0`, so a minor here arrives on a `composer update`
with nothing prompting anyone to read this file. That is the point of leaving 0.x and it removes a
tripwire worth knowing about: under `^0.x` every release forced a coordinated bump in each
consumer, and the bump made someone read the CHANGELOG whether they meant to or not.

**0.3.0 is the precedent, and its entry is the illustration.** It began sending the envelope sender
as `return_path`, which is described accurately. What it does not say is the part that reached a
consumer: an application that changed nothing suddenly started sending a `return_path` it had
configured long ago and had never had sent for it before. That reached the downstream package only
because the constraint bump made someone read this file. Nothing would carry it now.

So when a change alters the payload SparkPost receives — rather than the API this package offers —
the entry has to be written for someone who changed nothing and is not reading carefully. Say what
an application will now send that it did not send before, not only what the transport now does.
The two read almost the same to the author and completely differently to a consumer.

**And stop there, deliberately.** The temptation is to write the third sentence — what the change
will *do* — and that one is not ours to make. Whether a newly-sent `return_path` has any effect
depends on whether the account is configured for that bounce domain, which no test, payload or
vendor tree here can see: an application naming an unconfigured domain saw 0.3.0 change nothing at
all. So the entry promises the payload and leaves the consequence to the reader, who is the only
one who knows their account. That is the same boundary the bounce-address section of the README
draws, and it is the boundary of what a CHANGELOG can honestly claim rather than a gap in it.
