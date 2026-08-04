export const demoModeEnabled = import.meta.env.VITE_DEMO_MODE === 'true'

export type DataSource = 'live' | 'demo'

export function sourceLabel(source: DataSource) {
  return source === 'demo' ? 'Demo Data' : 'Live data'
}
