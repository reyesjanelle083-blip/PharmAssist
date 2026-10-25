/** What a search result card gives us: enough to show the cart line without asking the server again. */
export function lineFromResult(row) {
  return {
    medicineId: row.medicine_id,
    name: row.medicine_name,
    detail: [row.strength, row.dosage_form].filter(Boolean).join(' · '),
    price: String(row.price),
    available: row.available_quantity,
    requiresPrescription: row.prescription_required === true,
    quantity: 1,
  }
}
