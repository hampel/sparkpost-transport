# SparkPost transport for Symfony Mailer

By [Simon Hampel](mailto:simon@hampelgroup.com)

Sends Symfony Mailer messages through the SparkPost transmissions API, using
[`hampel/sparkpost`](https://github.com/hampel/sparkpost) to do the talking.

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

$mailer = new Mailer(new SparkPostTransport($sparkpost));

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
one — and the transport this replaces reported that as a successful send. Here it is a
failure:

- **Nobody accepted** → `TransportException`. Nothing was sent, and you will hear about it.
- **Some accepted, some rejected** → a `warning` on the logger, and the send succeeds.
  Raising here would tell the caller the whole thing failed, and a retry would deliver
  twice to everyone who already had it.

Everything that leaves the transport implements Symfony's `TransportExceptionInterface`,
including API errors and connection failures, so the one `catch` a Symfony consumer
already writes is enough.

The SparkPost transmission id is recorded as the message id:

```php
$sent = $mailer->send($email);
$sent->getMessageId();   // the transmission id, for matching against message events
$sent->getDebug();       // "SparkPost transmission 1166…: 2 accepted, 0 rejected"
```

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

$dispatcher->addSubscriber(new SinkEnvelopeListener());
```

It rewrites the envelope and leaves the headers alone, so the delivered message still reads
as though it were addressed normally.

## Symfony versions

`^5.4|^6.4|^7.0`, and the suite runs against all three. 5.4 is included deliberately: it is
what XenForo 2.3 bundles, and a XenForo add-on cannot substitute its own — the host's
autoloader wins.

## Licence

MIT. See [LICENSE.md](LICENSE.md).
