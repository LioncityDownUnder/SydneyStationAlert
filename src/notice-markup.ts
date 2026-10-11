import { escapeHTML as esc } from './logic.js';

/** Browser/UI-independent rendering of informational commuter notices. */
export type NotificationPermissionState = NotificationPermission | 'unsupported';

/**
 * Preserve the existing collapsible notice and cautious guidance about
 * limitations when the browser is backgrounded or the phone is locked.
 */
export function renderNotificationReliabilityNotice(
  permission: NotificationPermissionState,
  expanded: boolean
): string {
  const status = permission === 'granted'
    ? 'Notifications enabled while the app is active.'
    : permission === 'denied'
      ? 'Notifications blocked in browser settings.'
      : permission === 'default'
        ? 'Notifications not yet enabled.'
        : 'Notifications unavailable in this browser.';

  return '<details id="alert-reliability-notice" class="notice"'
    + (expanded ? ' open' : '')
    + '><summary>Alert Reliability Notice</summary><p>'
    + esc(status)
    + ' Location and timetable checks may stop when your phone is locked or the browser is suspended. Keep the app open for best results; do not rely on it as your only stop reminder.</p></details>';
}

/** Official TfNSW alternatives; only shown where the rail-pilot UI requests them. */
export function renderRailTravelLinks(enabled: boolean): string {
  return enabled
    ? '<p class="muted">Check <a href="https://transportnsw.info/alerts" target="_blank" rel="noopener noreferrer">TfNSW service alerts</a> or <a href="https://transportnsw.info/trip" target="_blank" rel="noopener noreferrer">TfNSW Trip Planner</a> for other transport arrangements.</p>'
    : '';
}
