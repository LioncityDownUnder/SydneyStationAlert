import { fmtTime, escapeHTML as esc } from './logic.js';

/** Stateless Sydney-local train-time labels and interchange presentation. */
export interface DisplayStation {
  id?: string | number | null;
  name?: string | null;
  arrival?: string | null;
  departure?: string | null;
  platform?: string | number | null;
}

export interface DisplayLeg {
  line?: string | null;
  arrival?: string | null;
  departure?: string | null;
  platform?: string | number | null;
  origin?: DisplayStation | null;
  destination?: DisplayStation | null;
}

export interface DisplayJourney {
  legs: readonly DisplayLeg[];
}

/** Preserve the original Sydney calendar-day comparison, including the same 24-hour tomorrow offset. */
export function sydneyDayLabel(value: string, now = new Date()): string {
  const time = new Date(value);
  if (!Number.isFinite(time.getTime())) return '';
  const day = new Intl.DateTimeFormat('en-CA', {
    timeZone: 'Australia/Sydney', year: 'numeric', month: '2-digit', day: '2-digit'
  });
  const current = day.format(now);
  const target = day.format(time);
  if (target === current) return '';
  const tomorrow = new Date(now.getTime() + 86400000);
  if (target === day.format(tomorrow)) return 'Tomorrow, ';
  return new Intl.DateTimeFormat('en-AU', {
    timeZone: 'Australia/Sydney', day: 'numeric', month: 'short'
  }).format(time) + ', ';
}

export function datedTrainTime(value: string | null | undefined): string {
  return value ? sydneyDayLabel(value) + fmtTime(value) : '';
}

export function stopDisplayTime(stop: DisplayStation, index: number): string {
  const value = index === 0
    ? (stop.departure || stop.arrival)
    : (stop.arrival || stop.departure);
  return datedTrainTime(value);
}

export function displayLineName(line: string | null | undefined): string {
  const raw = String(line || '').trim();
  const code = raw.toUpperCase();
  const names: Record<string, string> = {
    SCO: 'South Coast Line',
    SHL: 'Southern Highlands Line',
    BMT: 'Blue Mountains Line',
    CCN: 'Central Coast & Newcastle Line',
    HUN: 'Hunter Line',
    T1: 'T1 North Shore & Western Line',
    T2: 'T2 Inner West & Leppington Line',
    T3: 'T3 Liverpool & Inner West Line',
    T4: 'T4 Eastern Suburbs & Illawarra Line',
    T5: 'T5 Cumberland Line',
    T6: 'T6 Lidcombe & Bankstown Line',
    T7: 'T7 Olympic Park Line',
    T8: 'T8 Airport & South Line',
    T9: 'T9 Northern Line',
    M1: 'M1 Metro North West & Bankstown'
  };
  return names[code] || raw;
}

export function transferDisplay(journey: DisplayJourney, station: DisplayStation): string {
  const index = journey.legs.findIndex(
    (leg, i) => i > 0
      && (leg.origin?.id === station.id || leg.origin?.name === station.name)
  );
  if (index < 1) return '';
  const arriving = journey.legs[index - 1]!;
  const connecting = journey.legs[index]!;
  const arrival = arriving.destination?.arrival || arriving.arrival;
  const departure = connecting.origin?.departure || connecting.departure;
  const arrivalPlatform = arriving.destination?.platform;
  const departurePlatform = connecting.origin?.platform || connecting.platform;
  const format = (time: string | null | undefined, platform: string | number | null | undefined) =>
    [time ? fmtTime(time) : 'Time unavailable',
      platform ? 'Platform ' + platform : 'Platform unavailable'].join(' · ');
  const wait = arrival && departure
    ? Math.round((new Date(departure).getTime() - new Date(arrival).getTime()) / 60000)
    : NaN;
  const waitLabel = Number.isFinite(wait) && wait >= 0 ? ' · ' + wait + ' min to change' : '';
  return `<div class="transfer-detail"><small><span class="transfer-label">ARRIVE · ${esc(displayLineName(arriving.line))}</span>${esc(format(arrival,arrivalPlatform))}</small><small><span class="transfer-label">CONNECT · ${esc(displayLineName(connecting.line))}</span>${esc(format(departure,departurePlatform))}</small><span class="transfer">↗ Change trains here${esc(waitLabel)}</span></div>`;
}
