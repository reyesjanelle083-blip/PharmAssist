import { STATUS_LABELS } from '../../utils/status.js'

/** A reservation status as text (never colour alone), in the old status colours. The names come from the backend lifecycle. */
export default function StatusBadge({ status }) {
  return <span className={`ui-status ui-status--${status}`}>{STATUS_LABELS[status] ?? status}</span>
}
