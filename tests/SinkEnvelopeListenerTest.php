<?php

declare(strict_types=1);

namespace Hampel\SparkPost\Transport\Tests;

use Hampel\SparkPost\Transport\EventListener\SinkEnvelopeListener;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\Event\MessageEvent;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

final class SinkEnvelopeListenerTest extends TestCase
{
    private function event(Email $email, Envelope $envelope): MessageEvent
    {
        return new MessageEvent($email, $envelope, 'sparkpost');
    }

    public function test_it_redirects_every_recipient_to_the_sink(): void
    {
        $email = (new Email())->from('webmaster@example.com')->to('alice@example.com')->subject('Hi')->text('Body.');
        $envelope = new Envelope(new Address('webmaster@example.com'), [
            new Address('alice@example.com', 'Alice'),
            new Address('bob@example.com'),
        ]);

        (new SinkEnvelopeListener())->onMessage($this->event($email, $envelope));

        $this->assertSame(
            ['alice@example.com.sink.sparkpostmail.com', 'bob@example.com.sink.sparkpostmail.com'],
            array_map(static fn (Address $a): string => $a->getAddress(), $envelope->getRecipients())
        );
    }

    /**
     * The headers are what makes this usable: the message still reads normally, so a
     * staging site exercises the real thing rather than a redirected-looking copy.
     */
    public function test_it_leaves_the_message_headers_alone(): void
    {
        $email = (new Email())->from('webmaster@example.com')->to('alice@example.com')->subject('Hi')->text('Body.');
        $envelope = new Envelope(new Address('webmaster@example.com'), [new Address('alice@example.com')]);

        (new SinkEnvelopeListener())->onMessage($this->event($email, $envelope));

        $this->assertSame('alice@example.com', $email->getTo()[0]->getAddress());
    }

    /**
     * Not a quirk of this listener: Envelope::setRecipients() discards display names on
     * everything, because an envelope is SMTP-level. It is worth pinning because it is
     * the reason the transmission takes its To: line from the message headers rather than
     * from the envelope - the envelope has no name to give it.
     */
    public function test_symfony_drops_display_names_from_envelope_recipients(): void
    {
        $email = (new Email())->from('webmaster@example.com')->to('alice@example.com')->subject('Hi')->text('Body.');
        $envelope = new Envelope(new Address('webmaster@example.com'), [new Address('alice@example.com', 'Alice')]);

        (new SinkEnvelopeListener())->onMessage($this->event($email, $envelope));

        $this->assertSame('', $envelope->getRecipients()[0]->getName());
        // the message still knows who she is
        $this->assertSame('alice@example.com', $email->getTo()[0]->getAddress());
    }

    public function test_an_empty_suffix_disables_it(): void
    {
        $email = (new Email())->from('webmaster@example.com')->to('alice@example.com')->subject('Hi')->text('Body.');
        $envelope = new Envelope(new Address('webmaster@example.com'), [new Address('alice@example.com')]);

        (new SinkEnvelopeListener(''))->onMessage($this->event($email, $envelope));

        $this->assertSame('alice@example.com', $envelope->getRecipients()[0]->getAddress());
    }

    public function test_it_subscribes_to_the_message_event(): void
    {
        $this->assertArrayHasKey(MessageEvent::class, SinkEnvelopeListener::getSubscribedEvents());
    }
}
