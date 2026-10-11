import { escapeHTML as esc } from './logic.js';
import { crowdingCurrentlyVisible } from './crowding-safety.js';

/** Markup-only view of the optional TfNSW crowding response. */
interface CarriageCrowding {
  level?: string;
  position?: number | string;
  name?: string;
}

interface RailLegCrowding {
  mode?: string;
  carriages?: CarriageCrowding[];
  vehicleMatched?: boolean;
  updatedAt?: string | null;
  level?: string;
}

interface CrowdingJourney {
  crowding?: {
    available?: boolean;
    level?: string;
    legs?: RailLegCrowding[];
  } | null;
}

const LEVEL_LABELS: Record<string, string> = {
  quiet: 'Quiet',
  moderate: 'Moderate',
  busy: 'Busy',
  very_busy: 'Very busy',
};

function hasKnownCarriages(leg: RailLegCrowding, now: number): boolean {
  return crowdingCurrentlyVisible({available:true, legs:[leg]}, now) && Array.isArray(leg.carriages)
    && leg.carriages.some(car => Boolean(car.level && car.level !== 'unknown'));
}

/**
 * Source observation age, NOT the page load or API request time.
 * Never suggest a missing, stale or clock-skewed observation was just updated.
 */
export function crowdingObservationAgeLabel(observedAt: string | null | undefined, now=Date.now()): string {
  const timestamp=typeof observedAt==='string'&&observedAt.trim()?Date.parse(observedAt):NaN;
  const elapsed=now-timestamp;
  if(!Number.isFinite(elapsed)||elapsed<0||elapsed>5*60*1000)return '';
  const mins=Math.floor(elapsed/60000);
  return mins===0
    ? 'TfNSW crowding observed less than a minute ago'
    : `TfNSW crowding observed ${mins} minute${mins===1?'':'s'} ago`;
}

function freshnessMarkup(leg: RailLegCrowding | undefined, now: number): string {
  if(!leg||!crowdingCurrentlyVisible({available:true,legs:[leg]},now))return '';
  const label=crowdingObservationAgeLabel(leg.updatedAt,now);
  return label?`<div class="muted crowding-freshness">${esc(label)}</div>`:'';
}

/** Use overall crowding only when there is no usable per-carriage information. */
export function crowdingSummary(journey: CrowdingJourney, now=Date.now()): string {
  const crowding = journey?.crowding;
  if (!crowdingCurrentlyVisible(crowding,now) || !crowding?.available || !crowding.level || crowding.level === 'unknown') return '';

  if (Array.isArray(crowding.legs) && crowding.legs.some(leg=>hasKnownCarriages(leg,now))) return '';

  const label = LEVEL_LABELS[crowding.level];
  if(!label)return '';
  const levelLeg=crowding.legs?.find(leg=>leg.level===crowding.level
    && crowdingCurrentlyVisible({available:true,legs:[leg]},now));
  return `<div class="stateline">Crowding: ${esc(label)}</div>`+freshnessMarkup(levelLeg,now);
}

/** Render only the first leg with usable carriage levels, preserving TfNSW order by position. */
export function carriageCrowding(journey: CrowdingJourney, now=Date.now()): string {
  const legs = journey?.crowding?.legs;
  if (!crowdingCurrentlyVisible(journey?.crowding,now) || !Array.isArray(legs)) return '';

  const leg = legs.find(item=>hasKnownCarriages(item,now));
  if (!leg || !Array.isArray(leg.carriages)) return '';

  const cars = leg.carriages
    .filter(car => car.level && car.level !== 'unknown')
    .sort((a, b) =>
      (Number(a.position) > 0 ? Number(a.position) : 999)
      - (Number(b.position) > 0 ? Number(b.position) : 999));
  if (!cars.length) return '';

  const trainType = leg.mode === 'metro' ? 'Metro train' : 'train';
  const bars = cars.map((car, index) => {
    const label = LEVEL_LABELS[car.level ?? ''] || 'Unknown';
    const number = String(index + 1);
    const code = car.name ? ` · TfNSW code ${car.name}` : '';
    return `<span class="crowding-bar crowding-bar-${esc(car.level)}" title="Car ${esc(number)}: ${esc(label)}${esc(code)}" aria-label="Car ${esc(number)}, ${esc(label)}${esc(code)}"></span>`;
  }).join('');

  return `<div class="stateline"><strong>Carriage crowding · ${esc(String(cars.length))}-car ${esc(trainType)}</strong></div><div class="crowding-bars" style="--car-count:${esc(String(cars.length))}" aria-label="Carriage crowding">${bars}</div><div class="crowding-legend" aria-label="Crowding colour legend"><span><i class="crowding-dot crowding-dot-quiet"></i>Quiet</span><span><i class="crowding-dot crowding-dot-moderate"></i>Moderate</span><span><i class="crowding-dot crowding-dot-busy"></i>Busy</span></div>${freshnessMarkup(leg,now)}`;
}
