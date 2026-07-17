<?php

namespace App\Support\Regression;

final class BaselineComparator
{
    public function __construct(
        private readonly float $floatTolerance = 0.000001,
        private readonly int $maxDifferences = 200,
    ) {
    }

    /**
     * @param  array<string,mixed>  $baseline
     * @param  array<string,mixed>  $candidate
     * @return array{matches:bool,differences:array<int,array<string,mixed>>}
     */
    public function compare(array $baseline, array $candidate): array
    {
        $differences = [];

        $this->diff(
            CanonicalJson::normalize($baseline),
            CanonicalJson::normalize($candidate),
            '$',
            $differences
        );

        return [
            'matches' => $differences === [],
            'differences' => $differences,
        ];
    }

    /**
     * @param  array<int,array<string,mixed>>  $differences
     */
    private function diff(mixed $baseline, mixed $candidate, string $path, array &$differences): void
    {
        if (count($differences) >= $this->maxDifferences) {
            return;
        }

        if (is_array($baseline) && is_array($candidate)) {
            if (array_is_list($baseline) !== array_is_list($candidate)) {
                $this->addDifference($differences, 'type_changed', $path, $baseline, $candidate);

                return;
            }

            if (array_is_list($baseline)) {
                if (count($baseline) !== count($candidate)) {
                    $this->addDifference(
                        $differences,
                        'list_length_changed',
                        $path,
                        count($baseline),
                        count($candidate)
                    );
                }

                $count = min(count($baseline), count($candidate));
                for ($index = 0; $index < $count; $index++) {
                    $this->diff($baseline[$index], $candidate[$index], $path.'['.$index.']', $differences);
                }

                return;
            }

            foreach ($baseline as $key => $value) {
                $childPath = $path.'.'.$key;
                if (!array_key_exists($key, $candidate)) {
                    $this->addDifference($differences, 'missing_field', $childPath, $value, null);
                    continue;
                }

                $this->diff($value, $candidate[$key], $childPath, $differences);
            }

            foreach ($candidate as $key => $value) {
                if (!array_key_exists($key, $baseline)) {
                    $this->addDifference($differences, 'unexpected_field', $path.'.'.$key, null, $value);
                }
            }

            return;
        }

        if ((is_int($baseline) || is_float($baseline))
            && (is_int($candidate) || is_float($candidate))) {
            if (abs((float) $baseline - (float) $candidate) > $this->floatTolerance) {
                $this->addDifference($differences, 'value_changed', $path, $baseline, $candidate);
            }

            return;
        }

        if (is_string($baseline) && is_string($candidate) && $this->isMonotonicTimestampPath($path)) {
            $baselineTimestamp = strtotime($baseline);
            $candidateTimestamp = strtotime($candidate);

            if ($baselineTimestamp !== false && $candidateTimestamp !== false) {
                if ($candidateTimestamp < $baselineTimestamp) {
                    $this->addDifference(
                        $differences,
                        'stale_timestamp',
                        $path,
                        $baseline,
                        $candidate
                    );
                }

                // Moving a latest timestamp forward is expected when fresh data arrives.
                return;
            }
        }

        if (get_debug_type($baseline) !== get_debug_type($candidate)) {
            $this->addDifference($differences, 'type_changed', $path, $baseline, $candidate);

            return;
        }

        if ($baseline !== $candidate) {
            $this->addDifference($differences, 'value_changed', $path, $baseline, $candidate);
        }
    }

    private function isMonotonicTimestampPath(string $path): bool
    {
        return str_contains($path, '.latest_timestamps.')
            || str_contains($path, '.latest_timestamps_by_symbol.')
            || str_contains($path, '.timestamps_by_natural_key.')
            || str_ends_with($path, '.latest_fetched_at')
            || str_ends_with($path, '.latest_asof');
    }

    /**
     * @param  array<int,array<string,mixed>>  $differences
     */
    private function addDifference(
        array &$differences,
        string $type,
        string $path,
        mixed $baseline,
        mixed $candidate,
    ): void {
        if (count($differences) >= $this->maxDifferences) {
            return;
        }

        $differences[] = [
            'type' => $type,
            'path' => $path,
            'baseline' => $baseline,
            'candidate' => $candidate,
        ];
    }
}
