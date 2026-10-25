import { useId } from 'react'

/**
 * A label, a control and its hint and error messages, wired for assistive technology (label for, aria-describedby, aria-invalid).
 * `children` is a function that receives the props to spread on the control, so one wrapper serves input, textarea and select.
 */
export default function FormField({ label, hint, errors = [], children }) {
  const id = useId()
  const hintId = `${id}-hint`
  const errorId = `${id}-error`
  const describedBy = [hint ? hintId : null, errors.length > 0 ? errorId : null].filter(Boolean).join(' ') || undefined

  return (
    <div className="ui-field">
      <label className="ui-field__label" htmlFor={id}>
        {label}
      </label>
      {children({
        id,
        className: 'ui-field__control',
        'aria-describedby': describedBy,
        'aria-invalid': errors.length > 0 ? 'true' : undefined,
      })}
      {errors.length > 0 ? (
        <p id={errorId} className="ui-field__error">
          {errors.join(' ')}
        </p>
      ) : null}
      {hint ? (
        <small id={hintId} className="ui-field__hint">
          {hint}
        </small>
      ) : null}
    </div>
  )
}
