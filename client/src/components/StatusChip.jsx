import { STATUS_LABELS } from '../utils/status.js'

/** The status as text (never colour alone), in the old status colours. */
export default function StatusChip({ status }) {
  return <span className={`status-${status}`}>{STATUS_LABELS[status] ?? status}</span>
}
