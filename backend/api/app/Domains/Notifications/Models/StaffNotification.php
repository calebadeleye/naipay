<?php

declare(strict_types=1);

namespace App\Domains\Notifications\Models;

use App\Domains\Identity\Models\Staff;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * One entry in a staff member's notification bell.
 *
 * @property int $id
 * @property int $staff_id
 * @property string $type
 * @property string $title
 * @property string|null $body
 * @property string|null $action_url
 * @property \Illuminate\Support\Carbon|null $read_at
 */
class StaffNotification extends Model
{
    protected $table = 'staff_notifications';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'staff_id',
        'type',
        'title',
        'body',
        'subject_type',
        'subject_id',
        'action_url',
        'read_at',
    ];

    /**
     * @return BelongsTo<Staff, $this>
     */
    public function staff(): BelongsTo
    {
        return $this->belongsTo(Staff::class);
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function subject(): MorphTo
    {
        return $this->morphTo();
    }

    public function isRead(): bool
    {
        return $this->read_at !== null;
    }

    /**
     * @param  Builder<StaffNotification>  $query
     * @return Builder<StaffNotification>
     */
    public function scopeUnread(Builder $query): Builder
    {
        return $query->whereNull('read_at');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'read_at' => 'datetime',
        ];
    }
}
