<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
final class NotificationDelivery extends Model { use HasUuids; protected $guarded=[]; protected function casts():array{return ['next_attempt_at'=>'datetime','delivered_at'=>'datetime'];} }
