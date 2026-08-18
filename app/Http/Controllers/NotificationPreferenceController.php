<?php
namespace App\Http\Controllers;
use App\Models\{NotificationPreference,Organization};
use App\Services\PublicUrlGuard;
use Illuminate\Http\{JsonResponse,Request};
final class NotificationPreferenceController {
 public function show(Request $r,string $organizationId):JsonResponse{$this->access($r,$organizationId);return response()->json(NotificationPreference::firstOrCreate(['organization_id'=>$organizationId,'user_id'=>$r->user()->id],['email_enabled'=>true,'webhook_enabled'=>false]));}
 public function update(Request $r,string $organizationId,PublicUrlGuard $guard):JsonResponse{$this->access($r,$organizationId);$d=$r->validate(['email_enabled'=>'required|boolean','webhook_enabled'=>'required|boolean','webhook_url'=>'nullable|url|max:2000','webhook_secret'=>'nullable|string|max:500','event_types'=>'nullable|array','event_types.*'=>'string|max:120']);if($d['webhook_enabled']){abort_unless(!empty($d['webhook_url']),422);$guard->assert($d['webhook_url']);}return response()->json(NotificationPreference::updateOrCreate(['organization_id'=>$organizationId,'user_id'=>$r->user()->id],$d));}
 private function access(Request $r,string $id):void{abort_unless($r->user()->organizations()->whereKey($id)->exists(),403);}
}
