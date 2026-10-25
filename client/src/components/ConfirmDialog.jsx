import { useRef } from 'react'
import Button from './ui/Button.jsx'
import Modal from './ui/Modal.jsx'

/**
 * An accessible confirm dialog (native <dialog>, see ui/Modal): focus moves into it, Esc or the backdrop cancels it, and focus goes
 * back to where it was. `onCancel` runs for every way of closing it except the confirm button.
 */
export default function ConfirmDialog({ open, title, children, confirmLabel = 'Yes', cancelLabel = 'No', danger = false, onConfirm, onCancel, returnFocusRef }) {
  const confirmed = useRef(false)

  return (
    <Modal
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
        <Button className="confirm-button" onClick={onCancel}>
          {cancelLabel}
        </Button>
        <Button
          variant={danger ? 'danger' : 'primary'}
          className="confirm-button"
          onClick={() => {
            confirmed.current = true
            onConfirm()
          }}
        >
          {confirmLabel}
        </Button>
      </div>
    </Modal>
  )
}
