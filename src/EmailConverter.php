<?php

declare(strict_types=1);

namespace Hampel\SparkPost\Transport;

use Hampel\SparkPost\Transmission\Address as SparkPostAddress;
use Hampel\SparkPost\Transmission\Attachment;
use Hampel\SparkPost\Transmission\Transmission;
use Hampel\SparkPost\Transport\Mime\SparkPostEmail;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\Header\HeaderInterface;
use Symfony\Component\Mime\Header\ParameterizedHeader;
use Symfony\Component\Mime\Part\DataPart;

/**
 * Turns a Symfony Email into a SparkPost Transmission.
 *
 * The one thing worth understanding here is which of the two sources wins for what.
 * Symfony keeps delivery and display apart: the Email's To/Cc/Bcc headers are what a
 * recipient reads, while the Envelope is where the message is actually delivered, and a
 * listener may rewrite the envelope without touching the headers - that is how a sink
 * address or a redirect-all-mail rule works.
 *
 * So the headers build the To: and CC: lines, and the envelope decides where it goes.
 * When nothing has rewritten the envelope the two agree and it makes no difference; when
 * something has, this is the difference between mail that is redirected and mail that
 * merely looks wrong.
 */
final class EmailConverter
{
    public function convert(Email $email, Envelope $envelope): Transmission
    {
        $transmission = Transmission::make();

        $this->applyFrom($transmission, $email, $envelope);
        $this->applyAddresses($transmission, $email);
        $this->applyBody($transmission, $email);
        $this->applyHeaders($transmission, $email);
        $this->applyAttachments($transmission, $email);

        // Delivery follows the envelope, always. See the class comment.
        // Envelope recipients never carry a display name - Symfony strips them, because an
        // envelope is SMTP-level. The names the recipient sees come from the headers above.
        $transmission->deliverTo(array_values(array_map(
            static fn (Address $address): SparkPostAddress => new SparkPostAddress($address->getAddress()),
            $envelope->getRecipients()
        )));

        if ($email instanceof SparkPostEmail) {
            $this->applySparkPostFields($transmission, $email);
        }

        return $transmission;
    }

    private function applyFrom(Transmission $transmission, Email $email, Envelope $envelope): void
    {
        // Prefer the message's own From. Fall back to the envelope sender, which Symfony
        // will have synthesised if it had to.
        $from = $email->getFrom()[0] ?? $envelope->getSender();

        $transmission->from($from->getAddress(), $from->getName());
    }

    private function applyAddresses(Transmission $transmission, Email $email): void
    {
        foreach ($email->getTo() as $address) {
            $transmission->to($address->getAddress(), $address->getName());
        }

        foreach ($email->getCc() as $address) {
            $transmission->cc($address->getAddress(), $address->getName());
        }

        foreach ($email->getBcc() as $address) {
            $transmission->bcc($address->getAddress(), $address->getName());
        }

        foreach ($email->getReplyTo() as $address) {
            $transmission->replyTo($address->getAddress(), $address->getName());
        }
    }

    private function applyBody(Transmission $transmission, Email $email): void
    {
        $subject = $email->getSubject();

        if ($subject !== null) {
            $transmission->subject($subject);
        }

        $text = self::bodyToString($email->getTextBody());

        if ($text !== null) {
            $transmission->text($text);
        }

        $html = self::bodyToString($email->getHtmlBody());

        if ($html !== null) {
            $transmission->html($html);
        }
    }

    /**
     * Symfony will hand back a stream rather than a string when the body was set from one.
     */
    private static function bodyToString(mixed $body): ?string
    {
        if (is_string($body)) {
            return $body;
        }

        if (is_resource($body)) {
            $contents = stream_get_contents($body);

            return $contents === false ? null : $contents;
        }

        return null;
    }

    private function applyHeaders(Transmission $transmission, Email $email): void
    {
        foreach ($email->getHeaders()->all() as $header) {
            if (! $header instanceof HeaderInterface) {
                continue;
            }

            // The builder drops the headers SparkPost derives for itself, so everything
            // can be offered to it and that one list decides.
            $transmission->header($header->getName(), $header->getBodyAsString());
        }
    }

    private function applyAttachments(Transmission $transmission, Email $email): void
    {
        foreach ($email->getAttachments() as $part) {
            $transmission->attach($this->convertAttachment($part));
        }
    }

    private function convertAttachment(DataPart $part): Attachment
    {
        // Read everything off the prepared headers rather than the part's own getters.
        // getDisposition(), getName() and getFilename() were all added after Symfony 5.4,
        // which is the version XenForo ships, and getPreparedHeaders() carries the same
        // facts in every version this package supports.
        $headers = $part->getPreparedHeaders();

        $type = $headers->get('Content-Type');
        $contentType = $type instanceof ParameterizedHeader ? $type->getValue() : 'application/octet-stream';

        $disposition = $headers->get('Content-Disposition');
        $filename = $disposition instanceof ParameterizedHeader ? $disposition->getParameter('filename') : '';
        $inline = $disposition instanceof ParameterizedHeader && $disposition->getValue() === 'inline';

        if ($inline) {
            // Symfony rewrites `cid:<name>` into `cid:<generated id>` when it renders the
            // MIME message - and it never renders one here, because we send the HTML body
            // as it stands. So the image has to be named the way that HTML still refers to
            // it, which is the filename the author embedded it under, not a generated id.
            return Attachment::inline(
                $filename !== '' ? $filename : $part->getContentId(),
                $contentType,
                $part->getBody()
            );
        }

        return Attachment::fromData($filename, $contentType, $part->getBody());
    }

    private function applySparkPostFields(Transmission $transmission, SparkPostEmail $email): void
    {
        if ($email->getCampaignId() !== null) {
            $transmission->campaignId($email->getCampaignId());
        }

        if ($email->getDescription() !== null) {
            $transmission->description($email->getDescription());
        }

        if ($email->getSparkPostReturnPath() !== null) {
            $transmission->returnPath($email->getSparkPostReturnPath());
        }

        if ($email->isTransactional() !== null) {
            $transmission->transactional($email->isTransactional());
        }

        if ($email->isOpenTracking() !== null) {
            $transmission->openTracking($email->isOpenTracking());
        }

        if ($email->isClickTracking() !== null) {
            $transmission->clickTracking($email->isClickTracking());
        }

        if ($email->isSandbox() !== null) {
            $transmission->sandbox($email->isSandbox());
        }

        foreach ($email->getOptions() as $key => $value) {
            $transmission->option($key, $value);
        }

        if ($email->getMetadata() !== []) {
            $transmission->metadata($email->getMetadata());
        }

        if ($email->getSubstitutionData() !== []) {
            $transmission->substitutionData($email->getSubstitutionData());
        }

        // Last, because it replaces the content built from the MIME message entirely.
        if ($email->getContent() !== null) {
            $transmission->content($email->getContent());
        }
    }
}
