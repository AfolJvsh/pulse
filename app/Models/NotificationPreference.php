<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
final class NotificationPreference extends Model { protected $guarded=[]; protected function casts():array{return ['email_enabled'=>'boolean','webhook_enabled'=>'boolean','webhook_secret'=>'encrypted','event_types'=>'array'];} }
