<?php

declare(strict_types=1);

namespace Hampel\SparkPost\Transport\EventListener;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Mailer\Event\MessageEvent;
use Symfony\Component\Mime\Address;

/**
 * Redirects every message to SparkPost's sink, for testing.
 *
 * Anything at `<address>.sink.sparkpostmail.com` is accepted, counted and thrown away, so
 * a staging site can exercise real sending - including bounce and delivery events -
 * without mail reaching anyone.
 *
 * It rewrites the envelope and leaves the headers alone, so the message still reads as
 * though it were addressed normally. That only survives as far as the payload because the
 * transmission keeps delivery and display apart; see EmailConverter.
 */
final class SinkEnvelopeListener implements EventSubscriberInterface
{
    public function __construct(private readonly string $sinkSuffix = '.sink.sparkpostmail.com')
    {
    }

    public function onMessage(MessageEvent $event): void
    {
        if ($this->sinkSuffix === '') {
            return;
        }

        $envelope = $event->getEnvelope();

        // No display name: Envelope::setRecipients() discards them anyway, because an
        // envelope is SMTP-level and names mean nothing there. What the recipient sees
        // comes from the message headers, which this listener leaves untouched.
        $envelope->setRecipients(array_map(
            fn (Address $address): Address => new Address($address->getAddress() . $this->sinkSuffix),
            $envelope->getRecipients()
        ));
    }

    /**
     * @return array<class-string, string>
     */
    public static function getSubscribedEvents(): array
    {
        return [
            // Last, so other listeners have finished changing the headers first.
            MessageEvent::class => 'onMessage',
        ];
    }
}
