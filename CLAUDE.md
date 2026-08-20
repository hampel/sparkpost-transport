# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## What this package is

`hampel/sparkpost-transport` — a Symfony Mailer transport for SparkPost, built on
[`hampel/sparkpost`](https://github.com/hampel/sparkpost).

Together the two replace `hampel/symfonymailer-sparkpost`. The split is the point: **this package
owns no HTTP code**. It translates a Symfony `Email` into a SparkPost `Transmission` and hands it
over. Anything about talking to the API — clients, retries, error taxonomy — belongs in the API
package, and the dependency only ever points that way.

## Commands

```bash
composer install
composer check                                  # lint, analyse, test - what CI runs
vendor/bin/phpunit --filter test_name           # one test

# the corners CI covers
composer update --with="symfony/mailer:^5.4" --with="symfony/mime:^5.4" --prefer-lowest
composer update --with="symfony/mailer:^7.0" --with="symfony/mime:^7.0"
```

**`composer.json` carries a path repository pointing at `../sparkpost`**, plus
`minimum-stability: dev`, because the API package is not on Packagist yet. Both come out, and the
`hampel/sparkpost` constraint becomes a real one, at the first release.

## Symfony 5.4 is supported on purpose

The constraint is `^5.4|^6.4|^7.0`, and 5.4 is not there by accident or generosity. **XenForo 2.3
bundles `symfony/mailer` and `symfony/mime` at v5.4.52**, and a XenForo add-on installs its own
`vendor/` alongside XenForo's own — so whatever the add-on declares, XenForo's autoloader resolves
`Symfony\Component\Mailer\*` first and 5.4 is what the code actually runs against. Declaring `^6.4`
would resolve cleanly in Composer and then break at runtime, which is the worst of both.

Consequences when writing code here:

- **Only use API present in all three.** `Email::addPart()` arrived in 6.2 and
  `Email::attachPart()` went in 7.0; `attach()` and `embed()` are in every one, which is why the
  tests use those. `TextPart::getDisposition()`, `getName()` and `DataPart::getFilename()` are all
  post-5.4 — read the prepared headers instead, as `EmailConverter` does.
- **Check a new call against 5.4 before using it**, in
  `/srv/www/xenforo23.local/src/vendor/symfony/mime`, which is the exact copy the add-on runs on.

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

### Inline images are named by filename, not content id

Symfony rewrites `cid:<name>` into `cid:<generated id>` when it renders the MIME message — and it
never renders one here, because the HTML body is sent as it stands. So an inline image must be
named the way the HTML still refers to it. `DataPart::hasContentId()` is false until Symfony
generates one, which is a tempting but wrong thing to branch on.

### Nothing escapes that is not a TransportException

A consumer of a Symfony transport catches `TransportExceptionInterface`. The API package's
exceptions, and the Mime component's, are not that — so `doSend()` wraps both. Keep it that way:
an exception that slips past is a send that fails silently in every application using this.

## SparkPostEmail and serialisation

`__serialize()` uses an **associative** payload, unlike the positional array in the package this
replaces. With a positional list the two methods have to be kept in lockstep, and a property left
out of either vanishes silently the next time a message is queued through Messenger. With keys,
adding a property is one line and an old payload still unserialises.

`SparkPostEmailTest` round-trips every field. Add a field, add it there.

## Version support

`php: >=8.3` per the Tier A policy in `/srv/www/version-support.html`, with PHPStan analysing the
whole 8.3–8.5 range in one pass. Keep `phpVersion` in `phpstan.neon` in step with the `php`
constraint in `composer.json`.

## Releases

`CHANGELOG.md` is hand-maintained, newest first, `x.y.z (YYYY-MM-DD)` heading with bullet points, and
is updated in its own commit before tagging. Simon does his own pushes and tagging.
