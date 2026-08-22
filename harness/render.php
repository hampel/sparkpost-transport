<?php

/**
 * Exercise: show the payloads the converter builds. Touches nothing, sends nothing.
 *
 * The other three exercises all reach the live API, which since hampel/rig 0.2.0 means an
 * agent session cannot run any of them - the rig withholds .env, and they stop for want of
 * a key. That is the guard working, but it left nothing here that was safe to run at all.
 *
 * This is that thing. It needs no credential, opens no socket, and is the fastest way to
 * see what a change to EmailConverter actually did: the suite asserts on fragments of the
 * payload, and reading the whole of it is a different kind of check.
 *
 * @var Hampel\Rig\Io $io
 */

use Hampel\SparkPost\Transport\EmailConverter;
use Hampel\SparkPost\Transport\EventListener\SinkEnvelopeListener;
use Hampel\SparkPost\Transport\Mime\SparkPostEmail;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\Event\MessageEvent;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

$io->title('sparkpost-transport · render');

$show = static function (string $label, Email $email, ?Envelope $envelope = null, array $defaults = []) use ($io): void {
    $payload = (new EmailConverter($defaults))->convert($email, $envelope ?? Envelope::create($email))->toArray();

    $io->info($label);
    $io->line(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '(could not encode)');
    $io->line();
};

// 1. The least a message can be, and what a framework's own mailer produces.
$plain = (new Email())
    ->from(new Address('webmaster@example.com', 'Webmaster'))
    ->to(new Address('alice@example.com', 'Alice'))
    ->subject('Hello')
    ->text('Hello from SparkPost.');

$show('A plain Email, no defaults', clone $plain);

// 2. The same message, with the options an application usually decides once.
$show('The same, with converter defaults', clone $plain, null, [
    'transactional' => true,
    'open_tracking' => false,
    'click_tracking' => false,
]);

// 3. Return-Path becomes the envelope sender becomes return_path.
$bounced = (clone $plain)->returnPath(new Address('bounces@example.com'));
$show('With Email::returnPath()', $bounced);

// 4. Delivery and display disagreeing, which is the design decision worth seeing rendered.
// The listener rewrites the envelope only, so recipients[] goes to the sink while the To:
// and CC: lines the reader sees are untouched.
$addressed = (new Email())
    ->from(new Address('webmaster@example.com', 'Webmaster'))
    ->to(new Address('alice@example.com', 'Alice'))
    ->cc(new Address('bob@example.com', 'Bob'))
    ->bcc(new Address('carol@example.com', 'Carol'))
    ->subject('Hello')
    ->text('Hello from SparkPost.');

$envelope = Envelope::create($addressed);

$dispatcher = new EventDispatcher();
$dispatcher->addSubscriber(new SinkEnvelopeListener());
$dispatcher->dispatch(new MessageEvent($addressed, $envelope, 'sparkpost+api://api.sparkpost.com'));

$show('To/Cc/Bcc, with the sink listener having rewritten the envelope', $addressed, $envelope);

// 5. Everything SparkPost-specific, none of which has a MIME equivalent.
$sparkpost = (new SparkPostEmail())
    ->setCampaignId('welcome')
    ->setTransactional()
    ->setMetadata(['user_id' => 7])
    ->setSubstitutionData(['first_name' => 'Alice'])
    ->setSparkPostReturnPath('bounces@example.com');

$sparkpost
    ->from(new Address('webmaster@example.com', 'Webmaster'))
    ->to(new Address('alice@example.com', 'Alice'))
    ->subject('Hello')
    ->text('Hello {{first_name}}.');

$show('A SparkPostEmail', $sparkpost);

$io->success('✓ rendered - nothing was sent');
