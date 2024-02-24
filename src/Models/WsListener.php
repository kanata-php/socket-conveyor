<?php

namespace Conveyor\Models;

use Illuminate\Database\Eloquent\Model;

class WsListener extends Model
{
    public const TABLE_NAME = 'wslisteners';

    protected $table = self::TABLE_NAME;
    protected $connection = 'socket-conveyor';

    protected $fillable = [
        'fd',
        'action',
    ];
}
