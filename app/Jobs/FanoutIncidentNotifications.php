<?php
namespace App\Jobs;
use App\Models\{Incident,IncidentEvent,NotificationDelivery,NotificationPreference};
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
final class FanoutIncidentNotifications implements ShouldQueue {
 use Dispatchable,InteractsWithQueue,Queueable,SerializesModels;
 public function __construct(public int $eventId){}
 public function handle():void{
  $event=IncidentEvent::find($this->eventId); if(!$event)return;
  $incident=Incident::find($event->incident_id); if(!$incident)return;
  $participantIds=$incident->participants()->pluck('users.id')->all(); if($incident->commander_user_id)$participantIds[]=$incident->commander_user_id;
  $participantIds=array_values(array_unique(array_map('intval',$participantIds)));
  if($participantIds===[])return;
  $prefs=NotificationPreference::where('organization_id',$incident->organization_id)->whereIn('user_id',$participantIds)->get()->keyBy('user_id');
  foreach($participantIds as $userId){
   $pref=$prefs->get($userId); $allowed=$pref?->event_types;
   if(is_array($allowed)&&$allowed!==[]&&!in_array($event->event_type,$allowed,true))continue;
   $channels=[]; if($pref?->email_enabled ?? true)$channels[]='email'; if($pref?->webhook_enabled)$channels[]='webhook';
   foreach($channels as $channel){
    $delivery=NotificationDelivery::firstOrCreate(['incident_event_id'=>$event->id,'user_id'=>$userId,'channel'=>$channel],['status'=>'pending','next_attempt_at'=>now()]);
    if(in_array($delivery->status,['pending','failed'],true))DeliverIncidentNotification::dispatch($delivery->id)->onQueue('notifications');
   }
  }
 }
}
