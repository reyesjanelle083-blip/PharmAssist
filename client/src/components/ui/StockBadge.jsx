const LABELS = { available: 'Available', low_stock: 'Low stock', out_of_stock: 'Out of stock' }

/**
 * A branch's stock status (available / low_stock / out_of_stock, from inventory-service). This is a different domain from
 * the reservation StatusBadge: never mix the two. The text is always shown, never colour alone.
 */
export default function StockBadge({ status }) {
  const key = LABELS[status] ? status : 'available'
  return <span className={`ui-stock-badge ui-stock-badge--${key}`}>{LABELS[key]}</span>
}
