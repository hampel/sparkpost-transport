<?php

/**
 * Exercise: whether SparkPost renders To display names as templates. Reaches the live API.
 *
 * SparkPost runs its template language over the subject, the bodies, custom header values
 * and the From, Cc and Reply-To display names, whether or not substitution data is sent -
 * each measured through its content previewer. The To names are the one field the
 * previewer cannot reach: they travel per recipient, and it takes no recipients. So
 * EmailConverter leaves them unescaped, and whether that is right is what this settles.
 *
 * Four transmissions, each with one recipient, and the converter's escaping OFF so the
 * payload is exactly what the names below say:
 *
 *   bare       To name {{x.y}}, sent in header_to. Templated means a generation_failure
 *              event after the API has accepted the send. Not templated means an
 *              injection and a delivery, and the name arrives as written.
 *
 *   escaped    The same name through Transmission::escapeTemplateSyntax(). Generates
 *              either way. The delivered To: decides it: {{x.y}} means templated, and that
 *              escaping is the fix; visible macro text means not templated, and escaping
 *              To would corrupt it.
 *
 *   vanish     To name "Before {{x}} After". Templated renders "Before  After" - a quiet
 *              loss rather than a failure, and only visible in a delivered message.
 *
 *   no-to      Bcc only, name {{x.y}}. A control rather than a question: the converter
 *              always delivers to the envelope recipients, which Symfony strips of display
 *              names, so the payload should show no name at all. If one ever appears, a Bcc
 *              name has started reaching SparkPost and needs measuring in its own right.
 *
 * What a sink run answers: the failure half, from the message events - bare either fails
 * generation or it does not. What it cannot answer: vanish and escaped, which
 * need a delivered To: header to read. Run with SPARKPOST_DELIVER=1 for those.
 *
 * Needs SPARKPOST_API_KEY with "Message Events: Read" as well as "Transmissions:
 * Read/Write", plus SPARKPOST_TO and SPARKPOST_FROM. Sinks unless SPARKPOST_DELIVER=1, and
 * refuses to deliver from an agent session - see harness/lib/delivery.php.
 *
 * @var Hampel\Rig\Io $io
 */

use GuzzleHttp\Client;
use GuzzleHttp\Psr7\HttpFactory;
use Hampel\SparkPost\Config;
use Hampel\SparkPost\Exception\ApiException;
use Hampel\SparkPost\MessageEvent\EventQuery;
use Hampel\SparkPost\MessageEvent\EventType;
use Hampel\SparkPost\SparkPost;
use Hampel\SparkPost\Transmission\Transmission;
use Hampel\SparkPost\Transport\EmailConverter;
use Hampel\SparkPost\Transport\EventListener\SinkEnvelopeListener;
use Hampel\SparkPost\Transport\SparkPostTransport;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

$io->title('sparkpost-transport · are To names templated?');

$key = getenv('SPARKPOST_API_KEY');
$to = getenv('SPARKPOST_TO');
$from = getenv('SPARKPOST_FROM');

if ($key === false || $key === '' || $to === false || $to === '' || $from === false || $from === '') {
    $io->error('SPARKPOST_API_KEY, SPARKPOST_TO and SPARKPOST_FROM are all needed.');
    $io->info('  Copy .env.example to .env beside the package.');

    exit(1);
}

require __DIR__ . '/lib/delivery.php';

[$deliver, $mode] = rig_delivery();

$io->value('mode', $mode);
$io->line();

$factory = new HttpFactory();
$sparkpost = new SparkPost(Config::forRegion($key, getenv('SPARKPOST_REGION') ?: null), new Client(), $factory, $factory);

$dispatcher = new EventDispatcher();

if (! $deliver) {
    $dispatcher->addSubscriber(new SinkEnvelopeListener());
}

// Escaping off, deliberately and explicitly: the exercise asks what SparkPost does with a
// name as written, so the converter must not change its answer if its default ever does.
$converter = new EmailConverter([], false);
$transport = new SparkPostTransport($sparkpost, $dispatcher, null, $converter);

$cases = [
    'bare' => static fn (Email $e): Email => $e->to(new Address($to, '{{x.y}}')),
    'escaped' => static fn (Email $e): Email => $e->to(new Address($to, Transmission::escapeTemplateSyntax('{{x.y}}'))),
    'vanish' => static fn (Email $e): Email => $e->to(new Address($to, 'Before {{x}} After')),
    'no-to' => static fn (Email $e): Email => $e->bcc(new Address($to, '{{x.y}}')),
];

$started = new DateTimeImmutable('-5 minutes');
$sent = [];
$failed = false;

foreach ($cases as $case => $address) {
    $email = $address((new Email())
        ->from(new Address($from, 'Rig'))
        ->subject('hampel/sparkpost-transport · To templating · ' . $case)
        ->text("Sent by vendor/bin/rig templating, case: {$case}.\n\nRead the To: header of this message."));

    $recipient = $converter->convert($email, Envelope::create($email))->toArray()['recipients'][0]['address'] ?? [];

    try {
        $transmission = $transport->send($email)?->getMessageId();
    } catch (TransportExceptionInterface $e) {
        $io->error(sprintf('✗ %s: %s - %s', $case, $e::class, $e->getMessage()));
        $failed = true;

        continue;
    }

    $sent[(string) $transmission] = $case;

    $io->values([
        'case' => $case,
        'address' => json_encode($recipient, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        'transmission' => $transmission,
    ]);
    $io->line();
}

if ($sent === []) {
    exit(1);
}

// The API accepts a transmission before it renders one, so the answer is only in the
// message events. They lag: allow three minutes before giving up, and say that a timeout
// is a finding about this loop, not about SparkPost.
$terminal = ['generation_failure', 'generation_rejection', 'injection'];
$seen = array_fill_keys(array_keys($sent), []);
$details = [];
$deadline = time() + 180;

$io->info('Waiting for message events (up to 3 minutes)...');

try {
    do {
        sleep(15);

        $query = EventQuery::make()
            ->from($started)
            ->transmissionIds(...array_map('strval', array_keys($sent)))
            ->events(
                EventType::Injection,
                EventType::Delivery,
                EventType::GenerationFailure,
                EventType::GenerationRejection,
                EventType::PolicyRejection,
                EventType::Bounce,
            );

        foreach ($sparkpost->messageEvents()->each($query) as $event) {
            $id = (string) ($event['transmission_id'] ?? '');
            $type = (string) ($event['type'] ?? '?');

            if (! isset($seen[$id]) || in_array($type, $seen[$id], true)) {
                continue;
            }

            $seen[$id][] = $type;

            if (str_starts_with($type, 'generation_') || $type === 'policy_rejection' || $type === 'bounce') {
                $details[$id] = (string) ($event['raw_reason'] ?? $event['reason'] ?? '');
            }
        }

        $settled = array_filter($seen, static fn (array $types): bool => array_intersect($types, $terminal) !== []);
    } while (count($settled) < count($sent) && time() < $deadline);
} catch (ApiException $e) {
    $io->error('✗ message events: ' . $e::class . ' - ' . $e->getMessage());
    $io->info('  The key needs "Message Events: Read" as well.');

    exit(1);
}

$io->line();

foreach ($sent as $id => $case) {
    $types = $seen[$id];
    $verdict = match (true) {
        array_intersect($types, ['generation_failure', 'generation_rejection']) !== [] => 'TEMPLATED - generation failed',
        in_array('injection', $types, true) => 'generated',
        default => 'no answer yet - the loop timed out, not SparkPost',
    };

    $io->values(array_filter([
        'case' => $case,
        'events' => $types === [] ? '(none)' : implode(', ', $types),
        'verdict' => $verdict,
        'reason' => $details[$id] ?? null,
    ], static fn (mixed $v): bool => $v !== null));
    $io->line();
}

if (! $deliver) {
    $io->warn('Sink: bare is answered above. escaped and vanish are not - they need');
    $io->warn('a delivered To: header. Run with SPARKPOST_DELIVER=1 to read them.');
} else {
    $io->info('Read the To: header of each delivered message:');
    $io->line('  escaped:  {{x.y}} means To IS templated and escaping is the fix;');
    $io->line('            macro text such as {{ opening_double_curly() }} means it is NOT');
    $io->line('  vanish:   "Before  After" means templated; "Before {{x}} After" means not');
}

if ($failed) {
    exit(1);
}
