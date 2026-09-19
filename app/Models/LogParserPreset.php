<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;

/**
 * A reusable, team-scoped log parser configuration.
 *
 * `config` is a JSON document validated by App\Support\LogParserConfig and
 * interpreted in the browser by resources/js/log-parser.
 */
class LogParserPreset extends BaseModel
{
    use HasFactory;

    protected $fillable = [
        'team_id',
        'name',
        'description',
        'config',
    ];

    public function team()
    {
        return $this->belongsTo(Team::class);
    }

    public static function ownedByCurrentTeam(array $select = ['*'])
    {
        $selectArray = collect($select)->concat(['id']);

        return self::whereTeamId(currentTeam()->id)->select($selectArray->all());
    }
}
