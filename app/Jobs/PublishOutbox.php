<?php
namespace App\Jobs;
use App\Events\IncidentEventBroadcast;
use App\Models\OutboxMessage;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Throwable;
final class PublishOutbox implements ShouldQueue {
 use Dispatchable,InteractsWithQueue,Queueable,SerializesModels;
 public int $tries=5; public array $backoff=[2,5,15,60];
 public function handle():void{
  for($i=0;$i<100;$i++){
   $message=DB::transaction(function(){
    $m=OutboxMessage::whereNull('published_at')->where('available_at','<=',now())->orderBy('created_at')->lock('FOR UPDATE SKIP LOCKED')->first();
    if(!$m)return null;
    // Lease before the DB lock is released. A crashed publisher becomes eligible again.
    $m->available_at=now()->addSeconds(30);$m->attempts++;$m->save();return $m;
   },3);
   if(!$message)break;
   try{
    if($message->aggregate_type==='incident') broadcast(new IncidentEventBroadcast($message->aggregate_id,$message->payload_json));
    if($message->incident_event_id) FanoutIncidentNotifications::dispatch($message->incident_event_id)->onQueue('notifications');
    // Acknowledge the outbox only after every required side effect has been accepted.
    // If notification dispatch fails after a successful broadcast, the row is retried;
    // duplicate broadcasts are harmless because incident events have stable IDs/sequences.
    $message->update(['published_at'=>now(),'last_error'=>null]);
   }catch(Throwable $e){$message->update(['last_error'=>substr($e->getMessage(),0,1000),'available_at'=>now()->addSeconds(min(300,2**min(8,$message->attempts)))]);}
  }
 }
}
