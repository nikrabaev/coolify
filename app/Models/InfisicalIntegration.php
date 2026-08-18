<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;

class InfisicalIntegration extends BaseModel
{
    use HasFactory;

    protected $fillable = [
        'team_id',
        'name',
        'description',
        'base_url',
        'client_id',
        'client_secret',
        'is_usable',
        'last_validated_at',
    ];

    protected $hidden = [
        'client_id',
        'client_secret',
    ];

    protected $casts = [
        'client_id' => 'encrypted',
        'client_secret' => 'encrypted',
        'is_usable' => 'boolean',
        'last_validated_at' => 'datetime',
    ];

    protected static function booted()
    {
        // Delete configs through Eloquent so their own deleting hook runs and
        // managed environment variables are handed back to the user. The
        // database cascade stays as a backstop for team deletion.
        static::deleting(function (InfisicalIntegration $integration) {
            $integration->syncConfigs()->cursor()->each(fn (InfisicalSyncConfig $config) => $config->delete());
        });
    }

    public function team()
    {
        return $this->belongsTo(Team::class);
    }

    public function syncConfigs()
    {
        return $this->hasMany(InfisicalSyncConfig::class);
    }

    public static function ownedByCurrentTeam(array $select = ['*'])
    {
        $selectArray = collect($select)->concat(['id']);

        return self::whereTeamId(currentTeam()->id)->select($selectArray->all());
    }

    public function apiBaseUrl(): string
    {
        return rtrim($this->base_url ?: 'https://app.infisical.com', '/');
    }
}
