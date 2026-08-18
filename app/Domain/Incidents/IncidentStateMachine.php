<?php
namespace App\Domain\Incidents;use DomainException;
final class IncidentStateMachine{private const ALLOWED=['open'=>['investigating','closed'],'investigating'=>['mitigated','resolved','closed'],'mitigated'=>['investigating','resolved','closed'],'resolved'=>['open','closed'],'closed'=>['open']];public function assert(IncidentStatus $from,IncidentStatus $to):void{if(!in_array($to->value,self::ALLOWED[$from->value]??[],true))throw new DomainException("Invalid incident transition {$from->value} -> {$to->value}");}}
