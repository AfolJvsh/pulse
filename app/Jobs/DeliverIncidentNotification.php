<?php
namespace App\Jobs;
use App\Models\{IncidentEvent,NotificationDelivery,NotificationPreference,User};
use App\Services\PublicUrlGuard;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\{Http,Mail};
use Throwable;
final class DeliverIncidentNotification implements ShouldQueue {
 use Dispatchable,InteractsWithQueue,Queueable,SerializesModels;
 public int $tries=5; public array $backoff=[5,30,120,300];
 public function __construct(public string $deliveryId){}
 public function handle(PublicUrlGuard $guard):void{
  $delivery=NotificationDelivery::find($this->deliveryId); if(!$delivery||$delivery->status==='delivered')return;
  $event=IncidentEvent::with([])->findOrFail($delivery->incident_event_id); $user=User::findOrFail($delivery->user_id);
  $incident=\App\Models\Incident::findOrFail($event->incident_id); $pref=NotificationPreference::where('organization_id',$incident->organization_id)->where('user_id',$user->id)->first();
  $payload=['incident_id'=>$incident->id,'incident_number'=>$incident->incident_number,'title'=>$incident->title,'event_type'=>$event->event_type,'sequence'=>$event->sequence,'event'=>$event->payload_json,'occurred_at'=>$event->occurred_at?->toISOString()];
  try{
   if($delivery->channel==='email') Mail::raw("Pulse incident #{$incident->incident_number}: {$event->event_type}\n\n".json_encode($event->payload_json,JSON_PRETTY_PRINT),fn($m)=>$m->to($user->email)->subject("[Pulse] {$incident->title} · {$event->event_type}"));
   elseif($delivery->channel==='webhook'){
    if(!$pref?->webhook_enabled||!$pref->webhook_url)throw new \RuntimeException('Webhook preference is no longer enabled.'); $guard->assert($pref->webhook_url); $body=json_encode($payload,JSON_UNESCAPED_SLASHES); $sig=hash_hmac('sha256',$body,(string)$pref->webhook_secret); Http::timeout(8)->withHeaders(['Content-Type'=>'application/json','X-Pulse-Signature'=>'sha256='.$sig,'Idempotency-Key'=>'pulse-notification-'.$delivery->id])->withBody($body,'application/json')->post($pref->webhook_url)->throw();
   }
   else throw new \RuntimeException('Unknown notification channel.');
   $delivery->update(['status'=>'delivered','attempts'=>$delivery->attempts+1,'delivered_at'=>now(),'last_error'=>null,'next_attempt_at'=>null]);
  }catch(Throwable $e){$delivery->update(['status'=>$this->attempts()>=$this->tries?'dead':'failed','attempts'=>$delivery->attempts+1,'last_error'=>substr($e->getMessage(),0,1000),'next_attempt_at'=>now()->addSeconds(min(300,2**min(8,$delivery->attempts+1)))]);throw $e;}
 }
}
