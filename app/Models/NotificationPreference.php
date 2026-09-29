<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class NotificationPreference extends Model
{
    protected $table = 'notification_preferences';

    protected $fillable = ['notification_type', 'enabled', 'in_app_enabled', 'email_enabled', 'push_enabled'];
}
