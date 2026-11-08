<?php

namespace App\Support;

/** Customer ideas are recorded conditions and price levels. Outcome research stays in storage. */
final class CustomerIdeaPresentation
{
    public const VERSION = 'ideas.v1';

    public static function watchlist(array $data): array
    {
        $data['presentation'] = self::VERSION;
        foreach ($data['items'] ?? [] as $index => $row) {
            if (is_array($row['setup'] ?? null)) {
                $row['setup'] = self::setupLevels($row['setup'], true);
            }
            if (in_array($row['next_condition']['code'] ?? null,
                ['target_reached', 'invalidated', 'entry_missed', 'expired', 'awaiting_update'], true)) {
                $row['next_condition'] = ['version' => 'wall-next-condition.v1', 'setup_status' => 'watch',
                    'code' => 'price_sequence', 'text' => 'Watch for a completed price sequence at this wall.',
                    'completed_closes' => null, 'required_closes' => null];
            }
            $data['items'][$index] = $row;
        }
        $currentOrRecorded = array_values(array_filter($data['lifecycle_items'] ?? [], fn (array $row): bool => ($row['qualified'] ?? false) === true || in_array($row['status_group'] ?? null, ['watching', 'conditions_met'], true)));
        $data['lifecycle_items'] = array_map(self::ideaRow(...), $currentOrRecorded);
        $data['alert_history'] = array_map(function (array $row): array {
            if (is_array($row['lifecycle'] ?? null)) {
                $row['lifecycle'] = self::ideaRow($row['lifecycle']);
            }

            return $row;
        }, $data['alert_history'] ?? []);

        return $data;
    }

    public static function swing(array $original, array $state, array $extra = []): array
    {
        $horizon = $original['horizon'] ?? 'short';
        $profile = \App\Support\Swing\SwingIdeaRules::HORIZONS[$horizon];
        $activation = $original['activation_session'] ?? $original['plan_session']
            ?? \App\Support\Swing\SwingIdeaRules::sessionAfter($original['source_date'], 1);
        $qualified = is_string($state['qualified_at'] ?? null)
            && preg_match('/^\d{4}-\d{2}-\d{2}$/D', $state['qualified_at']);
        $idea = array_intersect_key($original, array_flip(['symbol', 'horizon', 'source_date', 'plan_session',
            'scope_label', 'levels', 'evidence', 'pattern', 'event_review']));

        return [...$idea, ...$extra, 'presentation' => self::VERSION,
            'idea_date' => $activation, 'activation_session' => $activation,
            'available_through' => \App\Support\Swing\SwingIdeaRules::sessionAfter($activation, $profile['max_sessions'] - 1),
            'status' => $qualified ? 'conditions_met' : 'watching',
            'status_group' => $qualified ? 'conditions_met' : 'watching',
            'qualified_at' => $qualified ? $state['qualified_at'] : null,
            'qualified_price' => $qualified ? ($state['qualified_price'] ?? null) : null,
            'reason' => $qualified
                ? 'The price conditions passed at the recorded daily close. Compare current price with the idea levels.'
                : 'Watch for the wall test and daily close confirmation shown below.'];
    }

    public static function timeline(array $data): array
    {
        if (($data['presentation'] ?? null) === self::VERSION) {
            return $data;
        }
        $data['presentation'] = self::VERSION;
        if (! is_array($data['wall_interaction'] ?? null)) {
            return $data;
        }
        $interaction = $data['wall_interaction'];
        unset($interaction['setup_reviews'], $interaction['setup_lifecycle'], $interaction['setup_history']);
        if (is_array($interaction['setups'] ?? null)) {
            $setups = $interaction['setups'];
            $qualified = [];
            foreach ($setups['readings'] ?? [] as $row) {
                if (($row['status_at_observation'] ?? $row['status'] ?? null) === 'conditions_met'
                    && ! empty($row['id']) && ($row['entry_eligible_at_observation'] ?? false)) {
                    $qualified[$row['id']] ??= $row;
                }
            }
            $project = function (array $row) use ($qualified): array {
                // Never turn a later target/invalidation close into a new entry signal.
                $original = $qualified[$row['id'] ?? ''] ?? null;
                $met = ($row['status_at_observation'] ?? $row['status'] ?? null) === 'conditions_met'
                    && ($row['entry_eligible_at_observation'] ?? false);
                if (! $met && $original) {
                    $row = $original;
                    $met = true;
                }
                $next = $row['next_condition'] ?? null;
                $watching = ($row['status_at_observation'] ?? $row['status'] ?? null) === 'watch';
                foreach (['outcome', 'outcome_at', 'outcome_close', 'terminal_at', 'review_until', 'reviewed_through', 'review_complete'] as $field) {
                    unset($row[$field]);
                }
                $row['status'] = $row['status_at_observation'] = $met ? 'conditions_met' : 'watch';
                $row['entry_eligible_at_observation'] = false;
                $row['live_entry_eligible'] = false;
                $row['actionable'] = false;
                $row['idea_recorded_at'] = $met ? ($row['observed_at'] ?? null) : null;
                $row['reason'] = $met
                    ? 'The conditions passed at this recorded close. Compare current price with the idea levels.'
                    : 'Watch for a completed price sequence at this wall.';
                $row['next_condition'] = ! $met && $watching && is_array($next)
                    && ($next['setup_status'] ?? null) === 'watch'
                    && in_array($next['code'] ?? null, ['price_sequence', 'wall_reaction', 'acceptance_close', 'retest',
                        'retest_failure', 'continuation_close', 'reclaim_close', 'reversal_close', 'entry_close'], true)
                    ? $next : ['version' => 'wall-next-condition.v1', 'setup_status' => $row['status'],
                        'code' => $met ? 'conditions_met' : 'price_sequence', 'text' => $row['reason'],
                        'completed_closes' => null, 'required_closes' => null, 'threshold' => null, 'close_side' => null,
                        'threshold_inclusive' => false];

                return $row;
            };
            $setups['current'] = array_map($project, $setups['current'] ?? []);
            $setups['readings'] = array_map($project, $setups['readings'] ?? []);
            unset($setups['review_seeds']);
            // The public response explains price conditions, not post-entry state machines.
            unset($setups['rules']['terminal_states']);
            $interaction['setups'] = $setups;
        }
        $data['wall_interaction'] = $interaction;

        return $data;
    }

    private static function ideaRow(array $row): array
    {
        $qualified = ($row['qualified'] ?? false) || ($row['status_group'] ?? null) === 'conditions_met';
        $row = array_intersect_key($row, array_flip(['id', 'symbol', 'side', 'strike', 'alert_id', 'assessment_id',
            'session', 'timeframe', 'qualified', 'observed_at', 'qualified_at', 'evaluated_through', 'close', 'setup']));
        $row['qualified'] = $qualified;
        if (is_array($row['setup'] ?? null)) {
            $row['setup'] = self::setupLevels($row['setup']);
        }
        $row['status_group'] = $qualified ? 'conditions_met' : 'watching';
        $row['reason'] = $qualified
            ? 'The conditions passed at the recorded close. Compare current price with the idea levels.'
            : 'Watch the price conditions at this level.';

        return $row;
    }

    private static function setupLevels(array $setup, bool $current = false): array
    {
        $public = array_intersect_key($setup, array_flip(['pattern', 'direction', 'entry_zone', 'invalidation',
            'target', 'evidence_checks', 'evidence_grade', 'target_room_ratio']));
        // Popup admission still uses the original entry window. Its clock is
        // not a customer-facing trade outcome or a new idea validity period.
        if ($current && array_key_exists('expires_at', $setup)) {
            $public['expires_at'] = $setup['expires_at'];
        }
        foreach (['entry_zone' => ['low', 'high'], 'invalidation' => ['price', 'close_side'], 'target' => ['strike']] as $field => $keys) {
            if (is_array($public[$field] ?? null)) {
                $public[$field] = array_intersect_key($public[$field], array_flip($keys));
            }
        }

        return $public;
    }
}
