import ErrorMessage from '../ErrorMessage.jsx'
import Button from './Button.jsx'

/** A failed load: the friendly error with its reference line, and a Try again button when `onRetry` is given. */
export default function ErrorState({ error, onRetry }) {
  return (
    <div>
      <ErrorMessage error={error} />
      {onRetry ? (
        <div className="ui-state__actions">
          <Button onClick={onRetry}>Try again</Button>
        </div>
      ) : null}
    </div>
  )
}
