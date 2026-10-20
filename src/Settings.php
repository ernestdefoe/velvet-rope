<?php

/*
 * This file is part of ernestdefoe/guest-gate.
 */

namespace ErnestDefoe\GuestGate;

use Flarum\Settings\SettingsRepositoryInterface;

/**
 * The extension's settings, read once per request and normalised: anything an
 * admin (or a hand-edited database row) stored out of range falls back to a
 * safe value instead of reaching the gate.
 */
class Settings
{
    public const PREFIX = 'ernestdefoe-guest-gate.';

    public const PERIODS = ['day' => 86400, 'week' => 604800, 'month' => 2592000];

    public const KINDS = ['image', 'link', 'file', 'embed'];

    /** @var array{0: string, 1: list<Rule>}|null */
    private ?array $rules = null;

    public function __construct(
        protected SettingsRepositoryInterface $settings
    ) {
    }

    public function meterEnabled(): bool
    {
        return (bool) $this->settings->get(self::PREFIX.'meter_enabled');
    }

    public function meterLimit(): int
    {
        return self::clamp($this->settings->get(self::PREFIX.'meter_limit'), 1, 100, 5);
    }

    public function meterPeriod(): int
    {
        return self::PERIODS[(string) $this->settings->get(self::PREFIX.'meter_period')] ?? self::PERIODS['month'];
    }

    /** Show "n left" once this many free discussions or fewer remain; 0 never. */
    public function meterWarn(): int
    {
        return self::clamp($this->settings->get(self::PREFIX.'meter_warn'), 0, 100, 2);
    }

    /** Characters of the first post shown once the meter has run out. */
    public function meterLeadIn(): int
    {
        return self::clamp($this->settings->get(self::PREFIX.'meter_lead_in'), 0, 2000, 280);
    }

    /** @return list<int> */
    public function meterExemptTags(): array
    {
        return self::ids($this->settings->get(self::PREFIX.'meter_exempt_tags'));
    }

    /** Also count by network address, for visitors who clear or refuse cookies. */
    public function meterByAddress(): bool
    {
        return (bool) $this->settings->get(self::PREFIX.'meter_by_ip');
    }

    public function crawlers(): bool
    {
        return (bool) $this->settings->get(self::PREFIX.'crawlers');
    }

    public function secret(): string
    {
        return (string) $this->settings->get(self::PREFIX.'secret');
    }

    /**
     * The rules, in the admin's order: a discussion takes the first rule whose
     * tags it has, or that names no tags.
     *
     * @return list<Rule>
     */
    public function rules(): array
    {
        $raw = (string) $this->settings->get(self::PREFIX.'rules');

        if ($this->rules !== null && $this->rules[0] === $raw) {
            return $this->rules[1];
        }

        $decoded = json_decode($raw, true);
        $rules = [];

        foreach (is_array($decoded) ? array_slice($decoded, 0, 50) : [] as $rule) {
            if (is_array($rule)) {
                $rules[] = Rule::fromArray($rule);
            }
        }

        $this->rules = [$raw, $rules];

        return $rules;
    }

    /** Whether any decision depends on a discussion's tags. */
    public function needsTags(): bool
    {
        if ($this->meterEnabled() && $this->meterExemptTags() !== []) {
            return true;
        }

        foreach ($this->rules() as $rule) {
            if ($rule->tags !== []) {
                return true;
            }
        }

        return false;
    }

    /** @param list<int> $tagIds */
    public function ruleFor(array $tagIds): ?Rule
    {
        foreach ($this->rules() as $rule) {
            if ($rule->tags === [] || array_intersect($rule->tags, $tagIds) !== []) {
                return $rule;
            }
        }

        return null;
    }

    public static function clamp(mixed $value, int $min, int $max, int $default): int
    {
        if (! is_numeric($value)) {
            return $default;
        }

        return max($min, min($max, (int) $value));
    }

    /** @return list<int> */
    public static function ids(mixed $value): array
    {
        if (is_string($value)) {
            $value = json_decode($value, true);
        }

        if (! is_array($value)) {
            return [];
        }

        $ids = [];

        foreach (array_slice($value, 0, 500) as $id) {
            if (is_numeric($id) && (int) $id > 0) {
                $ids[(int) $id] = (int) $id;
            }
        }

        return array_values($ids);
    }
}
