<?php

namespace App\Support\SwingResearch;

use App\Support\Swing\SwingIdeaRules;
use InvalidArgumentException;

/** Fixed research policies; customer rules and daily-close semantics stay shared. */
final class SwingResearchRules
{
    public const VERSION = 'swing-research.v1';

    public static function variants(): array
    {
        return [
            'baseline' => [
                'label' => 'Baseline',
                'description' => 'Current swing rules with the top three ranked entry walls.',
            ],
            'nearer_entry' => [
                'label' => 'Nearer entry',
                'description' => 'Select the nearest eligible wall from the top ten instead of three. The two ATR limit and all other gates stay the same.',
            ],
            'earlier_exit' => [
                'label' => 'Earlier exit',
                'description' => 'Use the same admitted plan and entry conditions, with a frozen exit halfway from the far entry edge to the original target.',
            ],
        ];
    }

    public function propose(array $input, string $horizon, string $variant): array
    {
        if (! array_key_exists($variant, self::variants())) {
            throw new InvalidArgumentException('Unknown swing research variant.');
        }

        $rules = new SwingIdeaRules;
        $plan = $variant === 'nearer_entry'
            ? $rules->proposeWithEntryCandidates($input, $horizon, 10)
            : $rules->propose($input, $horizon);

        if ($variant === 'earlier_exit' && $plan['status'] === 'watching') {
            $far = $plan['direction'] === 'bullish' ? $plan['levels']['entry_high'] : $plan['levels']['entry_low'];
            $plan['original_target'] = $plan['levels']['target'];
            $plan['levels']['target'] = $far + .5 * ($plan['original_target'] - $far);
        }

        return [...$plan, 'research_version' => self::VERSION, 'variant' => $variant];
    }

    public function advance(array $plan, array $bar): array
    {
        return (new SwingIdeaRules)->advance($plan, $bar);
    }
}
