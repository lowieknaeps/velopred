<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RaceResult extends Model
{
    protected $fillable = [
        'race_id',
        'rider_id',
        'team_id',
        'result_type',
        'stage_number',
        'position',
        'status',
        'time_seconds',
        'gap_seconds',
        'pcs_points',
        'uci_points',
        'synced_at',
    ];

    protected $casts = [
        'uci_points' => 'decimal:2',
        'synced_at'  => 'datetime',
    ];

    // ── Relaties ──────────────────────────────────────────────────────────────

    public function race(): BelongsTo
    {
        return $this->belongsTo(Race::class);
    }

    public function rider(): BelongsTo
    {
        return $this->belongsTo(Rider::class);
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    public function isFinished(): bool
    {
        return $this->status === 'finished';
    }

    public function isTopTen(): bool
    {
        return $this->isFinished() && $this->position !== null && $this->position <= 10;
    }

    /**
     * Geeft de rijtijd terug als leesbare string (H:MM:SS).
     */
    public function getFormattedTimeAttribute(): ?string
    {
        if ($this->time_seconds === null) return null;

        $h = intdiv($this->time_seconds, 3600);
        $m = intdiv($this->time_seconds % 3600, 60);
        $s = $this->time_seconds % 60;

        return sprintf('%d:%02d:%02d', $h, $m, $s);
    }
}
