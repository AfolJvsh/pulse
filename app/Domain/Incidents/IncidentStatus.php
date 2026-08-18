<?php
namespace App\Domain\Incidents;
enum IncidentStatus:string{case Open='open';case Investigating='investigating';case Mitigated='mitigated';case Resolved='resolved';case Closed='closed';}
