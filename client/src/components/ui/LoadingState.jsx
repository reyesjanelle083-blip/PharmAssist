/** A loading message in a polite live region, so screen reader users hear that something is happening. */
export default function LoadingState({ children = 'Loading...' }) {
  return (
    <p role="status" className="ui-state ui-state--loading">
      {children}
    </p>
  )
}
