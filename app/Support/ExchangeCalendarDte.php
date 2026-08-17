<?php

namespace App\Support;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use InvalidArgumentException;

final class ExchangeCalendarDte
{
    public const TIMEZONE = 'America/New_York';

    /**
     * Resolve an instant to its New York calendar date and calendar-day DTE.
     *
     * This intentionally does not infer exchange holidays or market sessions.
     * An expiration remains 0DTE for its entire New York calendar date.
     *
     * @return array{exchange_as_of_date:string, expiration_date:string, dte:int}
     */
    public function resolve(DateTimeInterface $instant, string $expirationDate): array
    {
        $timezone = new DateTimeZone(self::TIMEZONE);
        $exchangeInstant = DateTimeImmutable::createFromInterface($instant)->setTimezone($timezone);
        $exchangeAsOfDate = $exchangeInstant->format('Y-m-d');
        $asOf = $this->parseDate($exchangeAsOfDate, $timezone);
        $expiration = $this->parseDate($expirationDate, $timezone);
        $signedDays = (int) $asOf->diff($expiration)->format('%r%a');

        return [
            'exchange_as_of_date' => $exchangeAsOfDate,
            'expiration_date' => $expiration->format('Y-m-d'),
            'dte' => max(0, $signedDays),
        ];
    }

    private function parseDate(string $date, DateTimeZone $timezone): DateTimeImmutable
    {
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date, $timezone);
        $errors = DateTimeImmutable::getLastErrors();
        $hasErrors = is_array($errors)
            && ((int) $errors['warning_count'] > 0 || (int) $errors['error_count'] > 0);

        if ($parsed === false || $hasErrors || $parsed->format('Y-m-d') !== $date) {
            throw new InvalidArgumentException('Expiration date must be a valid Y-m-d calendar date.');
        }

        return $parsed;
    }
}
