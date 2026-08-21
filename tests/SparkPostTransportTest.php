<?php

declare(strict_types=1);

namespace Hampel\SparkPost\Transport\Tests;

use Hampel\SparkPost\Config;
use Hampel\SparkPost\SparkPost;
use Hampel\SparkPost\Transport\SparkPostTransport;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\Header\Headers;
use Symfony\Component\Mime\Message;
use Symfony\Component\Mime\RawMessage;

final class SparkPostTransportTest extends TestCase
{
    private function email(): Email
    {
        return (new Email())
            ->from('webmaster@example.com', )
            ->to('alice@example.com')
            ->subject('Hello')
            ->text('Body.');
    }

    public function test_it_names_the_endpoint_it_is_pointed_at(): void
    {
        $this->assertSame('sparkpost+api://api.sparkpost.com', (string) $this->transport());

        $factory = new \GuzzleHttp\Psr7\HttpFactory();
        $eu = new SparkPost(Config::forRegion('key', 'eu'), $this->client, $factory, $factory);

        $this->assertSame('sparkpost+api://api.eu.sparkpost.com', (string) new SparkPostTransport($eu));
    }

    public function test_it_sends_a_message(): void
    {
        $this->queueAccepted();

        $this->transport()->send($this->email());

        $payload = $this->sentTransmission();

        $this->assertSame(['email' => 'webmaster@example.com'], self::path($payload, 'content.from'));
        $this->assertSame('Hello', self::path($payload, 'content.subject'));
        $this->assertSame('Body.', self::path($payload, 'content.text'));
        $this->assertSame('alice@example.com', self::path($payload, 'recipients.0.address.email'));
    }

    public function test_it_records_the_transmission_id_as_the_message_id(): void
    {
        $this->queueAccepted(id: 'transmission-99');

        $sent = $this->transport()->send($this->email());

        $this->assertNotNull($sent);
        $this->assertSame('transmission-99', $sent->getMessageId());
        $this->assertStringContainsString('1 accepted, 0 rejected', $sent->getDebug());
    }

    /**
     * The defect this package exists to fix. SparkPost answers 200 having accepted
     * nobody, and the transport it replaces reported that as a successful send.
     */
    public function test_a_200_that_accepted_nobody_is_a_failed_send(): void
    {
        $this->queueAccepted(accepted: 0, rejected: 1);

        $this->expectException(TransportExceptionInterface::class);
        $this->expectExceptionMessage('rejected every recipient (1 of 1). Nothing was sent.');

        $this->transport()->send($this->email());
    }

    /**
     * But a partial rejection is not a failed send: some recipients did get it, and
     * raising here would invite a retry that delivers twice to those who already have it.
     */
    public function test_a_partial_rejection_is_warned_about_rather_than_raised(): void
    {
        $this->queueAccepted(accepted: 2, rejected: 1);

        $logger = new RecordingLogger();

        $sent = $this->transport($logger)->send(
            $this->email()->addTo('bob@example.com')->addCc('carol@example.com')
        );

        $this->assertNotNull($sent);

        $warnings = array_filter($logger->records, static fn (array $r): bool => $r['level'] === 'warning');
        $this->assertCount(1, $warnings);
    }

    public function test_an_api_error_arrives_as_a_transport_exception(): void
    {
        $this->client->pushJson(422, ['errors' => [['message' => 'required field is missing']]]);

        try {
            $this->transport()->send($this->email());
            $this->fail('Expected a transport exception.');
        } catch (TransportExceptionInterface $e) {
            $this->assertStringContainsString('required field is missing', $e->getMessage());
            $this->assertSame(422, $e->getCode());
            // the API package's exception is kept as the cause
            $this->assertInstanceOf(\Hampel\SparkPost\Exception\ClientException::class, $e->getPrevious());
        }
    }

    /**
     * A consumer of a Symfony transport catches TransportExceptionInterface. Anything
     * else - a Guzzle exception, this package's own - slips straight past them.
     */
    /**
     * The two ways a message can fail to be an Email before anything is sent. Both have
     * to leave as a TransportException like every other failure: a consumer catches
     * TransportExceptionInterface and nothing else, so anything that slips past is a send
     * that fails silently in their application.
     */
    public function test_a_message_that_is_not_mime_at_all_is_a_transport_exception(): void
    {
        $envelope = new Envelope(new Address('webmaster@example.com'), [new Address('alice@example.com')]);

        $this->expectException(TransportExceptionInterface::class);
        $this->expectExceptionMessage('it is not a MIME message');

        $this->transport()->send(new RawMessage('not mime'), $envelope);
    }

    public function test_a_message_the_mime_component_cannot_convert_is_a_transport_exception(): void
    {
        // MessageConverter::toEmail() throws its own RuntimeException for a Message it
        // cannot reduce to an Email - here one with no body at all. The headers still have
        // to be valid: SentMessage's constructor calls ensureValidity() before doSend()
        // ever runs, so a message that is invalid fails earlier and somewhere else.
        $headers = (new Headers())
            ->addMailboxListHeader('From', ['webmaster@example.com'])
            ->addMailboxListHeader('To', ['alice@example.com']);

        $envelope = new Envelope(new Address('webmaster@example.com'), [new Address('alice@example.com')]);

        $this->expectException(TransportExceptionInterface::class);
        $this->expectExceptionMessage('too complex');

        $this->transport()->send(new Message($headers), $envelope);
    }

    public function test_a_transport_failure_also_arrives_as_a_transport_exception(): void
    {
        $factory = new \GuzzleHttp\Psr7\HttpFactory();
        $this->client->push(new TransportFailure($factory->createRequest('POST', 'https://api.sparkpost.com')));

        $this->expectException(TransportExceptionInterface::class);

        $this->transport()->send($this->email());
    }
}
