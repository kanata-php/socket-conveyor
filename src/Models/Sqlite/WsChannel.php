<?php

namespace Conveyor\Models\Sqlite;

use Illuminate\Database\Eloquent\Model;

class WsChannel extends Model
{
    const TABLE_NAME = 'wschannels';

    /** @var string */
    protected $table = self::TABLE_NAME;

    protected $fillable = [
        'fd',
        'channel',
    ];
}
