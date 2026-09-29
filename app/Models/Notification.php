<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Notification extends Model
{
    protected $table = 'notifications';

    protected $fillable = ['type', 'subject_type', 'subject_id', 'milestone_key', 'milestone_date',
        'priority', 'title', 'body', 'action_url', 'scheduled_for', 'delivered_at', 'read_at', 'dismissed_at'];
}
