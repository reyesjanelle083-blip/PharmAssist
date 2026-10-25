export const RX_OPTIONS = [
  { value: 'false', label: 'No prescription needed' },
  { value: 'true', label: 'Prescription required' },
]

/** "Amoxicillin (Amoxil)", or just the generic name when there is no brand. Matches the name the availability search shows. */
export function displayName(medicine) {
  return medicine.brand_name ? `${medicine.generic_name} (${medicine.brand_name})` : medicine.generic_name
}
