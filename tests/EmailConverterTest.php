<?php

declare(strict_types=1);

namespace Hampel\SparkPost\Transport\Tests;

use Hampel\SparkPost\Transport\EmailConverter;
use Hampel\SparkPost\Transport\Mime\SparkPostEmail;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

final class EmailConverterTest extends TestCase
{
    /**
     * @return array<mixed>
     */
    private function convert(Email $email, ?Envelope $envelope = null): array
    {
        return (new EmailConverter())->convert($email, $envelope ?? Envelope::create($email))->toArray();
    }

    private function email(): Email
    {
        return (new Email())
            ->from(new Address('webmaster@example.com', 'Webmaster'))
            ->to(new Address('alice@example.com', 'Alice'))
            ->subject('Hello')
            ->text('Body.');
    }

    public function test_it_carries_the_basics_across(): void
    {
        $payload = $this->convert($this->email()->html('<p>Body.</p>'));

        $this->assertSame(['email' => 'webmaster@example.com', 'name' => 'Webmaster'], self::path($payload, 'content.from'));
        $this->assertSame('Hello', self::path($payload, 'content.subject'));
        $this->assertSame('Body.', self::path($payload, 'content.text'));
        $this->assertSame('<p>Body.</p>', self::path($payload, 'content.html'));
    }

    public function test_from_falls_back_to_the_envelope_sender(): void
    {
        $email = (new Email())->to('alice@example.com')->subject('Hi')->text('Body.');
        $envelope = new Envelope(new Address('sender@example.com'), [new Address('alice@example.com')]);

        $this->assertSame(['email' => 'sender@example.com'], self::path($this->convert($email, $envelope), 'content.from'));
    }

    public function test_to_cc_and_bcc_become_recipients_with_a_shared_to_line(): void
    {
        $payload = $this->convert(
            $this->email()
                ->addTo(new Address('amy@example.com'))
                ->addCc(new Address('bob@example.com', 'Bob'))
                ->addBcc(new Address('carol@example.com'))
        );

        $recipients = self::arrayAt($payload, 'recipients');

        $this->assertCount(4, $recipients);

        foreach ($recipients as $recipient) {
            $this->assertSame(
                'Alice <alice@example.com>, amy@example.com',
                self::path(is_array($recipient) ? $recipient : null, 'address.header_to')
            );
        }

        // Cc is visible because of the header; Bcc gets none, which is what makes it blind
        $this->assertSame('Bob <bob@example.com>', self::path($payload, 'content.headers.CC'));
        $this->assertStringNotContainsString('carol', (string) json_encode(self::arrayAt($payload, 'content.headers')));
    }

    /**
     * The reason delivery and display are kept apart. A listener rewrites the envelope -
     * the sink listener does exactly this - and the message must go to the new address
     * while still reading as though it were addressed to the original.
     */
    public function test_an_envelope_override_redirects_delivery_without_touching_the_headers(): void
    {
        $email = $this->email()->addCc(new Address('bob@example.com', 'Bob'));

        $envelope = new Envelope(
            new Address('webmaster@example.com'),
            [new Address('alice@example.com.sink.sparkpostmail.com'), new Address('bob@example.com.sink.sparkpostmail.com')]
        );

        $payload = $this->convert($email, $envelope);

        $this->assertSame(
            ['alice@example.com.sink.sparkpostmail.com', 'bob@example.com.sink.sparkpostmail.com'],
            array_map(
                static fn (mixed $r): mixed => self::path(is_array($r) ? $r : null, 'address.email'),
                array_values(self::arrayAt($payload, 'recipients'))
            )
        );

        // the delivered message still reads normally
        $this->assertSame('Alice <alice@example.com>', self::path($payload, 'recipients.0.address.header_to'));
        $this->assertSame('Bob <bob@example.com>', self::path($payload, 'content.headers.CC'));
    }

    public function test_reply_to_is_a_formatted_list(): void
    {
        $payload = $this->convert(
            $this->email()->replyTo(new Address('reply@example.com', 'Reply Desk'), new Address('second@example.com'))
        );

        $this->assertSame('Reply Desk <reply@example.com>, second@example.com', self::path($payload, 'content.reply_to'));
    }

    public function test_headers_symfony_derives_are_not_forwarded(): void
    {
        $email = $this->email();
        $email->getHeaders()->addTextHeader('X-Campaign', 'spring');

        $headers = self::arrayAt($this->convert($email), 'content.headers');

        $this->assertSame('spring', $headers['X-Campaign'] ?? null);

        foreach (['Subject', 'From', 'To', 'Date', 'Message-ID', 'MIME-Version'] as $derived) {
            $this->assertArrayNotHasKey($derived, $headers);
        }
    }

    public function test_an_attachment_becomes_one(): void
    {
        // attach() and embed() are the two spellings present in every Symfony this
        // package supports - addPart() arrived in 6.2 and attachPart() went in 7.0.
        $payload = $this->convert($this->email()->attach('INVOICE', 'invoice.pdf', 'application/pdf'));

        $this->assertSame(
            [['name' => 'invoice.pdf', 'type' => 'application/pdf', 'data' => base64_encode('INVOICE')]],
            self::path($payload, 'content.attachments')
        );
    }

    public function test_an_embedded_image_becomes_an_inline_image_named_by_its_content_id(): void
    {
        $email = $this->email()
            ->html('<p><img src="cid:logo"></p>')
            ->embed('PNGBYTES', 'logo', 'image/png');

        $payload = $this->convert($email);

        $this->assertNull(self::path($payload, 'content.attachments'));

        // named by the filename the HTML still refers to, not a generated content id
        $this->assertSame(
            [['name' => 'logo', 'type' => 'image/png', 'data' => base64_encode('PNGBYTES')]],
            self::path($payload, 'content.inline_images')
        );

        // and the body we send still says cid:logo, because no MIME was ever rendered
        $this->assertIsString($html = self::path($payload, 'content.html'));
        $this->assertStringContainsString('cid:logo', $html);
    }

    public function test_it_applies_the_sparkpost_specific_fields(): void
    {
        $email = (new SparkPostEmail())
            ->setCampaignId('spring')
            ->setDescription('Spring campaign')
            ->setSparkPostReturnPath('bounces@example.com')
            ->setTransactional()
            ->setOpenTracking(false)
            ->setClickTracking(false)
            ->setSandbox()
            ->setMetadata(['user_id' => 7])
            ->setSubstitutionData(['first_name' => 'Alice'])
            ->setOptions(['ip_pool' => 'marketing']);

        $email->from('webmaster@example.com')->to('alice@example.com')->subject('Hi')->text('Body.');

        $payload = $this->convert($email);

        $this->assertSame('spring', self::path($payload, 'campaign_id'));
        $this->assertSame('Spring campaign', self::path($payload, 'description'));
        $this->assertSame('bounces@example.com', self::path($payload, 'return_path'));
        $this->assertSame(['user_id' => 7], self::path($payload, 'metadata'));
        $this->assertSame(['first_name' => 'Alice'], self::path($payload, 'substitution_data'));
        $this->assertSame(
            ['open_tracking' => false, 'click_tracking' => false, 'transactional' => true, 'sandbox' => true, 'ip_pool' => 'marketing'],
            self::path($payload, 'options')
        );
    }

    public function test_a_template_replaces_the_body_built_from_the_message(): void
    {
        $email = (new SparkPostEmail())->setTemplate('welcome');
        $email->from('webmaster@example.com')->to('alice@example.com')->subject('Ignored')->text('Ignored.');

        $payload = $this->convert($email);

        $this->assertSame(['template_id' => 'welcome', 'use_draft_template' => false], self::path($payload, 'content'));
        // the recipients still come from the message
        $this->assertSame('alice@example.com', self::path($payload, 'recipients.0.address.email'));
    }

    public function test_an_ab_test_does_the_same(): void
    {
        $email = (new SparkPostEmail())->setAbTest('subject-line');
        $email->from('webmaster@example.com')->to('alice@example.com')->subject('Ignored')->text('Ignored.');

        $this->assertSame(['ab_test_id' => 'subject-line'], self::path($this->convert($email), 'content'));
    }
}
