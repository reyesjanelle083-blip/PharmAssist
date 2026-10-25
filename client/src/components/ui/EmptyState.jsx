/** "Nothing here yet": an icon (a Bootstrap Icons class), a title, a line of help and optional actions (buttons or links). */
export default function EmptyState({ icon = 'bi bi-folder2-open', title, children, actions }) {
  return (
    <div className="ui-state ui-state--empty">
      <div className="ui-state__icon">
        <i className={icon} aria-hidden="true"></i>
      </div>
      <h2 className="ui-state__title">{title}</h2>
      {children ? <p className="ui-state__text">{children}</p> : null}
      {actions ? <div className="ui-state__actions">{actions}</div> : null}
    </div>
  )
}
