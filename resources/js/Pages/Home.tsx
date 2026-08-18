import {Head} from '@inertiajs/react';
import {FormEvent, useEffect, useRef, useState} from 'react';
import {createEcho} from '../realtime/echo';
import {classifySequence, recoverGap, SequencedEvent} from '../realtime/sequence';

type Session = {token: string; user: any; organizations: any[]};
type IncidentSummary = {id: string; incident_number: number; title: string; severity: string; status: string; version: number; last_sequence: number; organization_id: string};

async function api<T>(path: string, token: string, init: RequestInit = {}): Promise<T> {
    const res = await fetch(`/api${path}`, {
        ...init,
        headers: {
            'Content-Type': 'application/json',
            Accept: 'application/json',
            Authorization: `Bearer ${token}`,
            ...(init.headers || {}),
        },
    });
    const body = await res.json().catch(() => ({}));
    if (!res.ok) {
        const error: any = new Error(body.message || `Request failed (${res.status})`);
        error.status = res.status;
        error.body = body;
        throw error;
    }
    return body;
}

const commandId = () => crypto.randomUUID();

export default function Home() {
    const [session, setSession] = useState<Session | null>(() => {
        try { return JSON.parse(localStorage.getItem('pulse-session') || 'null'); } catch { return null; }
    });
    const [incidents, setIncidents] = useState<IncidentSummary[]>([]);
    const [selected, setSelected] = useState<any | null>(null);
    const [presence, setPresence] = useState<any[]>([]);
    const [members, setMembers] = useState<any[]>([]);
    const [teams, setTeams] = useState<any[]>([]);
    const [preferences, setPreferences] = useState<any | null>(null);
    const [error, setError] = useState('');
    const [live, setLive] = useState<'offline' | 'connecting' | 'live' | 'recovering'>('offline');
    const lastSequence = useRef(0);
    const organization = session?.organizations?.[0] || session?.user?.organizations?.[0];

    useEffect(() => {
        if (!session || !organization?.id) return;
        Promise.all([
            api<IncidentSummary[]>('/incidents', session.token),
            api<any[]>(`/organizations/${organization.id}/members`, session.token),
            api<any[]>(`/organizations/${organization.id}/teams`, session.token),
            api<any>(`/organizations/${organization.id}/notification-preferences`, session.token),
        ]).then(([incidentRows, memberRows, teamRows, prefs]) => {
            setIncidents(incidentRows);
            setMembers(memberRows);
            setTeams(teamRows);
            setPreferences(prefs);
        }).catch(e => setError(e.message));
    }, [session, organization?.id]);

    useEffect(() => {
        if (!session || !selected?.incident?.id) return;
        const id = selected.incident.id;
        let cancelled = false;
        let joinedOnce = false;
        const telemetrySession = crypto.randomUUID();
        let heartbeatTimer: number | undefined;
        const heartbeat = async (reconnect = false) => {
            try {
                await api(`/incidents/${id}/realtime-heartbeat`, session.token, {
                    method: 'POST', body: JSON.stringify({session_id: telemetrySession, reconnect}),
                });
            } catch { /* telemetry never blocks collaboration */ }
        };
        const echo = createEcho(session.token);
        setLive('connecting');
        echo.join(`incidents.${id}`)
            .here((users: any[]) => {
                setPresence(users);
                setLive('live');
                heartbeat(joinedOnce);
                joinedOnce = true;
                if (heartbeatTimer) window.clearInterval(heartbeatTimer);
                heartbeatTimer = window.setInterval(() => heartbeat(false), 20_000);
            })
            .joining((user: any) => setPresence(current => [...current.filter(x => x.id !== user.id), user]))
            .leaving((user: any) => setPresence(current => current.filter(x => x.id !== user.id)))
            .listen('.incident.event', async (event: SequencedEvent) => {
                if (cancelled) return;
                const decision = classifySequence(lastSequence.current, event.sequence);
                if (decision.action === 'ignore') return;
                if (decision.action === 'apply') {
                    lastSequence.current = event.sequence;
                    applyEvent(event);
                    return;
                }
                setLive('recovering');
                try {
                    const replay = await recoverGap(id, lastSequence.current, session.token);
                    if (replay.mode === 'snapshot' && replay.snapshot) {
                        setSelected(replay.snapshot);
                        lastSequence.current = replay.snapshot.incident.last_sequence;
                    } else {
                        for (const replayed of replay.events) {
                            if (replayed.sequence === lastSequence.current + 1) {
                                lastSequence.current = replayed.sequence;
                                applyEvent(replayed);
                            }
                        }
                    }
                    setLive('live');
                } catch (e: any) {
                    setError(e.message);
                    setLive('offline');
                }
            });
        return () => {
            cancelled = true;
            if (heartbeatTimer) window.clearInterval(heartbeatTimer);
            echo.leave(`incidents.${id}`);
            echo.disconnect();
        };
    }, [session, selected?.incident?.id]);

    function applyEvent(event: SequencedEvent) {
        setSelected((previous: any) => previous ? {
            ...previous,
            incident: {...previous.incident, last_sequence: event.sequence},
            events: [...(previous.events || []), event].slice(-300),
        } : previous);
    }

    async function openIncident(id: string) {
        if (!session) return;
        const data = await api<any>(`/incidents/${id}`, session.token);
        lastSequence.current = data.incident.last_sequence;
        setSelected(data);
        setError('');
    }

    async function refreshSelected() {
        if (selected?.incident?.id) await openIncident(selected.incident.id);
    }

    async function command(path: string, method: string, payload: any) {
        if (!session) return;
        try {
            await api(path, session.token, {method, body: JSON.stringify({...payload, client_command_id: commandId()})});
            await refreshSelected();
        } catch (e: any) {
            if (e.status === 409) {
                setError('Another client changed this data first. The latest server state has been reloaded.');
                await refreshSelected();
            } else setError(e.message);
        }
    }

    async function createTeam(name: string) {
        if (!session || !organization?.id) return;
        const team = await api<any>(`/organizations/${organization.id}/teams`, session.token, {method: 'POST', body: JSON.stringify({name})});
        setTeams(current => [...current, team].sort((a, b) => a.name.localeCompare(b.name)));
    }

    async function deleteTeam(id: string) {
        if (!session || !organization?.id) return;
        await api(`/organizations/${organization.id}/teams/${id}`, session.token, {method: 'DELETE'});
        setTeams(current => current.filter(team => team.id !== id));
    }

    async function savePreferences(next: any) {
        if (!session || !organization?.id) return;
        const saved = await api<any>(`/organizations/${organization.id}/notification-preferences`, session.token, {method: 'PUT', body: JSON.stringify(next)});
        setPreferences(saved);
    }

    if (!session) return <Auth onSession={value => { localStorage.setItem('pulse-session', JSON.stringify(value)); setSession(value); }}/>;

    return <>
        <Head title="Pulse incident workspace"/>
        <div className="app-shell">
            <header className="topbar">
                <div><strong>Pulse</strong><span className="muted"> real-time incident command</span></div>
                <div className="top-actions"><span className={`live ${live}`}>{live}</span><span>{session.user.name}</span><button className="ghost" onClick={() => { localStorage.removeItem('pulse-session'); setSession(null); }}>Sign out</button></div>
            </header>
            {error && <div className="alert" onClick={() => setError('')}>{error}</div>}
            <div className="layout">
                <aside className="sidebar">
                    <div className="section-title">Incidents</div>
                    <CreateIncident token={session.token} organizationId={organization?.id} onCreated={incident => { setIncidents(current => [incident, ...current]); openIncident(incident.id); }}/>
                    {incidents.map(incident => <button key={incident.id} className={`incident-row ${selected?.incident?.id === incident.id ? 'active' : ''}`} onClick={() => openIncident(incident.id)}><span className={`sev ${incident.severity}`}>{incident.severity.toUpperCase()}</span><span><b>#{incident.incident_number} {incident.title}</b><small>{incident.status}</small></span></button>)}
                    <WorkspacePanel teams={teams} preferences={preferences} onCreateTeam={createTeam} onDeleteTeam={deleteTeam} onSavePreferences={savePreferences}/>
                </aside>
                <main className="workspace">{selected ? <IncidentRoom data={selected} presence={presence} members={members} command={command}/> : <Empty/>}</main>
            </div>
        </div>
    </>;
}

function Auth({onSession}: {onSession: (session: Session) => void}) {
    const [mode, setMode] = useState<'login' | 'register'>('register');
    const [error, setError] = useState('');
    async function submit(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();
        const payload = Object.fromEntries(new FormData(event.currentTarget).entries());
        try {
            const response = await fetch(`/api/auth/${mode}`, {method: 'POST', headers: {'Content-Type': 'application/json', Accept: 'application/json'}, body: JSON.stringify(payload)});
            const body = await response.json();
            if (!response.ok) throw new Error(body.message || 'Authentication failed');
            onSession({token: body.token, user: body.user, organizations: body.organizations || [body.organization]});
        } catch (e: any) { setError(e.message); }
    }
    return <><Head title="Pulse"/><main className="auth-shell"><section><p className="eyebrow">REAL-TIME SYSTEMS ENGINEERING</p><h1>Pulse</h1><p className="lede">Coordinate production incidents with ordered durable events, presence, replay and explicit concurrency control.</p></section><form className="auth-card" onSubmit={submit}><h2>{mode === 'register' ? 'Create demo workspace' : 'Sign in'}</h2>{error && <div className="alert">{error}</div>}{mode === 'register' && <><input name="name" placeholder="Your name" required/><input name="organization_name" placeholder="Organization" required/></>}<input name="email" type="email" placeholder="Email" required/><input name="password" type="password" placeholder="Password (10+ characters)" minLength={10} required/><button type="submit">{mode === 'register' ? 'Create workspace' : 'Sign in'}</button><button type="button" className="link" onClick={() => setMode(mode === 'register' ? 'login' : 'register')}>{mode === 'register' ? 'Already registered? Sign in' : 'Need a workspace? Register'}</button></form></main></>;
}

function CreateIncident({token, organizationId, onCreated}: {token: string; organizationId?: string; onCreated: (incident: any) => void}) {
    const [title, setTitle] = useState('');
    async function create(event: FormEvent) {
        event.preventDefault();
        if (!organizationId || !title.trim()) return;
        const incident = await api<any>('/incidents', token, {method: 'POST', body: JSON.stringify({organization_id: organizationId, title, severity: 'sev2', client_command_id: commandId()})});
        setTitle('');
        onCreated(incident);
    }
    return <form className="create" onSubmit={create}><input value={title} onChange={event => setTitle(event.target.value)} placeholder="New incident title"/><button>+</button></form>;
}

function IncidentRoom({data, presence, members, command}: {data: any; presence: any[]; members: any[]; command: (path: string, method: string, body: any) => Promise<void>}) {
    const incident = data.incident;
    const note = data.note || {body: '', version: 1};
    const [noteBody, setNoteBody] = useState(note.body || '');
    const [participantUserId, setParticipantUserId] = useState('');
    const [participantRole, setParticipantRole] = useState('responder');
    useEffect(() => setNoteBody(note.body || ''), [note.id, note.version]);
    const commander = members.find(member => String(member.id) === String(incident.commander_user_id));
    const timestamps = [
        ['Started', incident.started_at],
        ['Mitigated', incident.mitigated_at],
        ['Resolved', incident.resolved_at],
    ];

    return <div>
        <div className="incident-head">
            <div><p className="eyebrow">INC-{String(incident.incident_number).padStart(4, '0')}</p><h1>{incident.title}</h1><p className="muted">v{incident.version} · sequence {incident.last_sequence} · commander {commander?.name || 'unassigned'}</p></div>
            <div className="head-controls">
                <select value={incident.severity} onChange={event => command(`/incidents/${incident.id}/severity`, 'PUT', {severity: event.target.value, expected_version: incident.version})}><option>sev1</option><option>sev2</option><option>sev3</option><option>sev4</option></select>
                <select value={incident.status} onChange={event => command(`/incidents/${incident.id}/status`, 'PUT', {status: event.target.value, expected_version: incident.version})}><option>open</option><option>investigating</option><option>mitigated</option><option>resolved</option><option>closed</option></select>
                <select value={incident.commander_user_id || ''} onChange={event => command(`/incidents/${incident.id}/commander`, 'PUT', {commander_user_id: event.target.value ? Number(event.target.value) : null, expected_version: incident.version})}><option value="">No commander</option>{members.map(member => <option key={member.id} value={member.id}>{member.name}</option>)}</select>
            </div>
        </div>
        <div className="timestamp-strip">{timestamps.map(([label, value]) => <div key={label}><small>{label}</small><b>{value ? new Date(value).toLocaleString() : '—'}</b></div>)}</div>
        <div className="presence"><b>{presence.length} connected</b>{presence.map(user => <span key={user.id}>{user.name}</span>)}</div>
        <div className="columns">
            <section className="panel timeline"><h3>Live timeline</h3><CommentBox onSend={body => command(`/incidents/${incident.id}/comments`, 'POST', {body})}/><div className="events">{(data.events || []).map((event: any) => <article key={`${event.id}-${event.sequence}`}><span className="seq">{event.sequence}</span><div><b>{humanize(event.event_type)}</b><small>{event.occurred_at ? new Date(event.occurred_at).toLocaleTimeString() : ''}</small><pre>{JSON.stringify(event.payload_json || event.payload, null, 2)}</pre></div></article>)}</div></section>
            <aside className="right">
                <section className="panel"><h3>Incident notes</h3><textarea value={noteBody} onChange={event => setNoteBody(event.target.value)} rows={8}/><button onClick={() => command(`/incidents/${incident.id}/note`, 'PUT', {body: noteBody, expected_version: note.version || 1})}>Save note</button><small>Note version {note.version || 1}</small></section>
                <section className="panel"><h3>Action items</h3><ActionCreate onAdd={title => command(`/incidents/${incident.id}/action-items`, 'POST', {title})}/>{(data.action_items || []).map((item: any) => <div className="action" key={item.id}><span className={item.status === 'completed' ? 'done' : ''}>{item.title}</span>{item.status !== 'completed' && <button className="ghost" onClick={() => command(`/incidents/${incident.id}/action-items/${item.id}/complete`, 'PUT', {expected_version: item.version})}>Done</button>}</div>)}</section>
                <section className="panel"><h3>Durable participants</h3><div className="participant-controls"><select value={participantUserId} onChange={event => setParticipantUserId(event.target.value)}><option value="">Choose member</option>{members.map(member => <option key={member.id} value={member.id}>{member.name}</option>)}</select><select value={participantRole} onChange={event => setParticipantRole(event.target.value)}><option value="responder">Responder</option><option value="observer">Observer</option><option value="commander">Commander</option></select><button disabled={!participantUserId} onClick={async () => { await command(`/incidents/${incident.id}/participants`, 'POST', {user_id: Number(participantUserId), role: participantRole}); setParticipantUserId(''); }}>Add</button></div>{(data.participants || []).map((participant: any) => <div className="person" key={participant.id}><span>{participant.name}</span><small>{participant.pivot?.role}</small></div>)}</section>
            </aside>
        </div>
    </div>;
}

function WorkspacePanel({teams, preferences, onCreateTeam, onDeleteTeam, onSavePreferences}: {teams: any[]; preferences: any; onCreateTeam: (name: string) => Promise<void>; onDeleteTeam: (id: string) => Promise<void>; onSavePreferences: (value: any) => Promise<void>}) {
    const [teamName, setTeamName] = useState('');
    const [emailEnabled, setEmailEnabled] = useState(false);
    const [webhookEnabled, setWebhookEnabled] = useState(false);
    const [webhookUrl, setWebhookUrl] = useState('');
    useEffect(() => {
        setEmailEnabled(Boolean(preferences?.email_enabled));
        setWebhookEnabled(Boolean(preferences?.webhook_enabled));
        setWebhookUrl(preferences?.webhook_url || '');
    }, [preferences]);
    return <div className="workspace-admin">
        <div className="section-title">Teams</div>
        <form className="mini-form" onSubmit={async event => { event.preventDefault(); if (!teamName.trim()) return; await onCreateTeam(teamName.trim()); setTeamName(''); }}><input value={teamName} onChange={event => setTeamName(event.target.value)} placeholder="Team name"/><button>+</button></form>
        {teams.map(team => <div className="team-row" key={team.id}><span>{team.name}</span><button className="ghost" onClick={() => onDeleteTeam(team.id)}>×</button></div>)}
        <div className="section-title">Notifications</div>
        <label className="check"><input type="checkbox" checked={emailEnabled} onChange={event => setEmailEnabled(event.target.checked)}/> Email</label>
        <label className="check"><input type="checkbox" checked={webhookEnabled} onChange={event => setWebhookEnabled(event.target.checked)}/> Webhook</label>
        {webhookEnabled && <input value={webhookUrl} onChange={event => setWebhookUrl(event.target.value)} placeholder="https://hooks.example.test/incidents"/>}
        <button className="ghost wide" onClick={() => onSavePreferences({email_enabled: emailEnabled, webhook_enabled: webhookEnabled, webhook_url: webhookEnabled ? webhookUrl : null})}>Save notifications</button>
    </div>;
}

function CommentBox({onSend}: {onSend: (body: string) => Promise<void>}) {
    const [value, setValue] = useState('');
    return <form className="comment" onSubmit={async event => { event.preventDefault(); if (!value.trim()) return; const body = value; setValue(''); await onSend(body); }}><input value={value} onChange={event => setValue(event.target.value)} placeholder="Add timeline update…"/><button>Add</button></form>;
}
function ActionCreate({onAdd}: {onAdd: (title: string) => Promise<void>}) {
    const [value, setValue] = useState('');
    return <form className="comment" onSubmit={async event => { event.preventDefault(); if (!value.trim()) return; const title = value; setValue(''); await onAdd(title); }}><input value={value} onChange={event => setValue(event.target.value)} placeholder="New action item"/><button>+</button></form>;
}
function Empty() { return <div className="empty"><h2>Select or create an incident</h2><p>Open the same incident in two browser sessions to exercise presence, ordered events and version conflicts.</p></div>; }
function humanize(value: string) { return value.replace(/([a-z])([A-Z])/g, '$1 $2'); }
