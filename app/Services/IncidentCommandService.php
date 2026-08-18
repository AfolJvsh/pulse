<?php

namespace App\Services;

use App\Domain\Incidents\{IncidentStateMachine,IncidentStatus,Severity};
use App\Exceptions\{ClientCommandConflict,VersionConflict};
use App\Models\{ActionItem,Comment,Incident,IncidentEvent,IncidentNote};
use Illuminate\Support\Facades\DB;

final class IncidentCommandService
{
    public function __construct(private IncidentStateMachine $states, private IncidentEventWriter $events) {}

    public function changeSeverity(Incident $incident, Severity $severity, int $expectedVersion, ?int $actorId, ?string $commandId): Incident
    {
        return DB::transaction(function () use ($incident,$severity,$expectedVersion,$actorId,$commandId) {
            $current=Incident::lockForUpdate()->findOrFail($incident->id);
            if($this->existingCommand($current,$commandId,'SeverityChanged')) return $current->fresh();
            $this->assertVersion($current,$expectedVersion); $from=$current->severity; $current->severity=$severity; $current->version++; $current->save();
            $this->events->appendLocked($current,'SeverityChanged',$actorId,['from'=>$from->value,'to'=>$severity->value,'version'=>$current->version],$commandId);
            return $current->fresh();
        },3);
    }

    public function changeStatus(Incident $incident, IncidentStatus $status, int $expectedVersion, ?int $actorId, ?string $commandId): Incident
    {
        return DB::transaction(function () use ($incident,$status,$expectedVersion,$actorId,$commandId) {
            $current=Incident::lockForUpdate()->findOrFail($incident->id);
            if($this->existingCommand($current,$commandId,'StatusChanged')) return $current->fresh();
            $this->assertVersion($current,$expectedVersion); $this->states->assert($current->status,$status); $from=$current->status; $current->status=$status; $current->version++;
            if($status===IncidentStatus::Mitigated)$current->mitigated_at=now(); if($status===IncidentStatus::Resolved)$current->resolved_at=now(); $current->save();
            $this->events->appendLocked($current,'StatusChanged',$actorId,['from'=>$from->value,'to'=>$status->value,'version'=>$current->version],$commandId);
            return $current->fresh();
        },3);
    }

    public function assignCommander(Incident $incident, ?int $commanderId, int $expectedVersion, int $actorId, ?string $commandId): Incident
    {
        return DB::transaction(function() use($incident,$commanderId,$expectedVersion,$actorId,$commandId){
            $current=Incident::lockForUpdate()->findOrFail($incident->id);
            if($this->existingCommand($current,$commandId,'CommanderAssigned')) return $current->fresh();
            $this->assertVersion($current,$expectedVersion); $from=$current->commander_user_id; $current->commander_user_id=$commanderId; $current->version++; $current->save();
            if($commanderId!==null) DB::table('incident_participants')->upsert([['incident_id'=>$current->id,'user_id'=>$commanderId,'role'=>'commander','joined_at'=>now()]],['incident_id','user_id'],['role']);
            $this->events->appendLocked($current,'CommanderAssigned',$actorId,['from_user_id'=>$from,'to_user_id'=>$commanderId,'version'=>$current->version],$commandId);
            return $current->fresh();
        },3);
    }

    public function addParticipant(Incident $incident,int $userId,string $role,int $actorId,?string $commandId): array
    {
        return DB::transaction(function() use($incident,$userId,$role,$actorId,$commandId){
            $current=Incident::lockForUpdate()->findOrFail($incident->id);
            if($event=$this->existingCommand($current,$commandId,'ParticipantJoined')) return $event->payload_json;
            DB::table('incident_participants')->upsert([['incident_id'=>$current->id,'user_id'=>$userId,'role'=>$role,'joined_at'=>now()]],['incident_id','user_id'],['role']);
            $event=$this->events->appendLocked($current,'ParticipantJoined',$actorId,['user_id'=>$userId,'role'=>$role],$commandId);
            return $event->payload_json;
        },3);
    }

    public function addComment(Incident $incident,int $userId,string $body,?string $commandId): Comment
    {
        return DB::transaction(function() use($incident,$userId,$body,$commandId){$current=Incident::lockForUpdate()->findOrFail($incident->id);if($event=$this->existingCommand($current,$commandId,'CommentAdded'))return Comment::findOrFail($event->payload_json['comment_id']);$comment=Comment::create(['incident_id'=>$current->id,'user_id'=>$userId,'body'=>$body,'version'=>1]);$this->events->appendLocked($current,'CommentAdded',$userId,['comment_id'=>$comment->id,'body'=>$body,'version'=>1],$commandId);return $comment;},3);
    }

    public function editComment(Incident $incident,Comment $comment,string $body,int $expectedVersion,int $actorId,?string $commandId): Comment
    {
        return DB::transaction(function() use($incident,$comment,$body,$expectedVersion,$actorId,$commandId){$current=Incident::lockForUpdate()->findOrFail($incident->id);if($event=$this->existingCommand($current,$commandId,'CommentEdited'))return Comment::findOrFail($event->payload_json['comment_id']);$locked=Comment::lockForUpdate()->where('incident_id',$current->id)->findOrFail($comment->id);if($locked->version!==$expectedVersion)throw new VersionConflict($locked->toArray());$locked->body=$body;$locked->version++;$locked->edited_at=now();$locked->save();$this->events->appendLocked($current,'CommentEdited',$actorId,['comment_id'=>$locked->id,'body'=>$body,'version'=>$locked->version],$commandId);return $locked->fresh();},3);
    }

    public function addActionItem(Incident $incident,int $actorId,string $title,?int $assigneeId,?string $dueAt,?string $commandId): ActionItem
    {
        return DB::transaction(function() use($incident,$actorId,$title,$assigneeId,$dueAt,$commandId){$current=Incident::lockForUpdate()->findOrFail($incident->id);if($event=$this->existingCommand($current,$commandId,'ActionItemAdded'))return ActionItem::findOrFail($event->payload_json['action_item_id']);$item=ActionItem::create(['incident_id'=>$current->id,'title'=>$title,'assignee_user_id'=>$assigneeId,'status'=>'open','version'=>1,'due_at'=>$dueAt]);$this->events->appendLocked($current,'ActionItemAdded',$actorId,['action_item_id'=>$item->id,'title'=>$item->title,'assignee_user_id'=>$item->assignee_user_id,'due_at'=>$item->due_at?->toISOString(),'version'=>1],$commandId);return $item;},3);
    }

    public function updateActionItem(Incident $incident,ActionItem $item,array $changes,int $expectedVersion,int $actorId,?string $commandId): ActionItem
    {
        return DB::transaction(function() use($incident,$item,$changes,$expectedVersion,$actorId,$commandId){$current=Incident::lockForUpdate()->findOrFail($incident->id);if($event=$this->existingCommand($current,$commandId,'ActionItemUpdated'))return ActionItem::findOrFail($event->payload_json['action_item_id']);$locked=ActionItem::lockForUpdate()->where('incident_id',$current->id)->findOrFail($item->id);if($locked->version!==$expectedVersion)throw new VersionConflict($locked->toArray());foreach(['title','assignee_user_id','status','due_at'] as $key)if(array_key_exists($key,$changes))$locked->{$key}=$changes[$key];$locked->version++;$locked->save();$this->events->appendLocked($current,'ActionItemUpdated',$actorId,['action_item_id'=>$locked->id,'changes'=>$changes,'version'=>$locked->version],$commandId);return $locked->fresh();},3);
    }

    public function completeActionItem(Incident $incident,ActionItem $item,int $expectedVersion,int $actorId,?string $commandId): ActionItem
    { return $this->updateActionItem($incident,$item,['status'=>'completed'],$expectedVersion,$actorId,$commandId); }

    public function saveNote(Incident $incident,string $body,int $expectedNoteVersion,int $actorId,?string $commandId): IncidentNote
    {
        return DB::transaction(function() use($incident,$body,$expectedNoteVersion,$actorId,$commandId){$current=Incident::lockForUpdate()->findOrFail($incident->id);if($event=$this->existingCommand($current,$commandId,'IncidentNoteEdited'))return IncidentNote::findOrFail($event->payload_json['note_id']);$note=IncidentNote::lockForUpdate()->firstOrCreate(['incident_id'=>$current->id],['body'=>'','version'=>1]);if($note->version!==$expectedNoteVersion)throw new VersionConflict($note->toArray());$note->body=$body;$note->version++;$note->edited_by_user_id=$actorId;$note->edited_at=now();$note->save();$this->events->appendLocked($current,'IncidentNoteEdited',$actorId,['note_id'=>$note->id,'body'=>$body,'version'=>$note->version],$commandId);return $note->fresh();},3);
    }

    public function monitoringSignal(Incident $incident,string $source,string $summary,string $level,array $details=[]): IncidentEvent
    {
        return DB::transaction(function() use($incident,$source,$summary,$level,$details){$current=Incident::lockForUpdate()->findOrFail($incident->id);return $this->events->appendLocked($current,'MonitoringSignalDetected',null,['source'=>$source,'summary'=>$summary,'level'=>$level,'details'=>$details]);},3);
    }

    private function existingCommand(Incident $incident,?string $commandId,string $expectedType): ?IncidentEvent {if($commandId===null)return null;$event=IncidentEvent::where('incident_id',$incident->id)->where('client_command_id',$commandId)->first();if($event&&$event->event_type!==$expectedType)throw new ClientCommandConflict();return $event;}
    private function assertVersion(Incident $incident,int $expected):void{if($incident->version!==$expected)throw new VersionConflict($incident->toArray());}
}
