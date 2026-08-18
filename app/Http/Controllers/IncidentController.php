<?php

namespace App\Http\Controllers;

use App\Domain\Incidents\{IncidentStatus,Severity};
use App\Exceptions\{ClientCommandConflict,VersionConflict};
use App\Models\{ActionItem,Comment,Incident,Organization};
use App\Services\{IncidentCommandService,IncidentEventWriter};
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{DB,Redis};

final class IncidentController
{
    public function index(Request $request): JsonResponse
    {
        $orgIds=$request->user()->organizations()->pluck('organizations.id');
        return response()->json(Incident::whereIn('organization_id',$orgIds)->withCount(['comments','actionItems','participants'])->latest('started_at')->limit(100)->get());
    }

    public function store(Request $request, IncidentEventWriter $events): JsonResponse
    {
        $data=$request->validate(['organization_id'=>'required|uuid','title'=>'required|string|max:180','description'=>'nullable|string','severity'=>'required|in:sev1,sev2,sev3,sev4','client_command_id'=>'nullable|uuid']); $this->assertOrganizationAccess($request,$data['organization_id']);
        $incident=DB::transaction(function() use($data,$request,$events){Organization::lockForUpdate()->findOrFail($data['organization_id']);$number=(int)Incident::where('organization_id',$data['organization_id'])->max('incident_number')+1;$incident=Incident::create(['organization_id'=>$data['organization_id'],'title'=>$data['title'],'description'=>$data['description']??null,'severity'=>$data['severity'],'incident_number'=>$number,'status'=>IncidentStatus::Open,'version'=>1,'last_sequence'=>0,'started_at'=>now()]);DB::table('incident_participants')->insertOrIgnore(['incident_id'=>$incident->id,'user_id'=>$request->user()->id,'role'=>'commander','joined_at'=>now()]);$incident->commander_user_id=$request->user()->id;$incident->save();$events->appendLocked($incident,'IncidentCreated',$request->user()->id,['title'=>$incident->title,'severity'=>$incident->severity->value,'status'=>$incident->status->value,'commander_user_id'=>$incident->commander_user_id,'version'=>1],$data['client_command_id']??null);return $incident;},3);
        return response()->json($incident,201);
    }

    public function show(Request $request, Incident $incident): JsonResponse { $this->assertOrganizationAccess($request,$incident->organization_id); return response()->json($this->snapshot($incident)); }

    public function events(Request $request, Incident $incident): JsonResponse
    {
        $this->assertOrganizationAccess($request,$incident->organization_id);$after=max(0,(int)$request->query('after_sequence',0));Redis::incr("pulse:metrics:replays:{$incident->organization_id}");$gap=max(0,$incident->last_sequence-$after);
        $events=$incident->events()->where('sequence','>',$after)->orderBy('sequence')->limit($gap>1000?250:500)->get();
        if($gap>1000)return response()->json(['mode'=>'snapshot','snapshot'=>$this->snapshot($incident),'events'=>$events,'last_sequence'=>$incident->last_sequence]);
        return response()->json(['mode'=>'events','events'=>$events,'last_sequence'=>$incident->last_sequence]);
    }

    public function severity(Request $r,Incident $i,IncidentCommandService $c):JsonResponse{$this->assertOrganizationAccess($r,$i->organization_id);$d=$r->validate(['severity'=>'required|in:sev1,sev2,sev3,sev4','expected_version'=>'required|integer|min:1','client_command_id'=>'nullable|uuid']);return $this->conflictSafe($i,fn()=>response()->json($c->changeSeverity($i,Severity::from($d['severity']),$d['expected_version'],$r->user()->id,$d['client_command_id']??null)));}
    public function status(Request $r,Incident $i,IncidentCommandService $c):JsonResponse{$this->assertOrganizationAccess($r,$i->organization_id);$d=$r->validate(['status'=>'required|in:open,investigating,mitigated,resolved,closed','expected_version'=>'required|integer|min:1','client_command_id'=>'nullable|uuid']);return $this->conflictSafe($i,fn()=>response()->json($c->changeStatus($i,IncidentStatus::from($d['status']),$d['expected_version'],$r->user()->id,$d['client_command_id']??null)));}
    public function commander(Request $r,Incident $i,IncidentCommandService $c):JsonResponse{$this->assertOrganizationAccess($r,$i->organization_id);$d=$r->validate(['commander_user_id'=>'nullable|integer|exists:users,id','expected_version'=>'required|integer|min:1','client_command_id'=>'nullable|uuid']);if(isset($d['commander_user_id']))$this->assertOrgUser($i,$d['commander_user_id']);return $this->conflictSafe($i,fn()=>response()->json($c->assignCommander($i,$d['commander_user_id']??null,$d['expected_version'],$r->user()->id,$d['client_command_id']??null)));}
    public function participant(Request $r,Incident $i,IncidentCommandService $c):JsonResponse{$this->assertOrganizationAccess($r,$i->organization_id);$d=$r->validate(['user_id'=>'required|integer|exists:users,id','role'=>'required|in:commander,responder,observer','client_command_id'=>'nullable|uuid']);$this->assertOrgUser($i,$d['user_id']);return response()->json($c->addParticipant($i,$d['user_id'],$d['role'],$r->user()->id,$d['client_command_id']??null),201);}
    public function comment(Request $r,Incident $i,IncidentCommandService $c):JsonResponse{$this->assertOrganizationAccess($r,$i->organization_id);$d=$r->validate(['body'=>'required|string|max:10000','client_command_id'=>'nullable|uuid']);return response()->json($c->addComment($i,$r->user()->id,$d['body'],$d['client_command_id']??null),201);}
    public function editComment(Request $r,Incident $i,Comment $comment,IncidentCommandService $c):JsonResponse{$this->assertOrganizationAccess($r,$i->organization_id);$d=$r->validate(['body'=>'required|string|max:10000','expected_version'=>'required|integer|min:1','client_command_id'=>'nullable|uuid']);return $this->conflictSafe($i,fn()=>response()->json($c->editComment($i,$comment,$d['body'],$d['expected_version'],$r->user()->id,$d['client_command_id']??null)));}
    public function actionItem(Request $r,Incident $i,IncidentCommandService $c):JsonResponse{$this->assertOrganizationAccess($r,$i->organization_id);$d=$r->validate(['title'=>'required|string|max:255','assignee_user_id'=>'nullable|integer|exists:users,id','due_at'=>'nullable|date','client_command_id'=>'nullable|uuid']);if(isset($d['assignee_user_id']))$this->assertOrgUser($i,$d['assignee_user_id']);return response()->json($c->addActionItem($i,$r->user()->id,$d['title'],$d['assignee_user_id']??null,$d['due_at']??null,$d['client_command_id']??null),201);}
    public function updateActionItem(Request $r,Incident $i,ActionItem $actionItem,IncidentCommandService $c):JsonResponse{$this->assertOrganizationAccess($r,$i->organization_id);$d=$r->validate(['title'=>'sometimes|string|max:255','assignee_user_id'=>'sometimes|nullable|integer|exists:users,id','status'=>'sometimes|in:open,in_progress,completed','due_at'=>'sometimes|nullable|date','expected_version'=>'required|integer|min:1','client_command_id'=>'nullable|uuid']);if(isset($d['assignee_user_id']))$this->assertOrgUser($i,$d['assignee_user_id']);$changes=array_intersect_key($d,array_flip(['title','assignee_user_id','status','due_at']));return $this->conflictSafe($i,fn()=>response()->json($c->updateActionItem($i,$actionItem,$changes,$d['expected_version'],$r->user()->id,$d['client_command_id']??null)));}
    public function completeActionItem(Request $r,Incident $i,ActionItem $actionItem,IncidentCommandService $c):JsonResponse{$this->assertOrganizationAccess($r,$i->organization_id);$d=$r->validate(['expected_version'=>'required|integer|min:1','client_command_id'=>'nullable|uuid']);return $this->conflictSafe($i,fn()=>response()->json($c->completeActionItem($i,$actionItem,$d['expected_version'],$r->user()->id,$d['client_command_id']??null)));}
    public function saveNote(Request $r,Incident $i,IncidentCommandService $c):JsonResponse{$this->assertOrganizationAccess($r,$i->organization_id);$d=$r->validate(['body'=>'required|string|max:100000','expected_version'=>'required|integer|min:1','client_command_id'=>'nullable|uuid']);return $this->conflictSafe($i,fn()=>response()->json($c->saveNote($i,$d['body'],$d['expected_version'],$r->user()->id,$d['client_command_id']??null)));}
    public function monitoringSignal(Request $r,Incident $i,IncidentCommandService $c):JsonResponse{$this->assertOrganizationAccess($r,$i->organization_id);$d=$r->validate(['source'=>'required|string|max:120','summary'=>'required|string|max:500','level'=>'required|in:info,warning,critical,recovery','details'=>'nullable|array']);return response()->json($c->monitoringSignal($i,$d['source'],$d['summary'],$d['level'],$d['details']??[]),201);}

    private function snapshot(Incident $incident):array{return ['incident'=>$incident->fresh(),'participants'=>$incident->participants()->get(['users.id','users.name','users.email']),'comments'=>$incident->comments()->orderBy('created_at')->get(),'action_items'=>$incident->actionItems()->orderBy('created_at')->get(),'note'=>$incident->note()->first()??['body'=>'','version'=>1],'events'=>$incident->events()->latest('sequence')->limit(100)->get()->reverse()->values()];}
    private function conflictSafe(Incident $incident,callable $fn):JsonResponse{try{return $fn();}catch(VersionConflict $e){Redis::incr("pulse:metrics:conflicts:{$incident->organization_id}");return response()->json(['message'=>$e->getMessage(),'latest'=>$e->latest],409);}catch(ClientCommandConflict $e){return response()->json(['message'=>$e->getMessage()],409);}}
    private function assertOrganizationAccess(Request $r,string $id):void{abort_unless($r->user()->organizations()->whereKey($id)->exists(),403);}
    private function assertOrgUser(Incident $i,int $userId):void{abort_unless(Organization::findOrFail($i->organization_id)->users()->whereKey($userId)->exists(),422);}
}
