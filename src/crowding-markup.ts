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

function hasKnownCarriages(leg: RailLegCrowding): boolean {
  return Array.isArray(leg.carriages)
    && leg.carriages.some(car => Boolean(car.level && car.level !== 'unknown'));
}

/** Use overall crowding only when there is no usable per-carriage information. */
export function crowdingSummary(journey: CrowdingJourney): string {
  const crowding = journey?.crowding;
  if (!crowdingCurrentlyVisible(crowding) || !crowding?.available || !crowding.level || crowding.level === 'unknown') return '';

  if (Array.isArray(crowding.legs) && crowding.legs.some(hasKnownCarriages)) return '';

  const label = LEVEL_LABELS[crowding.level];
  return label ? `<div class="stateline">Crowding: ${esc(label)}</div>` : '';
}

/** Render only the first leg with usable carriage levels, preserving TfNSW order by position. */
export function carriageCrowding(journey: CrowdingJourney): string {
  const legs = journey?.crowding?.legs;
  if (!crowdingCurrentlyVisible(journey?.crowding) || !Array.isArray(legs)) return '';

  const leg = legs.find(item=>item.vehicleMatched && item.updatedAt && hasKnownCarriages(item));
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

  return `<div class="stateline"><strong>Carriage crowding · ${esc(String(cars.length))}-car ${esc(trainType)}</strong></div><div class="crowding-bars" style="--car-count:${esc(String(cars.length))}" aria-label="Carriage crowding">${bars}</div><div class="crowding-legend" aria-label="Crowding colour legend"><span><i class="crowding-dot crowding-dot-quiet"></i>Quiet</span><span><i class="crowding-dot crowding-dot-moderate"></i>Moderate</span><span><i class="crowding-dot crowding-dot-busy"></i>Busy</span></div>`;
}
