<?php

namespace Conveyor\Persistence\WebSockets\Eloquent\Models;

use Illuminate\Database\Eloquent\Model;

class WsChannel extends Model
{
    public const TABLE_NAME = 'wschannels';

    /** @var string */
    protected $table = self::TABLE_NAME;
    protected $connection = 'socket-conveyor';

    protected $fillable = [
        'fd',
        'channel',
    ];
}
