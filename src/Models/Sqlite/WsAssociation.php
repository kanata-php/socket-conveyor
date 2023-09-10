<?php

namespace Conveyor\Models\Sqlite;

use Illuminate\Database\Eloquent\Model;

class WsAssociation extends Model
{
    const TABLE_NAME = 'wsassociations';

    protected $table = self::TABLE_NAME;

    protected $fillable = [
        'fd',
        'user_id',
    ];
}
