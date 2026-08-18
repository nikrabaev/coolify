<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Received calls on the Infisical webhook endpoint, kept so the user can see
 * whether Infisical reaches Coolify and why a call was not acted on.
 *
 * Rows only exist for requests whose uuid resolved to a configuration; blind
 * spam against unknown uuids is never stored.
 */
class InfisicalWebhookEvent extends Model
{
    use HasFactory;

    /** Applies to signature-verified rows only; see UNVERIFIED_OUTCOMES. */
    public const KEEP_PER_CONFIG = 50;

    public const OUTCOME_QUEUED = 'queued';

    public const OUTCOME_PAYLOAD_MISMATCH = 'payload_mismatch';

    public const OUTCOME_INVALID_SIGNATURE = 'invalid_signature';

    public const OUTCOME_MALFORMED_SIGNATURE = 'malformed_signature';

    public const OUTCOME_STALE_TIMESTAMP = 'stale_timestamp';

    public const OUTCOME_SECRET_MISSING = 'secret_missing';

    public const OUTCOME_DISABLED = 'disabled';

    /**
     * Outcomes for calls whose signature was never verified.
     *
     * These are folded into one counter row per outcome instead of one row per
     * call. The endpoint is unauthenticated, so storing them individually would
     * hand anyone who knows a configuration uuid a way to push the genuine,
     * signature-verified history out of the newest KEEP_PER_CONFIG rows — a
     * log-wiping primitive. Coalescing bounds them to one row per outcome and
     * exempts them from pruning, so the two classes never compete.
     */
    public const UNVERIFIED_OUTCOMES = [
        self::OUTCOME_INVALID_SIGNATURE,
        self::OUTCOME_MALFORMED_SIGNATURE,
        self::OUTCOME_STALE_TIMESTAMP,
        self::OUTCOME_SECRET_MISSING,
        self::OUTCOME_DISABLED,
    ];

    protected $fillable = [
        'infisical_sync_config_id',
        'outcome',
        'event',
        'occurrences',
    ];

    protected $casts = [
        'occurrences' => 'integer',
    ];

    public function syncConfig(): BelongsTo
    {
        return $this->belongsTo(InfisicalSyncConfig::class, 'infisical_sync_config_id');
    }

    /**
     * Store one received call. A null config means the uuid did not resolve, so
     * there is nothing to attribute the call to and nothing is written.
     */
    public static function record(?InfisicalSyncConfig $config, string $outcome, ?string $event = null): void
    {
        if (! $config) {
            return;
        }

        if (self::isUnverified($outcome)) {
            self::coalesce($config, $outcome);

            return;
        }

        self::create([
            'infisical_sync_config_id' => $config->id,
            'outcome' => $outcome,
            'event' => filled($event) ? substr($event, 0, 255) : null,
            'occurrences' => 1,
        ]);

        self::pruneVerified($config);
    }

    public static function isUnverified(string $outcome): bool
    {
        return in_array($outcome, self::UNVERIFIED_OUTCOMES, true);
    }

    /**
     * True once this row stands for more than one call, i.e. it is a coalesced
     * counter rather than a single delivery.
     */
    public function isCounter(): bool
    {
        return $this->occurrences > 1;
    }

    /**
     * Bump the counter for this outcome, creating it on the first occurrence.
     * `updated_at` doubles as "last seen".
     */
    private static function coalesce(InfisicalSyncConfig $config, string $outcome): void
    {
        $bumped = self::where('infisical_sync_config_id', $config->id)
            ->where('outcome', $outcome)
            ->increment('occurrences');

        if ($bumped > 0) {
            return;
        }

        self::create([
            'infisical_sync_config_id' => $config->id,
            'outcome' => $outcome,
            'occurrences' => 1,
        ]);
    }

    /**
     * Keep the newest KEEP_PER_CONFIG verified rows. Unverified outcomes are
     * excluded on both sides: they are already bounded, and counting them here
     * would let them evict verified deliveries.
     */
    private static function pruneVerified(InfisicalSyncConfig $config): void
    {
        $cutoff = self::verifiedFor($config)
            ->orderByDesc('id')
            ->skip(self::KEEP_PER_CONFIG - 1)
            ->take(1)
            ->value('id');

        if ($cutoff === null) {
            return;
        }

        self::verifiedFor($config)->where('id', '<', $cutoff)->delete();
    }

    private static function verifiedFor(InfisicalSyncConfig $config)
    {
        return self::where('infisical_sync_config_id', $config->id)
            ->whereNotIn('outcome', self::UNVERIFIED_OUTCOMES);
    }
}
