<?php

namespace Conveyor\Models;

use Illuminate\Database\Eloquent\Model;

class WsAssociation extends Model
{
    public const TABLE_NAME = 'wsassociations';

    protected $table = self::TABLE_NAME;
    protected $connection = 'socket-conveyor';

    protected $fillable = [
        'fd',
        'user_id',
    ];
}
