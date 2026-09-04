<?php

/**
 * Exercise: send through the transport, the SparkPost way. Reaches the live API.
 *
 * The suite proves the payload is built correctly against a stub client. It cannot tell
 * you whether SparkPost accepts what Symfony produces, which is the only question left
 * before a release - so this drives the whole stack: Email, transport, API client, real
 * HTTP.
 *
 * It sends nothing to a real address unless told to. Without SPARKPOST_DELIVER=1 every
 * recipient goes through SinkEnvelopeListener to SparkPost's sink, which accepts, counts
 * and discards it - everything except the last hop. Delivering is the deliberate act,
 * because the session that does not know a populated .env is sitting here is exactly the
 * session that will not know about a flag either.
 *
 * Set SPARKPOST_RETURN_PATH to exercise the envelope FROM, which the suite cannot settle
 * either. It is a different address from the header From, and the difference is the point:
 * the envelope address is where bounces are delivered and what the receiver runs SPF
 * against, while the header From is what the reader sees and what DMARC aligns against.
 *
 * SparkPost polices the From and not the return path. A From outside the configured sending
 * domains is refused at the API with HTTP 400 "Unconfigured Sending Domain <domain>"; a
 * return path is not checked at all, so a bounce domain the account is not configured for is
 * accepted and then discarded silently - the mail is delivered under the account default.
 * Only the domain survives even when it is configured: the local part comes back as an id
 * SparkPost chose. Configured but not aligned with the From passes SPF and still fails
 * DMARC. Every one of those verdicts is reached on somebody else's mail server, so no
 * amount of unit testing reaches them, and the payload is identical in all of them.
 *
 * This exercise sets the return path the SparkPost-specific way. `rig envelope` sets the
 * same field the ordinary Symfony way; the two are worth comparing.
 *
 * Needs SPARKPOST_API_KEY, SPARKPOST_TO, SPARKPOST_FROM. SPARKPOST_RETURN_PATH is optional.
 *
 * @var Hampel\Rig\Io $io
 */

use GuzzleHttp\Client;
use GuzzleHttp\Psr7\HttpFactory;
use Hampel\SparkPost\Config;
use Hampel\SparkPost\SparkPost;
use Hampel\SparkPost\Transport\EmailConverter;
use Hampel\SparkPost\Transport\EventListener\SinkEnvelopeListener;
use Hampel\SparkPost\Transport\Mime\SparkPostEmail;
use Hampel\SparkPost\Transport\SparkPostTransport;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mime\Address;

$io->title('sparkpost-transport · send');

$key = getenv('SPARKPOST_API_KEY');
$to = getenv('SPARKPOST_TO');
$from = getenv('SPARKPOST_FROM');

if ($key === false || $key === '' || $to === false || $to === '' || $from === false || $from === '') {
    $io->error('SPARKPOST_API_KEY, SPARKPOST_TO and SPARKPOST_FROM are all needed.');
    $io->info('  Copy .env.example to .env beside the package.');

    exit(1);
}

require __DIR__ . '/lib/delivery.php';

// Sink unless SPARKPOST_DELIVER=1, and refused outright for an agent session whatever the
// .env says. See harness/lib/delivery.php for why the second gate exists.
[$deliver, $mode] = rig_delivery();

// sparkpostbox.com is SparkPost's sandbox domain, which lets someone without a verified
// sending domain run this at all - but only when the transmission carries the sandbox
// option, so set it to match the From. Not every account has the sandbox domain enabled;
// see .env.example for the error you get when it does not.
$sandbox = str_ends_with(strtolower($from), '@sparkpostbox.com');

$returnPath = getenv('SPARKPOST_RETURN_PATH') ?: null;

$factory = new HttpFactory();
$sparkpost = new SparkPost(Config::forRegion($key, getenv('SPARKPOST_REGION') ?: null), new Client(), $factory, $factory);

$dispatcher = new EventDispatcher();

if (! $deliver) {
    $dispatcher->addSubscriber(new SinkEnvelopeListener());
}

$transport = new SparkPostTransport($sparkpost, $dispatcher);

$io->value('transport', (string) $transport);
$io->value('mode', $mode);
$io->value('sandbox', $sandbox ? 'yes - sparkpostbox.com, limited to a few messages' : 'no');
$io->line();

$email = (new SparkPostEmail())
    ->setCampaignId('rig-send')
    ->setTransactional()
    // Off so the delivered message is the one the converter built. Click tracking rewrites
    // every link through SparkPost's domain and open tracking appends a pixel, which is
    // noise when the point is to read the headers that came out the far end.
    ->setOpenTracking(false)
    ->setClickTracking(false)
    ->setMetadata(['source' => 'rig']);

if ($sandbox) {
    $email->setSandbox();
}

// The SparkPost-specific setter, which is what this exercise is here to cover. Since 0.3.0
// Email::returnPath() reaches the same field through the envelope - `rig envelope` does it
// that way - and this one wins when both are set.
if ($returnPath !== null) {
    $email->setSparkPostReturnPath($returnPath);
}

$email
    ->from(new Address($from, 'Rig'))
    ->to($to)
    ->subject('hampel/sparkpost-transport · rig send')
    ->text('Sent by vendor/bin/rig send, through the SparkPost transport.')
    ->html('<p>Sent by <code>vendor/bin/rig send</code>, through the SparkPost transport.</p>');

// What the converter actually built. Printed from the payload rather than the variables,
// because where return_path lands is the part worth seeing: it is a top-level field, not
// one of the options, and putting it under options is a mistake SparkPost accepts in
// silence - a 200, a delivered message, and the default bounce domain still on the
// envelope. This is the same converter the transport uses; only the envelope recipients
// differ, since no listener has rewritten them yet.
$payload = (new EmailConverter())->convert($email, Envelope::create($email))->toArray();

$io->value('options', $payload['options'] ?? []);
$io->value('return_path', $payload['return_path'] ?? '(not in the payload)');
$io->line();

try {
    // Through the transport rather than Mailer: MailerInterface::send() returns void, so
    // the SentMessage - and with it the transmission id - is only available here. The
    // transport dispatches the MessageEvent itself, so the sink listener still runs.
    $sent = $transport->send($email);
} catch (TransportExceptionInterface $e) {
    $io->error('✗ ' . $e::class);
    $io->value('message', $e->getMessage());
    $io->value('debug', $e->getDebug());

    exit(1);
}

$io->success('✓ sent');
$io->value('transmission', $sent?->getMessageId());
$io->value('debug', $sent?->getDebug());

if ($returnPath !== null) {
    $io->line();
    // SparkPost polices the From and not the return path, which is the asymmetry that
    // makes "it validates sender domains" plausible and wrong. Established 21-22 August
    // 2026 across both packages: a From outside the configured sending domains is refused
    // at the API with HTTP 400 "Unconfigured Sending Domain <domain>"; a return path on a
    // domain nobody owns came back 200 with 1 accepted, and that message never arrived.
    $io->info('SparkPost checks the From against your sending domains. It does not check');
    $io->info('this - a 200 means the payload was well formed and nothing more, so a bogus');
    $io->info('return path is taken and the mail then quietly fails to arrive. Read the');
    $io->info('delivered message:');
    $io->line('  Return-Path:                   the envelope address, and where a bounce would go');
    $io->line('  Authentication-Results: spf    authenticates the envelope domain, not the From');
    $io->line('  Authentication-Results: dmarc  passes only if SPF or DKIM aligns with the From');
    $io->line();
    $envelopeDomain = substr(strrchr($returnPath, '@') ?: '@', 1);
    $fromDomain = substr(strrchr($from, '@') ?: '@', 1);

    if (strcasecmp($envelopeDomain, $fromDomain) === 0) {
        $io->success(sprintf('Envelope and From are both on %s, so SPF alignment is satisfied.', $fromDomain));
    } else {
        $io->warn(sprintf('Envelope is on %s and From is on %s: SPF authenticates but does', $envelopeDomain, $fromDomain));
        $io->warn('not align. DMARC then rests entirely on DKIM, which SparkPost signs with');
        $io->warn('the From domain when that domain is configured for it - so check the');
        $io->warn('dmarc= verdict in the delivered message rather than assuming either way.');
    }
}

if (! $deliver) {
    $io->line();
    $io->warn('Sink: everything above is real, but nothing was delivered, so there is no');
    $io->warn('message to read the headers of. SPARKPOST_DELIVER=1 answers delivery,');
    $io->warn('Return-Path and DMARC.');
}
