<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One received call on the Infisical webhook endpoint, kept so the user can see
 * whether Infisical reaches Coolify at all and why a call was not acted on.
 *
 * Rows only exist for requests whose uuid resolved to a configuration; blind
 * spam against unknown uuids is never stored. Each configuration keeps at most
 * KEEP_PER_CONFIG rows, so a flood of unauthenticated calls cannot grow the
 * table without bound.
 */
class InfisicalWebhookEvent extends Model
{
    use HasFactory;

    public const KEEP_PER_CONFIG = 50;

    public const OUTCOME_QUEUED = 'queued';

    public const OUTCOME_INVALID_SIGNATURE = 'invalid_signature';

    public const OUTCOME_PAYLOAD_MISMATCH = 'payload_mismatch';

    public const OUTCOME_SECRET_MISSING = 'secret_missing';

    public const OUTCOME_DISABLED = 'disabled';

    protected $fillable = [
        'infisical_sync_config_id',
        'outcome',
        'event',
    ];

    public function syncConfig(): BelongsTo
    {
        return $this->belongsTo(InfisicalSyncConfig::class, 'infisical_sync_config_id');
    }

    /**
     * Store one received call and prune the configuration's history down to the
     * newest KEEP_PER_CONFIG rows in the same breath, so retention never depends
     * on a scheduler being healthy.
     */
    public static function record(InfisicalSyncConfig $config, string $outcome, ?string $event = null): self
    {
        $row = self::create([
            'infisical_sync_config_id' => $config->id,
            'outcome' => $outcome,
            'event' => filled($event) ? substr($event, 0, 255) : null,
        ]);

        $cutoff = self::where('infisical_sync_config_id', $config->id)
            ->orderByDesc('id')
            ->skip(self::KEEP_PER_CONFIG - 1)
            ->take(1)
            ->value('id');

        if ($cutoff !== null) {
            self::where('infisical_sync_config_id', $config->id)
                ->where('id', '<', $cutoff)
                ->delete();
        }

        return $row;
    }
}
