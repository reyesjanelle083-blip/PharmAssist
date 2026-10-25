import { useRef } from 'react'
import SiteDialog from './SiteDialog.jsx'

/**
 * An accessible confirm dialog (native <dialog>, see SiteDialog): focus moves into it, Esc or the backdrop cancels it, and focus goes
 * back to where it was. `onCancel` runs for every way of closing it except the confirm button.
 */
export default function ConfirmDialog({ open, title, children, confirmLabel = 'Yes', cancelLabel = 'No', danger = false, onConfirm, onCancel, returnFocusRef }) {
  const confirmed = useRef(false)

  return (
    <SiteDialog
      open={open}
      title={title}
      returnFocusRef={returnFocusRef}
      onClose={() => {
        if (confirmed.current) confirmed.current = false
        else onCancel()
      }}
    >
      {children}
      <div className="confirm-actions">
        <button type="button" className="btn-outline btn-outline-blue confirm-button" onClick={onCancel}>
          {cancelLabel}
        </button>
        <button
          type="button"
          className={danger ? 'btn-outline btn-outline-red confirm-button' : 'submit-btn confirm-button'}
          onClick={() => {
            confirmed.current = true
            onConfirm()
          }}
        >
          {confirmLabel}
        </button>
      </div>
    </SiteDialog>
  )
}
