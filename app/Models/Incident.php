<?php
namespace App\Models;
use App\Domain\Incidents\{IncidentStatus,Severity};
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
final class Incident extends Model {
    use HasUuids;
    protected $guarded=[];
    protected function casts():array{return ['status'=>IncidentStatus::class,'severity'=>Severity::class,'started_at'=>'datetime','mitigated_at'=>'datetime','resolved_at'=>'datetime'];}
    public function events(){return $this->hasMany(IncidentEvent::class)->orderBy('sequence');}
    public function comments(){return $this->hasMany(Comment::class);}
    public function actionItems(){return $this->hasMany(ActionItem::class);}
    public function note(){return $this->hasOne(IncidentNote::class);}
    public function participants(){return $this->belongsToMany(User::class,'incident_participants')->withPivot(['role','joined_at']);}
}
