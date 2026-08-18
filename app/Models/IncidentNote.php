<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
final class IncidentNote extends Model { use HasUuids; protected $guarded=[]; protected function casts():array{return ['edited_at'=>'datetime'];} }
