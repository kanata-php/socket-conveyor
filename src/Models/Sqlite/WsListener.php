<?php

namespace Conveyor\Models\Sqlite;

use Illuminate\Database\Eloquent\Model;

class WsListener extends Model
{
    const TABLE_NAME = 'wslisteners';


    protected $table = self::TABLE_NAME;

    protected $fillable = [
        'fd',
        'action',
    ];
}
