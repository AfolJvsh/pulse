export type SequencedEvent={id:number;sequence:number;event_type:string;payload:Record<string,any>;occurred_at?:string;actor_user_id?:number|null;client_command_id?:string|null};
export type SequenceResult={action:'apply'|'ignore'|'gap';lastSequence:number};
export function classifySequence(lastSequence:number,incoming:number):SequenceResult{if(incoming===lastSequence+1)return{action:'apply',lastSequence:incoming};if(incoming<=lastSequence)return{action:'ignore',lastSequence};return{action:'gap',lastSequence};}
export type ReplayResponse={mode:'events'|'snapshot';events:SequencedEvent[];last_sequence:number;snapshot?:any};
export async function recoverGap(incidentId:string,lastSequence:number,token:string):Promise<ReplayResponse>{const res=await fetch(`/api/incidents/${incidentId}/events?after_sequence=${lastSequence}`,{headers:{Authorization:`Bearer ${token}`}});if(!res.ok)throw new Error('Replay failed');return res.json();}
