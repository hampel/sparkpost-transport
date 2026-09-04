# SparkPost transport for Symfony Mailer

[![Tests](https://github.com/hampel/sparkpost-transport/actions/workflows/tests.yml/badge.svg)](https://github.com/hampel/sparkpost-transport/actions/workflows/tests.yml)
[![Latest Version on Packagist](https://img.shields.io/packagist/v/hampel/sparkpost-transport.svg?style=flat-square)](https://packagist.org/packages/hampel/sparkpost-transport)
[![Total Downloads](https://img.shields.io/packagist/dt/hampel/sparkpost-transport.svg?style=flat-square)](https://packagist.org/packages/hampel/sparkpost-transport)
[![Open Issues](https://img.shields.io/github/issues-raw/hampel/sparkpost-transport.svg?style=flat-square)](https://github.com/hampel/sparkpost-transport/issues)
[![License](https://img.shields.io/packagist/l/hampel/sparkpost-transport.svg?style=flat-square)](https://packagist.org/packages/hampel/sparkpost-transport)

Sends Symfony Mailer messages through the SparkPost transmissions API, using
[`hampel/sparkpost`](https://github.com/hampel/sparkpost) to do the talking.

By [Simon Hampel](mailto:simon@hampelgroup.com)

## Installation

```bash
composer require hampel/sparkpost-transport
```

You also need a PSR-18 HTTP client and a PSR-17 factory, because the API client takes
whatever your application already has. Guzzle 7 provides both:

```bash
composer require guzzlehttp/guzzle
```

## Usage

```php
use GuzzleHttp\Client;
use GuzzleHttp\Psr7\HttpFactory;
use Hampel\SparkPost\Config;
use Hampel\SparkPost\SparkPost;
use Hampel\SparkPost\Transport\SparkPostTransport;
use Symfony\Component\Mailer\Mailer;
use Symfony\Component\Mime\Email;

$factory   = new HttpFactory();
$sparkpost = new SparkPost(new Config('MY-API-KEY'), new Client(), $factory, $factory);

$transport = new SparkPostTransport($sparkpost);
$mailer    = new Mailer($transport);

$mailer->send(
    (new Email())
        ->from('webmaster@example.com')
        ->to('alice@example.com')
        ->subject('Hello')
        ->text('Hello from SparkPost.')
);
```

## HTTP 200 is not a successful send

SparkPost answers `200` having accepted zero recipients — a suppressed address, an invalid
one. A transport that reads only the status code reports that as a successful send. Here it
is a failure:

- **Nobody accepted** → `TransportException`. Nothing was sent, and you will hear about it.
- **Some accepted, some rejected** → a `warning` on the logger, and the send succeeds.
  Raising here would tell the caller the whole thing failed, and a retry would deliver
  twice to everyone who already had it.

Everything that leaves the transport implements Symfony's `TransportExceptionInterface`,
including API errors and connection failures, so the one `catch` a Symfony consumer
already writes is enough.

**The original exception is kept as `previous`, not flattened into a message.** SparkPost
returns a structured `errors` array and `hampel/sparkpost` carries it decoded, so an
application that wants to show what the API objected to — an admin screen, a diagnostics
page — reads the fields rather than parsing them back out of a string:

```php
try {
    $transport->send($email);
} catch (TransportExceptionInterface $e) {
    $cause = $e->getPrevious();      // the hampel/sparkpost exception, when the API refused

    if ($cause instanceof \Hampel\SparkPost\Exception\ApiException) {
        foreach ($cause->errors as $error) {
            $error['message'] ?? '';     // SparkPost's own fields, decoded
        }
    }
}
```

This is a supported contract and will not be removed in a 1.x release.

**The container is promised; the contents are not**, and the line between them is
`hampel/sparkpost`'s rather than this package's. `$errors` is `public readonly` on every
`ApiException` subclass and is always a list of arrays — empty when the response carried no
`errors`, and empty when the body was not JSON at all, which happens when a proxy answers
on that URL with HTML. It is never `null`, so no guard is needed beyond the `instanceof`.
The keys *inside* each error are SparkPost's payload, passed through unreshaped: if SparkPost
renames a field, neither package will notice and neither will issue a major for it. Hence
`??` above rather than a bare index.

`ApiException` is abstract; the concrete throw is `ClientException`, `RateLimitException` or
`ServerException`, so the one `instanceof` covers all three and keeps working if a fourth
appears.

The SparkPost transmission id is recorded as the message id. `Mailer::send()` returns
`void`, so the `SentMessage` carrying it comes from the transport:

```php
$sent = $transport->send($email);

$sent->getMessageId();   // the transmission id, for matching against message events
$sent->getDebug();       // "SparkPost transmission 1166…: 2 accepted, 0 rejected"
```

The transport dispatches the same events the mailer does. It returns `null` only when a
listener rejected the message before it was sent.

## SparkPost-specific fields

Campaigns, metadata and substitution data are transmission-level concepts with no MIME
equivalent, so there is nowhere in a plain `Email` to put them. Use `SparkPostEmail`:

```php
use Hampel\SparkPost\Transport\Mime\SparkPostEmail;

$email = (new SparkPostEmail())
    ->setCampaignId('welcome')
    ->setTransactional()
    ->setOpenTracking(false)
    ->setMetadata(['user_id' => 7])
    ->setSubstitutionData(['first_name' => 'Alice']);

$email->from('webmaster@example.com')->to('alice@example.com')->subject('Welcome')->text('…');
```

It is a plain `Email` in every other respect, and it survives serialisation — so a message
queued through Symfony Messenger arrives at the worker with all of the above intact.

Stored templates and A/B tests replace the message body entirely. The message still needs a
From and a To, because the envelope is built from them:

```php
(new SparkPostEmail())->setTemplate('welcome')->from(…)->to(…);
(new SparkPostEmail())->setAbTest('subject-line')->from(…)->to(…);
```

## Options for every message

Campaigns and metadata are per-message, but tracking and transactional are usually a
decision an application makes once. `EmailConverter` takes defaults for them, applied to
every message the transport sends — including a plain `Email`, which matters when the
messages are built by a framework that has never heard of `SparkPostEmail`:

```php
$transport = new SparkPostTransport($sparkpost, null, null, new EmailConverter([
    'open_tracking' => false,
    'click_tracking' => false,
    'transactional' => true,
]));
```

Anything the message itself carries wins, so a single `SparkPostEmail` can still turn
tracking back on without disturbing the default.

## The bounce address

SparkPost's `return_path` is the envelope sender: where a bounce is delivered, and the
domain a receiver runs SPF against. It is a different thing from the `From:` a reader sees,
and the difference is what DMARC alignment is about.

Set it the ordinary Symfony way and it is used:

```php
$email->returnPath('bounces@bounce.example.com');   // or ->sender(...)
```

Nothing is sent when you set nothing: the transport only populates `return_path` when the
envelope sender actually differs from the `From:`, so a message nobody configured a bounce
address for is left to SparkPost's own defaults.

`SparkPostEmail::setSparkPostReturnPath()` sets the same field and wins over the envelope,
for when a single message needs a different bounce address.

### What SparkPost then does with it

None of this is visible from inside this package — it is SparkPost's behaviour, and it
depends on how the account is configured, so it can only be established by operating one.
The following was measured against a live account rather than read from documentation:

- **Only the domain survives.** SparkPost replaces the local part with an identifier of its
  own, so `bounces@bounce.example.com` is delivered with a `Return-Path` of
  `<id>@bounce.example.com`. Reading back a local part you did not choose is what success
  looks like here, not a failure.
- **A domain the account has not been configured for is discarded, not honoured.** The
  transmission is accepted, the value is ignored, and the message is sent under the fallback
  below. It is the `From:` that SparkPost polices, with
  `HTTP 400 "Unconfigured Sending Domain <domain>"`.

  **Discarded is a statement about SparkPost, not about delivery.** The value is inert at the
  API and can still cost you the message at the far end, because the fallback removes the SPF
  leg of DMARC — see below. Setting a domain the account does not know is therefore not a
  harmless no-op: it is the same position as setting nothing, which is a position worth
  choosing deliberately rather than arriving at by typo.
- **The fallback is two steps.** Setting nothing — or setting a domain SparkPost does not
  recognise — uses the account's default bounce domain, or the *subaccount's* where the API
  key is a subaccount key; and where neither is configured, `sparkpostmail.com`.

### Setting one is worth doing, and it has to be chosen with the `From:`

A custom bounce domain is usually presented as being about where bounces are collected. The
larger reason is authentication. SPF authenticates the `Return-Path` domain, and DMARC
passes on the strength of SPF only when that domain **aligns** with the `From:` — so every
fallback above authenticates correctly, aligns with nothing, and leaves DMARC resting on
DKIM alone.

**That is one failure away from rejection rather than two.** An aligned bounce domain is not
belt-and-braces: it is the second of two independent routes to a DMARC pass. Without one the
message rests entirely on DKIM alignment, so a DKIM problem becomes an outage rather than a
degradation — and a receiver enforcing DMARC refuses a message SparkPost sent perfectly well,
with nothing on the sending side to show that it did.

**Alignment is a relationship between the two domains, not a property of either.** Relaxed
alignment needs the same organisational domain, strict needs the identical one:

| `Return-Path` | `From:` | aligns |
|---|---|---|
| `<id>@example.com` | `noreply@example.com` | strict, and relaxed |
| `<id>@bounce.example.com` | `noreply@example.com` | relaxed |
| `<id>@bounce.example.net` | `noreply@example.com` | neither |

The last row is configured, valid, delivered — and has bought nothing. So the bounce domain
and the `From:` are chosen together: change either alone and everything keeps working, mail
keeps arriving, and the second DMARC path disappears with nothing to show for it.

Unset is a working configuration rather than a recommended one. An application with a bounce
domain available on the account should set it, and should pick one that aligns.

## Cc, Bcc, and what the recipient sees

SparkPost sends one message per recipient, so a naive transport gives every recipient a
`To:` line containing only themselves, and loses Cc entirely. This one does what ordinary
mail does: every recipient gets the same `To:` line, Cc is made visible with a `CC` header,
and Bcc gets no header at all — which is what makes it blind.

## Testing against the sink

SparkPost accepts, counts and discards anything at `<address>.sink.sparkpostmail.com`, so a
staging site can exercise real sending, including bounce and delivery events, without mail
reaching anyone:

```php
use Hampel\SparkPost\Transport\EventListener\SinkEnvelopeListener;
use Symfony\Component\EventDispatcher\EventDispatcher;

$dispatcher = new EventDispatcher();
$dispatcher->addSubscriber(new SinkEnvelopeListener());

$transport = new SparkPostTransport($sparkpost, $dispatcher);
```

The dispatcher has to reach the transport, which is what invokes the listener. A listener
added to a dispatcher the transport never received does nothing, and the mail is delivered
normally.

It rewrites the envelope and leaves the headers alone, so the delivered message still reads
as though it were addressed normally.

## Symfony versions

`^5.4|^6.4|^7.0`, and the suite runs against all three. 5.4 is included deliberately: this
package is used inside host applications that bundle Symfony themselves at 5.4 and load
their own copy first, so a consumer there cannot substitute a newer one.

## Licence

MIT. See [LICENSE.md](LICENSE.md).
