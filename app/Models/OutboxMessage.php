<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
final class OutboxMessage extends Model { use HasUuids; protected $guarded=[]; protected function casts():array{return ['payload_json'=>'array','published_at'=>'datetime','available_at'=>'datetime'];} }
