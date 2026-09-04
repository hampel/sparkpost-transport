<?php

/**
 * Exercise: a return path set the ordinary Symfony way. Reaches the live API.
 *
 * `rig send` sets the bounce address with SparkPostEmail::setSparkPostReturnPath(), which
 * is the SparkPost-specific route. This one uses no SparkPost class at all - a plain
 * Symfony Email and Email::returnPath(), which is what a framework's own mailer produces
 * and what a consumer reaches for first.
 *
 * Until 0.3.0 that was discarded in silence. The Return-Path header is in the transmission
 * builder's disallowed list, and nothing populated the top-level return_path field except
 * SparkPostEmail - so the caller asked for a bounce address and got the account default
 * with nothing to say otherwise. Now the envelope sender carries it: Symfony resolves the
 * envelope sender from Return-Path, then Sender, then From, and that is the same thing
 * SparkPost calls return_path.
 *
 * Which makes this exercise the regression test that cannot be written as a unit test. The
 * suite proves the payload carries the field; only a delivered message proves the far end
 * agrees, and the Return-Path header a receiver reports is the only place that shows.
 *
 * Set SPARKPOST_SENDER=1 to use Email::sender() instead of Email::returnPath(). Symfony
 * resolves either into the envelope sender, so both should produce the same payload - and
 * confirming that is the point of the switch.
 *
 * It also covers the other thing a plain Email cannot say for itself. Tracking and
 * transactional are per-message on a SparkPostEmail, but an application decides them once,
 * and a framework's mailer builds an Email that has never heard of either - so they reach
 * the transmission as converter defaults instead. The transport here is given the same
 * converter the payload is built with, or the exercise would print defaults it did not send.
 *
 * Without SPARKPOST_RETURN_PATH set there is no bounce address to send, and the exercise
 * shows the other half of the rule: no return_path in the payload, because the envelope
 * sender then merely repeats the From and sending it would move bounces off SparkPost's own
 * bounce domain.
 *
 * Needs SPARKPOST_API_KEY, SPARKPOST_TO, SPARKPOST_FROM. Sinks unless SPARKPOST_DELIVER=1,
 * and refuses to deliver from an agent session - see harness/lib/delivery.php.
 *
 * @var Hampel\Rig\Io $io
 */

use GuzzleHttp\Client;
use GuzzleHttp\Psr7\HttpFactory;
use Hampel\SparkPost\Config;
use Hampel\SparkPost\SparkPost;
use Hampel\SparkPost\Transport\EmailConverter;
use Hampel\SparkPost\Transport\EventListener\SinkEnvelopeListener;
use Hampel\SparkPost\Transport\SparkPostTransport;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

require __DIR__ . '/lib/delivery.php';

$io->title('sparkpost-transport · envelope sender');

$key = getenv('SPARKPOST_API_KEY');
$to = getenv('SPARKPOST_TO');
$from = getenv('SPARKPOST_FROM');

if ($key === false || $key === '' || $to === false || $to === '' || $from === false || $from === '') {
    $io->error('SPARKPOST_API_KEY, SPARKPOST_TO and SPARKPOST_FROM are all needed.');
    $io->info('  Copy .env.example to .env beside the package.');

    exit(1);
}

$returnPath = getenv('SPARKPOST_RETURN_PATH') ?: null;
$useSender = getenv('SPARKPOST_SENDER') === '1';

[$deliver, $mode] = rig_delivery();

$factory = new HttpFactory();
$sparkpost = new SparkPost(Config::forRegion($key, getenv('SPARKPOST_REGION') ?: null), new Client(), $factory, $factory);

$dispatcher = new EventDispatcher();

if (! $deliver) {
    $dispatcher->addSubscriber(new SinkEnvelopeListener());
}

// The other half of what a plain Email cannot say for itself. A SparkPostEmail carries
// tracking and transactional per message; an application usually wants them decided once,
// and a framework's mailer builds an Email that has never heard of either. These reach the
// transmission through the converter, so the transport has to be given the same instance
// the payload below is built with - otherwise this exercise would print defaults it did not
// actually send.
$defaults = [
    'transactional' => true,
    'open_tracking' => false,
    'click_tracking' => false,
];

$converter = new EmailConverter($defaults);

$transport = new SparkPostTransport($sparkpost, $dispatcher, null, $converter);

$io->value('mode', $mode);
$io->value('set via', $returnPath === null
    ? '(nothing - no bounce address to send)'
    : ($useSender ? 'Email::sender()' : 'Email::returnPath()'));
$io->line();

// A plain Email. Nothing on this object knows what SparkPost is, which is the whole point.
$email = (new Email())
    ->from(new Address($from, 'Rig'))
    ->to($to)
    ->subject('hampel/sparkpost-transport · envelope sender')
    ->text('Sent by vendor/bin/rig envelope, as a plain Symfony Email.')
    ->html('<p>Sent by <code>vendor/bin/rig envelope</code>, as a plain Symfony Email.</p>');

if ($returnPath !== null) {
    $useSender
        ? $email->sender(new Address($returnPath))
        : $email->returnPath(new Address($returnPath));
}

// Built the way the transport builds it. Envelope::create() is what resolves Return-Path or
// Sender into the envelope sender, so this is where the whole mechanism shows.
$envelope = Envelope::create($email);
$payload = $converter->convert($email, $envelope)->toArray();

$io->values([
    'header From' => $from,
    'envelope sender' => $envelope->getSender()->getAddress(),
    'return_path' => $payload['return_path'] ?? '(not in the payload)',
    'options' => $payload['options'] ?? '(none)',
]);
$io->line();

// Nothing on the Email asked for these, so if they are absent the defaults did not reach a
// plain message - which is the whole reason the converter takes them.
foreach ($defaults as $key => $expected) {
    if (($payload['options'][$key] ?? null) !== $expected) {
        $io->error(sprintf('✗ Default option %s did not reach the payload.', $key));

        exit(1);
    }
}

if ($returnPath === null && isset($payload['return_path'])) {
    $io->error('✗ A return_path was sent when nothing asked for one.');
    $io->info('  Equal to the From means nobody asked; sending it moves bounces off');
    $io->info('  SparkPost\'s own bounce domain.');

    exit(1);
}

if ($returnPath !== null && ! isset($payload['return_path'])) {
    $io->error('✗ A return path was set on the Email and did not reach the payload.');
    $io->info('  That is the 0.3.0 regression this exercise exists to catch.');

    exit(1);
}

try {
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

if (! $deliver) {
    $io->line();
    $io->warn('Sink: the payload above is real, but nothing was delivered - and a delivered');
    $io->warn('message is the only thing that shows what the far end did with the return');
    $io->warn('path. SPARKPOST_DELIVER=1 is what answers that.');

    return;
}

if ($returnPath === null) {
    return;
}

$envelopeDomain = substr(strrchr($returnPath, '@') ?: '@', 1);
$fromDomain = substr(strrchr($from, '@') ?: '@', 1);

$io->line();
$io->info('Read the delivered message:');
$io->line('  Return-Path:                   should be <id>@' . $envelopeDomain);
$io->line('  Authentication-Results: spf    authenticates that domain, not the From');
$io->line('  Authentication-Results: dmarc  passes only if SPF or DKIM aligns with the From');
$io->line();
$io->line('  SparkPost replaces the local part with an identifier of its own, so the "' . strstr($returnPath, '@', true) . '"');
$io->line('  above will not come back. That is what success looks like, not a failure.');
$io->line();
$io->line('  And only if ' . $envelopeDomain . ' is configured as a bounce domain on the');
$io->line('  account - or the subaccount, for a subaccount key. If it is not, SparkPost');
$io->line('  discards the value silently and sends under the account default, or');
$io->line('  sparkpostmail.com where there is none. The payload above is identical either');
$io->line('  way, so the delivered header is the only thing that tells them apart.');
$io->line();
$io->line('  And an unrecognised domain is not a harmless no-op: the fallback aligns with');
$io->line('  nothing, leaving DMARC on DKIM alone, which has been observed to lose the');
$io->line('  message at a DMARC-enforcing receiver. If nothing arrives at all, that is the');
$io->line('  first thing to check - SparkPost will have reported the send as fine.');

if (strcasecmp($envelopeDomain, $fromDomain) === 0) {
    $io->success(sprintf('Envelope and From are both on %s, so SPF alignment is satisfied', $fromDomain));
    $io->success('- provided the domain is configured, per the caveat above.');
} else {
    $io->warn(sprintf('Envelope is on %s and From is on %s: SPF authenticates but does', $envelopeDomain, $fromDomain));
    $io->warn('not align. DMARC then rests entirely on DKIM, which is the same place the');
    $io->warn('fallback leaves it - so this bounce domain is buying nothing.');
}
