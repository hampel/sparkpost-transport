<?php

declare(strict_types=1);

namespace Hampel\SparkPost\Transport\Tests;

use Hampel\SparkPost\Transport\Mime\SparkPostEmail;
use PHPUnit\Framework\TestCase;

final class SparkPostEmailTest extends TestCase
{
    private function email(): SparkPostEmail
    {
        $email = new SparkPostEmail();
        $email->from('webmaster@example.com')->to('alice@example.com')->subject('Hello')->text('Body.');

        return $email;
    }

    /**
     * The failure this guards against is silent: a message queued through Symfony
     * Messenger is serialised, and anything the round trip drops simply is not there when
     * the worker sends it. The implementation this replaces used a positional array, so
     * adding a property without touching both methods lost it.
     */
    public function test_every_sparkpost_field_survives_a_round_trip(): void
    {
        $email = $this->email()
            ->setCampaignId('spring')
            ->setDescription('Spring campaign')
            ->setSparkPostReturnPath('bounces@example.com')
            ->setTransactional()
            ->setOpenTracking(false)
            ->setClickTracking(false)
            ->setSandbox()
            ->setOptions(['ip_pool' => 'marketing'])
            ->setMetadata(['user_id' => 7])
            ->setSubstitutionData(['first_name' => 'Alice']);

        $restored = unserialize(serialize($email));

        $this->assertInstanceOf(SparkPostEmail::class, $restored);
        $this->assertSame('spring', $restored->getCampaignId());
        $this->assertSame('Spring campaign', $restored->getDescription());
        $this->assertSame('bounces@example.com', $restored->getSparkPostReturnPath());
        $this->assertTrue($restored->isTransactional());
        $this->assertFalse($restored->isOpenTracking());
        $this->assertFalse($restored->isClickTracking());
        $this->assertTrue($restored->isSandbox());
        $this->assertSame(['ip_pool' => 'marketing'], $restored->getOptions());
        $this->assertSame(['user_id' => 7], $restored->getMetadata());
        $this->assertSame(['first_name' => 'Alice'], $restored->getSubstitutionData());
    }

    public function test_the_message_itself_survives_the_round_trip(): void
    {
        $restored = unserialize(serialize($this->email()->setCampaignId('spring')));

        $this->assertInstanceOf(SparkPostEmail::class, $restored);
        $this->assertSame('Hello', $restored->getSubject());
        $this->assertSame('Body.', $restored->getTextBody());
        $this->assertSame('alice@example.com', $restored->getTo()[0]->getAddress());
    }

    public function test_a_template_survives_the_round_trip(): void
    {
        $restored = unserialize(serialize($this->email()->setTemplate('welcome', true)));

        $this->assertInstanceOf(SparkPostEmail::class, $restored);
        $this->assertSame(['template_id' => 'welcome', 'use_draft_template' => true], $restored->getContent());
    }

    /**
     * An associative payload means a message queued before a property existed still
     * unserialises - it just comes back with that property unset.
     */
    public function test_an_unset_field_round_trips_as_unset_rather_than_as_false(): void
    {
        $restored = unserialize(serialize($this->email()));

        $this->assertInstanceOf(SparkPostEmail::class, $restored);
        $this->assertNull($restored->isTransactional());
        $this->assertNull($restored->getCampaignId());
        $this->assertSame([], $restored->getMetadata());
    }

    public function test_metadata_and_substitution_data_can_be_added_one_at_a_time(): void
    {
        $email = $this->email()->addMetadata('user_id', 7)->addSubstitutionData('first_name', 'Alice');

        $this->assertSame(['user_id' => 7], $email->getMetadata());
        $this->assertSame(['first_name' => 'Alice'], $email->getSubstitutionData());
    }
}
