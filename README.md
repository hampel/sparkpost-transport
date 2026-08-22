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
$email->returnPath('bounces@example.com');   // or ->sender(...)
```

Nothing is sent when you set nothing, so SparkPost uses the account's own bounce domain —
which is where its bounce processing expects mail, so leave it alone unless you have a
verified custom bounce domain.

Two things worth knowing before you rely on it:

- **A custom bounce domain must be verified on the account.** SparkPost does not check it at
  send time — the transmission is accepted either way — so a domain it cannot route produces
  a `200`, and the message then does not arrive. It is the From that SparkPost polices, with
  `HTTP 400 "Unconfigured Sending Domain <domain>"`.
- **For DMARC to pass on the strength of SPF, this domain has to align with the `From:`.**
  `bounces@example.com` against a From of `noreply@example.com` aligns; the same address
  against a From on another domain authenticates and does not align.

`SparkPostEmail::setSparkPostReturnPath()` sets the same field and wins over the envelope,
for when a single message needs a different bounce address.

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
