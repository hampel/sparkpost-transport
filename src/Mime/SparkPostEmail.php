<?php

declare(strict_types=1);

namespace Hampel\SparkPost\Transport\Mime;

use Symfony\Component\Mime\Email;

/**
 * A Symfony Email carrying the SparkPost-specific fields that have no MIME equivalent.
 *
 * Campaigns, metadata and substitution data are transmission-level concepts - they never
 * appear in the message itself - so there is nowhere in a plain Email to put them.
 */
class SparkPostEmail extends Email
{
    private ?string $campaignId = null;

    private ?string $description = null;

    private ?string $returnPath = null;

    private ?bool $transactional = null;

    private ?bool $openTracking = null;

    private ?bool $clickTracking = null;

    private ?bool $sandbox = null;

    /** @var array<string, mixed> */
    private array $options = [];

    /** @var array<string, mixed> */
    private array $metadata = [];

    /** @var array<string, mixed> */
    private array $substitutionData = [];

    /** @var array<string, mixed>|null */
    private ?array $content = null;

    public function getCampaignId(): ?string
    {
        return $this->campaignId;
    }

    public function setCampaignId(?string $campaignId): static
    {
        $this->campaignId = $campaignId;

        return $this;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $description): static
    {
        $this->description = $description;

        return $this;
    }

    public function getSparkPostReturnPath(): ?string
    {
        return $this->returnPath;
    }

    /**
     * Named for SparkPost rather than matching Email::returnPath(), which sets the MIME
     * header. This is the transmission's return_path, which is the bounce address
     * SparkPost actually uses.
     */
    public function setSparkPostReturnPath(?string $returnPath): static
    {
        $this->returnPath = $returnPath;

        return $this;
    }

    public function isTransactional(): ?bool
    {
        return $this->transactional;
    }

    public function setTransactional(?bool $transactional = true): static
    {
        $this->transactional = $transactional;

        return $this;
    }

    public function isOpenTracking(): ?bool
    {
        return $this->openTracking;
    }

    public function setOpenTracking(?bool $openTracking = true): static
    {
        $this->openTracking = $openTracking;

        return $this;
    }

    public function isClickTracking(): ?bool
    {
        return $this->clickTracking;
    }

    public function setClickTracking(?bool $clickTracking = true): static
    {
        $this->clickTracking = $clickTracking;

        return $this;
    }

    public function isSandbox(): ?bool
    {
        return $this->sandbox;
    }

    public function setSandbox(?bool $sandbox = true): static
    {
        $this->sandbox = $sandbox;

        return $this;
    }

    /**
     * @return array<string, mixed>
     */
    public function getOptions(): array
    {
        return $this->options;
    }

    /**
     * Any transmission option without a setter of its own - ip_pool, start_time.
     *
     * @param  array<string, mixed>  $options
     */
    public function setOptions(array $options): static
    {
        $this->options = $options;

        return $this;
    }

    /**
     * @return array<string, mixed>
     */
    public function getMetadata(): array
    {
        return $this->metadata;
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    public function setMetadata(array $metadata): static
    {
        $this->metadata = $metadata;

        return $this;
    }

    public function addMetadata(string $key, mixed $value): static
    {
        $this->metadata[$key] = $value;

        return $this;
    }

    /**
     * @return array<string, mixed>
     */
    public function getSubstitutionData(): array
    {
        return $this->substitutionData;
    }

    /**
     * @param  array<string, mixed>  $substitutionData
     */
    public function setSubstitutionData(array $substitutionData): static
    {
        $this->substitutionData = $substitutionData;

        return $this;
    }

    public function addSubstitutionData(string $key, mixed $value): static
    {
        $this->substitutionData[$key] = $value;

        return $this;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getContent(): ?array
    {
        return $this->content;
    }

    /**
     * Replace the message body with a content block of your own.
     *
     * Everything MIME - subject, body, attachments - is ignored once this is set, because
     * SparkPost takes the content from here instead. The message still needs a From and a
     * To: the envelope is built from them.
     *
     * @param  array<string, mixed>|null  $content
     */
    public function setContent(?array $content): static
    {
        $this->content = $content;

        return $this;
    }

    public function setTemplate(string $templateId, bool $useDraft = false): static
    {
        return $this->setContent(['template_id' => $templateId, 'use_draft_template' => $useDraft]);
    }

    public function setAbTest(string $abTestId): static
    {
        return $this->setContent(['ab_test_id' => $abTestId]);
    }

    /**
     * Serialisation for Symfony Messenger, which is how a queued message survives.
     *
     * Deliberately an associative array rather than the positional one this replaces: a
     * positional list has to be kept in lockstep with __unserialize(), and a property
     * left out of either vanishes silently the next time a message is queued. With keys,
     * adding a property is one line and an old payload still unserialises.
     *
     * @return array<mixed>
     */
    public function __serialize(): array
    {
        return [
            [
                'campaign_id' => $this->campaignId,
                'description' => $this->description,
                'return_path' => $this->returnPath,
                'transactional' => $this->transactional,
                'open_tracking' => $this->openTracking,
                'click_tracking' => $this->clickTracking,
                'sandbox' => $this->sandbox,
                'options' => $this->options,
                'metadata' => $this->metadata,
                'substitution_data' => $this->substitutionData,
                'content' => $this->content,
            ],
            parent::__serialize(),
        ];
    }

    /**
     * @param  array<mixed>  $data
     */
    public function __unserialize(array $data): void
    {
        [$sparkpost, $parent] = $data;

        $sparkpost = is_array($sparkpost) ? $sparkpost : [];

        $this->campaignId = self::stringOrNull($sparkpost['campaign_id'] ?? null);
        $this->description = self::stringOrNull($sparkpost['description'] ?? null);
        $this->returnPath = self::stringOrNull($sparkpost['return_path'] ?? null);
        $this->transactional = self::boolOrNull($sparkpost['transactional'] ?? null);
        $this->openTracking = self::boolOrNull($sparkpost['open_tracking'] ?? null);
        $this->clickTracking = self::boolOrNull($sparkpost['click_tracking'] ?? null);
        $this->sandbox = self::boolOrNull($sparkpost['sandbox'] ?? null);
        $this->options = self::arrayOr($sparkpost['options'] ?? null);
        $this->metadata = self::arrayOr($sparkpost['metadata'] ?? null);
        $this->substitutionData = self::arrayOr($sparkpost['substitution_data'] ?? null);
        $this->content = isset($sparkpost['content']) && is_array($sparkpost['content'])
            ? self::arrayOr($sparkpost['content'])
            : null;

        parent::__unserialize(is_array($parent) ? $parent : []);
    }

    private static function stringOrNull(mixed $value): ?string
    {
        return is_string($value) ? $value : null;
    }

    private static function boolOrNull(mixed $value): ?bool
    {
        return is_bool($value) ? $value : null;
    }

    /**
     * @return array<string, mixed>
     */
    private static function arrayOr(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $result = [];

        foreach ($value as $key => $item) {
            $result[(string) $key] = $item;
        }

        return $result;
    }
}
