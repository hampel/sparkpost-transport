<?php

/**
 * Exercise: send a real message through the SparkPost transport.
 *
 * The suite proves the payload is built correctly against a stub client. It cannot tell
 * you whether SparkPost accepts what Symfony produces, which is the only question left
 * before a release - so this drives the whole stack: Email, transport, API client, real
 * HTTP.
 *
 * Set SPARKPOST_SINK=1 to route it through SinkEnvelopeListener instead, which SparkPost
 * accepts and discards. That exercises everything except the last hop.
 *
 * Needs SPARKPOST_API_KEY, SPARKPOST_TO, SPARKPOST_FROM.
 *
 * @var Hampel\Rig\Io $io
 */

use GuzzleHttp\Client;
use GuzzleHttp\Psr7\HttpFactory;
use Hampel\SparkPost\Config;
use Hampel\SparkPost\SparkPost;
use Hampel\SparkPost\Transport\EventListener\SinkEnvelopeListener;
use Hampel\SparkPost\Transport\Mime\SparkPostEmail;
use Hampel\SparkPost\Transport\SparkPostTransport;
use Symfony\Component\EventDispatcher\EventDispatcher;
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

$sink = getenv('SPARKPOST_SINK') === '1';

// sparkpostbox.com is SparkPost's sandbox domain, which lets someone without a verified
// sending domain run this at all - but only when the transmission carries the sandbox
// option, so set it to match the From. Not every account has the sandbox domain enabled;
// see .env.example for the error you get when it does not.
$sandbox = str_ends_with(strtolower($from), '@sparkpostbox.com');

$factory = new HttpFactory();
$sparkpost = new SparkPost(Config::forRegion($key, getenv('SPARKPOST_REGION') ?: null), new Client(), $factory, $factory);

$dispatcher = new EventDispatcher();

if ($sink) {
    $dispatcher->addSubscriber(new SinkEnvelopeListener());
}

$transport = new SparkPostTransport($sparkpost, $dispatcher);

$io->value('transport', (string) $transport);
$io->value('sink', $sink ? 'yes - nothing will be delivered' : 'no');
$io->value('sandbox', $sandbox ? 'yes - sparkpostbox.com, limited to a few messages' : 'no');
$io->line();

$email = (new SparkPostEmail())
    ->setCampaignId('rig-send')
    ->setTransactional()
    ->setMetadata(['source' => 'rig']);

if ($sandbox) {
    $email->setSandbox();
}

$email
    ->from(new Address($from, 'Rig'))
    ->to($to)
    ->subject('hampel/sparkpost-transport · rig send')
    ->text('Sent by vendor/bin/rig send, through the SparkPost transport.')
    ->html('<p>Sent by <code>vendor/bin/rig send</code>, through the SparkPost transport.</p>');

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
