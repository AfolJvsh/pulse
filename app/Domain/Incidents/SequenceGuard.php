<?php
namespace App\Domain\Incidents;
final class SequenceGuard{public function decide(int $lastSequence,int $incoming):SequenceDecision{return $incoming===$lastSequence+1?SequenceDecision::Apply:($incoming<=$lastSequence?SequenceDecision::Ignore:SequenceDecision::Gap);}}
