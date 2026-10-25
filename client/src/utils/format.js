const peso = new Intl.NumberFormat('en-PH', { style: 'currency', currency: 'PHP' })
const dateTime = new Intl.DateTimeFormat('en-PH', { dateStyle: 'medium', timeStyle: 'short' })

/** Prices arrive as decimal strings ("12.50"); shown as Philippine pesos. */
export function formatPeso(value) {
  const number = Number(value)
  return Number.isFinite(number) ? peso.format(number) : '-'
}

/** Money is added up in centavos so 0.1 + 0.2 never shows as 0.30000000000000004. */
export function toCents(price) {
  const number = Number(price)
  return Number.isFinite(number) ? Math.round(number * 100) : 0
}

export function formatCents(cents) {
  return peso.format(cents / 100)
}

/** An ISO date from the API, shown in the person's own time zone. */
export function formatDateTime(iso) {
  const date = new Date(iso)
  return Number.isNaN(date.getTime()) ? '-' : dateTime.format(date)
}

export function formatTravel(minutes, km) {
  if (minutes === null || minutes === undefined) return null
  const time = minutes < 1 ? 'less than 1 min' : `${minutes} min`
  const distance = km === null || km === undefined ? '' : ` (${Number(km).toFixed(1)} km)`
  return `${time}${distance}`
}
