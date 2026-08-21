<?php

/**
 * Exercise: a message with everything the converter has to take apart.
 *
 * send.php proves a plain message reaches SparkPost. This one covers the parts with the
 * most logic behind them, none of which a stub client can settle - what a person sees in
 * their inbox is the only verdict:
 *
 *   Cc and Bcc      SparkPost sends one message per recipient, so a naive transport gives
 *                   everyone a To: line containing only themselves and loses Cc entirely.
 *                   Every recipient should see the same To: line, Cc should be visible,
 *                   and Bcc should appear nowhere in the delivered headers.
 *
 *   inline image    Named by filename, not content id. Symfony rewrites cid:<name> into
 *                   cid:<generated id> when it renders a MIME message and never renders
 *                   one here, so the HTML still refers to the image by the name it was
 *                   embedded under. If that is wrong the image is a broken box.
 *
 *   attachment      Content type and filename are read off the prepared headers rather
 *                   than DataPart::getFilename(), which does not exist in Symfony 5.4.
 *
 * Recipients default to plus-addressed variants of SPARKPOST_TO so one inbox receives all
 * three; set SPARKPOST_CC and SPARKPOST_BCC to use real separate addresses.
 *
 * Needs SPARKPOST_API_KEY, SPARKPOST_TO, SPARKPOST_FROM. Without SPARKPOST_DELIVER=1 it
 * goes to the sink, which proves the payload builds and delivers nothing - so it answers
 * none of the questions above.
 *
 * @var Hampel\Rig\Io $io
 */

use GuzzleHttp\Client;
use GuzzleHttp\Psr7\HttpFactory;
use Hampel\SparkPost\Config;
use Hampel\SparkPost\SparkPost;
use Hampel\SparkPost\Transport\EmailConverter;
use Hampel\SparkPost\Transport\EventListener\SinkEnvelopeListener;
use Hampel\SparkPost\Transport\Mime\SparkPostEmail;
use Hampel\SparkPost\Transport\SparkPostTransport;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mime\Address;

$io->title('sparkpost-transport · rich send');

$key = getenv('SPARKPOST_API_KEY');
$to = getenv('SPARKPOST_TO');
$from = getenv('SPARKPOST_FROM');

if ($key === false || $key === '' || $to === false || $to === '' || $from === false || $from === '') {
    $io->error('SPARKPOST_API_KEY, SPARKPOST_TO and SPARKPOST_FROM are all needed.');
    $io->info('  Copy .env.example to .env beside the package.');

    exit(1);
}

/**
 * alice@example.com becomes alice+cc@example.com. Most providers deliver plus-addressed
 * mail to the same mailbox, which is what makes one inbox enough to read all three roles.
 */
$plus = static function (string $address, string $tag) use ($io): string {
    $at = strrpos($address, '@');

    if ($at === false) {
        $io->warn(sprintf('SPARKPOST_TO (%s) has no @, using it unchanged.', $address));

        return $address;
    }

    return substr($address, 0, $at) . '+' . $tag . substr($address, $at);
};

$cc = getenv('SPARKPOST_CC') ?: $plus($to, 'cc');
$bcc = getenv('SPARKPOST_BCC') ?: $plus($to, 'bcc');

// Exactly '1' - see send.php.
$deliver = getenv('SPARKPOST_DELIVER') === '1';

$factory = new HttpFactory();
$sparkpost = new SparkPost(Config::forRegion($key, getenv('SPARKPOST_REGION') ?: null), new Client(), $factory, $factory);

$dispatcher = new EventDispatcher();

if (! $deliver) {
    $dispatcher->addSubscriber(new SinkEnvelopeListener());
}

$transport = new SparkPostTransport($sparkpost, $dispatcher);

$io->value('to', $to);
$io->value('cc', $cc);
$io->value('bcc', $bcc);
$io->value('mode', $deliver ? 'DELIVER - these go to real addresses' : 'sink - nothing will be delivered');
$io->line();

// A 160x80 PNG, checkerboard with a border, so a broken reference is obvious at a glance.
$logo = base64_decode(
    ''
    . 'iVBORw0KGgoAAAANSUhEUgAAAKAAAABQCAIAAAARP+ljAAAA50lEQVR42u3bMQ6AIBBFQW7iEb0uta2JoaCg9AQE'
    . 'SCxwM8mvX7FTiuk4bwu85ASADbD9ArjUNly+nqXNNPW/7QMGDAAwAMAAAOsD1gesDxgwYMAAAAPYGdhBY/QBAwYA'
    . 'GABgAID1AesD1gcMGDBgAIABANYHrA9YHzBgH/y96AAAGABgAID1AesD1gcM2IEAAwAMALA+YH3A+oABA/Z/sIN6'
    . '0QEAsD5gfcD6gAEDBgwAMADAAADrA9YHrA8YsAMBBuD/YGBedOgD1gesDxgwYMAAAAMADACwPmB9wIB7wBZvgAEb'
    . 'YNt2Lw5mmkEUC0OaAAAAAElFTkSuQmCC'
);

$email = (new SparkPostEmail())
    ->setCampaignId('rig-rich')
    ->setTransactional()
    ->setOpenTracking(false)
    ->setClickTracking(false)
    ->setMetadata(['source' => 'rig', 'exercise' => 'rich']);

$email
    ->from(new Address($from, 'Rig'))
    ->to(new Address($to, 'Primary Recipient'))
    ->cc(new Address($cc, 'Carbon Copy'))
    ->bcc(new Address($bcc, 'Blind Copy'))
    ->subject('hampel/sparkpost-transport · rich send')
    ->text("Sent by vendor/bin/rig rich.\n\nThe HTML part carries an inline image; a PDF is attached.")
    ->html(
        '<p>Sent by <code>vendor/bin/rig rich</code>.</p>'
        // cid:rig-logo.png - the name it is embedded under, because nothing renders the
        // MIME message here to rewrite it into a generated content id.
        . '<p><img src="cid:rig-logo.png" alt="If you see a broken image, inline naming is wrong." /></p>'
        . '<p>A PDF should be attached.</p>'
    );

$email->embed($logo, 'rig-logo.png', 'image/png');
$email->attach(pdf('hampel/sparkpost-transport'), 'rig-report.pdf', 'application/pdf');

// What the converter built, before it goes. The three things worth seeing are that every
// recipient carries the same header_to, that CC is a content header rather than a
// recipient-level one, and that bcc appears only as a delivery address.
$payload = (new EmailConverter())->convert($email, Envelope::create($email))->toArray();

$recipients = $payload['recipients'] ?? [];

$io->value('delivered to', array_map(static fn (array $r): string => $r['address']['email'] ?? '?', $recipients));
$io->value('header_to', array_values(array_unique(array_map(
    static fn (array $r): string => $r['address']['header_to'] ?? '(none)',
    $recipients
))));
$io->value('content.headers', $payload['content']['headers'] ?? '(none)');
$io->value('attachments', array_map(
    static fn (array $a): string => sprintf('%s (%s)', $a['name'] ?? '?', $a['type'] ?? '?'),
    $payload['content']['attachments'] ?? []
));
$io->value('inline_images', array_map(
    static fn (array $a): string => sprintf('%s (%s)', $a['name'] ?? '?', $a['type'] ?? '?'),
    $payload['content']['inline_images'] ?? []
));
$io->line();

if (str_contains(json_encode($payload['content']['headers'] ?? []) ?: '', $bcc)) {
    $io->error('✗ The Bcc address is in the content headers. That is not blind.');

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
    $io->warn('Sink: the payload above is real, but nothing was delivered, so none of the');
    $io->warn('questions this exercise exists to ask are answered. Run it with');
    $io->warn('SPARKPOST_DELIVER=1 and read the three messages.');

    return;
}

$io->line();
$io->info('Three messages should arrive. In each of them:');
$io->line('  To:     names the primary recipient only, and is the same in all three');
$io->line('  Cc:     names the cc address, and is present in all three');
$io->line('  Bcc:    absent - if the bcc recipient can see their own address, it is not blind');
$io->line('  body:   the image renders inline rather than as a broken box or an attachment');
$io->line('  files:  rig-report.pdf attached, and opening it shows one line of text');

/**
 * A one-page PDF, built here so the exercise carries no binary fixture. Offsets in the
 * xref table are byte positions into the finished file, so they are measured rather than
 * written down.
 */
function pdf(string $text): string
{
    $content = sprintf("BT /F1 14 Tf 24 64 Td (%s) Tj ET", str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $text));

    $objects = [
        '<</Type/Catalog/Pages 2 0 R>>',
        '<</Type/Pages/Kids[3 0 R]/Count 1>>',
        '<</Type/Page/Parent 2 0 R/MediaBox[0 0 320 120]/Contents 4 0 R/Resources<</Font<</F1 5 0 R>>>>>>',
        sprintf("<</Length %d>>stream\n%s\nendstream", strlen($content), $content),
        '<</Type/Font/Subtype/Type1/BaseFont/Helvetica>>',
    ];

    $pdf = "%PDF-1.4\n";
    $offsets = [];

    foreach ($objects as $i => $object) {
        $offsets[] = strlen($pdf);
        $pdf .= sprintf("%d 0 obj%s endobj\n", $i + 1, $object);
    }

    $xref = strlen($pdf);
    $pdf .= sprintf("xref\n0 %d\n0000000000 65535 f \n", count($objects) + 1);

    foreach ($offsets as $offset) {
        $pdf .= sprintf("%010d 00000 n \n", $offset);
    }

    $pdf .= sprintf("trailer<</Size %d/Root 1 0 R>>\nstartxref\n%d\n%%%%EOF\n", count($objects) + 1, $xref);

    return $pdf;
}
