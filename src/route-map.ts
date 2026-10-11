import { escapeHTML as esc } from './logic.js';

/** Pure SVG route-map presentation; does not control selected services or browser state. */
export interface RouteMapPoint { lat: number; lon: number; }
export interface RouteMapLeg {
  mode?: string;
  path: readonly RouteMapPoint[];
  stops: readonly RouteMapPoint[];
}
export interface RouteMapJourney {
  legs: readonly RouteMapLeg[];
  stops: readonly RouteMapPoint[];
  destination: { name: string };
}
export interface RouteMapProgress { index: number; }

export function renderRouteMap(j: RouteMapJourney, p: RouteMapProgress, location: RouteMapPoint | null): string {
  const all=j.legs.flatMap(l=>l.path.length?l.path:l.stops.map(s=>({lat:s.lat,lon:s.lon})));
  if(!all.length)return '<p class="muted">Map geometry is unavailable. Follow the station list below.</p>';
  const coords=[...all,...(location?[location]:[])];
  const minLon=Math.min(...coords.map(s=>s.lon)),maxLon=Math.max(...coords.map(s=>s.lon)),minLat=Math.min(...coords.map(s=>s.lat)),maxLat=Math.max(...coords.map(s=>s.lat));
  const spanX=Math.max(.003,maxLon-minLon),spanY=Math.max(.003,maxLat-minLat);
  const x=(lon: number)=>35+(lon-minLon)/spanX*290;
  const y=(lat: number)=>250-(lat-minLat)/spanY*205;
  const legs=j.legs.map(l=>{const path=l.path.length?l.path:l.stops.map(s=>({lat:s.lat,lon:s.lon}));
  return `<polyline points="${path.map(q=>`${x(q.lon).toFixed(1)},${y(q.lat).toFixed(1)}`).join(' ')}" fill="none" stroke="${l.mode==='metro'?'#dc7b3d':'#566c61'}" stroke-width="4.5" stroke-linecap="round" stroke-linejoin="round"/>`;}).join('');
  const markers=j.stops.map((s,i)=>`${i===p.index?`<circle class="map-active-pulse" cx="${x(s.lon)}" cy="${y(s.lat)}" r="11" fill="none" stroke="#ef7a2d" stroke-width="2"/>`:''}<circle cx="${x(s.lon)}" cy="${y(s.lat)}" r="${i===p.index?7:i===j.stops.length-1?6:3.8}" fill="${i===p.index?'#ef7a2d':i===j.stops.length-1?'#202421':'#fff'}" stroke="${i===p.index?'#ef7a2d':'#66766d'}" stroke-width="2"/>`).join('');
  const position=location?`<circle cx="${x(location.lon)}" cy="${y(location.lat)}" r="10" fill="#ef7a2d" opacity=".18"/><circle cx="${x(location.lon)}" cy="${y(location.lat)}" r="4" fill="#ef7a2d"/>`:'';
  return `<svg class="map" role="img" aria-label="Rail route map with station positions and your current location when available" viewBox="0 0 360 280" preserveAspectRatio="xMidYMid meet"><rect x="0" y="0" width="360" height="280" fill="#f2f3ef"/>${legs}${markers}${position}<text x="14" y="270" class="maplabel">${esc(j.legs.length>1?'Rail segments shown separately ·':'Rail route ·')} ${esc(j.destination.name)}</text></svg>`;
}
