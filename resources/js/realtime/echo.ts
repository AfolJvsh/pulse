import Echo from 'laravel-echo';
import Pusher from 'pusher-js';
(window as any).Pusher=Pusher;
export function createEcho(token:string){
  const host=import.meta.env.VITE_REVERB_HOST || window.location.hostname;
  const port=Number(import.meta.env.VITE_REVERB_PORT || 8080);
  const scheme=import.meta.env.VITE_REVERB_SCHEME || 'http';
  return new Echo({
    broadcaster:'reverb',
    key:import.meta.env.VITE_REVERB_APP_KEY || 'pulse-local-key',
    wsHost:host,
    wsPort:port,
    wssPort:port,
    forceTLS:scheme==='https',
    enabledTransports:['ws','wss'],
    authEndpoint:'/api/broadcasting/auth',
    auth:{headers:{Authorization:`Bearer ${token}`}},
  });
}
