const peso = new Intl.NumberFormat('en-PH', { style: 'currency', currency: 'PHP' })

/** Prices arrive as decimal strings ("12.50"); shown as Philippine pesos. */
export function formatPeso(value) {
  const number = Number(value)
  return Number.isFinite(number) ? peso.format(number) : '-'
}

export function formatTravel(minutes, km) {
  if (minutes === null || minutes === undefined) return null
  const time = minutes < 1 ? 'less than 1 min' : `${minutes} min`
  const distance = km === null || km === undefined ? '' : ` (${Number(km).toFixed(1)} km)`
  return `${time}${distance}`
}
