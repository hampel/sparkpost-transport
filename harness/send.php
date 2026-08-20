<?php

/**
 * Exercise: send a real message through Symfony Mailer and the SparkPost transport.
 *
 * The suite proves the payload is built correctly against a stub client. It cannot tell
 * you whether SparkPost accepts what Symfony produces, which is the only question left
 * before a release - so this drives the whole stack: Email, Mailer, transport, API client,
 * real HTTP.
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
use Symfony\Component\Mailer\Mailer;

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

$factory = new HttpFactory();
$sparkpost = new SparkPost(Config::forRegion($key, getenv('SPARKPOST_REGION') ?: null), new Client(), $factory, $factory);

$dispatcher = new EventDispatcher();

if ($sink) {
    $dispatcher->addSubscriber(new SinkEnvelopeListener());
}

$transport = new SparkPostTransport($sparkpost, null, $dispatcher);

$io->value('transport', (string) $transport);
$io->value('sink', $sink ? 'yes - nothing will be delivered' : 'no');
$io->line();

$email = (new SparkPostEmail())
    ->setCampaignId('rig-send')
    ->setTransactional()
    ->setMetadata(['source' => 'rig']);

$email
    ->from($from, 'Rig')
    ->to($to)
    ->subject('hampel/sparkpost-transport · rig send')
    ->text('Sent by vendor/bin/rig send, through Symfony Mailer.')
    ->html('<p>Sent by <code>vendor/bin/rig send</code>, through Symfony Mailer.</p>');

try {
    $sent = (new Mailer($transport))->send($email);
} catch (TransportExceptionInterface $e) {
    $io->error('✗ ' . $e::class);
    $io->value('message', $e->getMessage());
    $io->value('debug', $e->getDebug());

    exit(1);
}

$io->success('✓ sent');
$io->value('transmission', $sent?->getMessageId());
$io->value('debug', $sent?->getDebug());
