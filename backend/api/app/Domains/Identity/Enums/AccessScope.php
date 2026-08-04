<?php

declare(strict_types=1);

namespace App\Domains\Identity\Enums;

/**
 * How far across the organisation a staff member can see.
 *
 * Deliberately separate from permissions. A permission answers "may they do
 * this?"; a scope answers "to whose records?". A Branch Manager and an
 * Operations Manager can hold identical permissions and legitimately differ in
 * reach, and conflating the two would force a duplicate role per branch.
 */
enum AccessScope: string
{
    /** Their own branch only. The default, and the right default. */
    case Branch = 'branch';

    /** Their department across every branch — credit, finance, compliance. */
    case Department = 'department';

    /** The whole organisation. */
    case Global = 'global';

    public function label(): string
    {
        return match ($this) {
            self::Branch => 'Own branch',
            self::Department => 'Own department, all branches',
            self::Global => 'Entire organisation',
        };
    }

    public function isGlobal(): bool
    {
        return $this === self::Global;
    }
}
