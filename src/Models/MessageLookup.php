<?php

declare(strict_types=1);

namespace Sendportal\Base\Models;

use Illuminate\Database\Eloquent\Model;

class MessageLookup extends Model
{
    protected $table = 'sendportal_message_lookup';

    public $timestamps = false;

    protected $primaryKey = 'message_id';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'message_id',
        'source_id',
        'hash',
    ];
}
