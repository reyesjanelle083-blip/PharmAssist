import { useEffect, useId, useRef } from 'react'

/**
 * A modal dialog built on the native <dialog> element: focus moves into it when it opens, Esc closes it, the page behind is inert,
 * and focus goes back to the button that opened it. It is named by its title. No Bootstrap JavaScript.
 * The look is the old guide modal (blue-grey header, white body, rounded corners).
 */
export default function SiteDialog({ open, onClose, title, returnFocusRef, children }) {
  const dialog = useRef(null)
  const titleId = useId()

  useEffect(() => {
    const element = dialog.current
    if (!element) return
    if (open && !element.open) element.showModal()
    if (!open && element.open) element.close()
  }, [open])

  function closed() {
    onClose()
    returnFocusRef?.current?.focus()
  }

  return (
    <dialog
      ref={dialog}
      className="legacy-dialog"
      aria-labelledby={titleId}
      onClose={closed}
      onClick={(event) => {
        if (event.target === dialog.current) dialog.current.close() // a click on the backdrop
      }}
    >
      <div className="legacy-dialog__header">
        <h2 id={titleId}>{title}</h2>
        <button type="button" className="legacy-dialog__close" onClick={() => dialog.current?.close()} aria-label="Close">
          <span aria-hidden="true">&times;</span>
        </button>
      </div>
      <div className="legacy-dialog__body">{children}</div>
    </dialog>
  )
}
