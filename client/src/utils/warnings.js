// Gateway warning codes in plain language.
const WARNINGS = {
  DISTANCE_FALLBACK: 'Distances are estimates right now',
  ORIGIN_NOT_PROVIDED: 'Share your location to rank branches by distance',
  NO_STOCK_FOUND: 'No branch has this medicine in stock',
}

export function warningText(code) {
  return WARNINGS[code] ?? null
}
