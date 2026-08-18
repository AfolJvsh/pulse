<?php
namespace App\Models;use Illuminate\Database\Eloquent\Model;
final class IncidentEvent extends Model{public $timestamps=false;protected $guarded=[];protected function casts():array{return ['payload_json'=>'array','occurred_at'=>'datetime'];}}
