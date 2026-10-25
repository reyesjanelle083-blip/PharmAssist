/** The title block at the top of a page: heading, optional subtitle and optional actions on the right. `headingRef` receives focus on load. */
export default function PageHeader({ title, subtitle, actions, headingRef }) {
  return (
    <div className="ui-page-header">
      <div>
        <h1 className="ui-page-header__title" ref={headingRef} tabIndex={headingRef ? -1 : undefined}>
          {title}
        </h1>
        {subtitle ? <p className="ui-page-header__subtitle">{subtitle}</p> : null}
      </div>
      {actions ? <div className="ui-page-header__actions">{actions}</div> : null}
    </div>
  )
}
