<?php

namespace App\Enums;

/**
 * Status values accepted by the deal CRUD API.
 */
enum DealStatus: string
{
    case New = 'NEW';
    case InProgress = 'IN_PROGRESS';
    case Won = 'WON';
    case Lost = 'LOST';

    /**
     * Return all supported status values for validation and fixtures.
     *
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(
            static fn (self $status): string => $status->value,
            self::cases(),
        );
    }
}
