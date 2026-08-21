<?php

declare(strict_types=1);

namespace Hampel\SparkPost\Transport;

use Hampel\SparkPost\Exception\ExceptionInterface as SparkPostExceptionInterface;
use Hampel\SparkPost\Result\TransmissionResult;
use Hampel\SparkPost\SparkPost;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\Exception\ExceptionInterface as MimeException;
use Symfony\Component\Mime\Message;
use Symfony\Component\Mime\MessageConverter;

/**
 * A Symfony Mailer transport that sends through the SparkPost transmissions API.
 *
 * Two things distinguish it from the transport it replaces.
 *
 * It owns no HTTP code: hampel/sparkpost does the talking, so the client is whatever the
 * host application injected there, and this class is only a translation layer.
 *
 * And it checks what SparkPost said. A transmission can come back HTTP 200 having
 * accepted no recipients at all, which the previous implementation reported as a
 * successful send - the mail simply vanished. Here that is a failure.
 */
final class SparkPostTransport extends AbstractTransport
{
    private readonly EmailConverter $converter;

    public function __construct(
        private readonly SparkPost $sparkpost,
        ?EventDispatcherInterface $dispatcher = null,
        ?LoggerInterface $logger = null,
        ?EmailConverter $converter = null,
    ) {
        $this->converter = $converter ?? new EmailConverter();

        parent::__construct($dispatcher, $logger);
    }

    public function __toString(): string
    {
        return sprintf('sparkpost+api://%s', $this->sparkpost->config()->host());
    }

    protected function doSend(SentMessage $message): void
    {
        $email = $this->toEmail($message);

        $transmission = $this->converter->convert($email, $message->getEnvelope());

        try {
            $result = $this->sparkpost->transmissions()->send($transmission);
        } catch (SparkPostExceptionInterface $e) {
            // Everything leaving a Symfony transport has to implement
            // TransportExceptionInterface, or it slips past every catch a consumer wrote.
            throw new TransportException($e->getMessage(), (int) $e->getCode(), $e);
        }

        $message->setMessageId($result->id);
        $message->appendDebug($this->describe($result));

        if (! $result->wasAccepted()) {
            throw new TransportException(sprintf(
                'SparkPost accepted the transmission but rejected every recipient (%d of %d). Nothing was sent.',
                $result->totalRejectedRecipients,
                $result->totalRecipients()
            ));
        }

        if ($result->hasRejections()) {
            // Some went, some did not. Throwing here would tell the caller the whole send
            // failed, and a retry would deliver twice to everyone it did reach - so this
            // is reported rather than raised.
            $this->getLogger()->warning('SparkPost rejected some recipients', [
                'transmission' => $result->id,
                'accepted' => $result->totalAcceptedRecipients,
                'rejected' => $result->totalRejectedRecipients,
            ]);
        }
    }

    /**
     * Symfony hands the transport a RawMessage, which is not necessarily a MIME message
     * at all. Anything that cannot be converted has to leave here as a TransportException
     * like every other failure, rather than as the Mime component's own RuntimeException.
     */
    private function toEmail(SentMessage $message): Email
    {
        $original = $message->getOriginalMessage();

        if (! $original instanceof Message) {
            throw new TransportException(sprintf(
                'Cannot send a "%s" through SparkPost: it is not a MIME message.',
                get_debug_type($original)
            ));
        }

        try {
            return MessageConverter::toEmail($original);
        } catch (MimeException $e) {
            throw new TransportException($e->getMessage(), 0, $e);
        }
    }

    private function describe(TransmissionResult $result): string
    {
        return sprintf(
            'SparkPost transmission %s: %d accepted, %d rejected',
            $result->id,
            $result->totalAcceptedRecipients,
            $result->totalRejectedRecipients
        );
    }
}
