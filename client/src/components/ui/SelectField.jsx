import FormField from './FormField.jsx'

/** A labelled drop-down. `options` is [{value, label}]; `placeholder` adds an empty first choice such as "All statuses". */
export default function SelectField({ label, value, onChange, options, placeholder, hint, errors, ...rest }) {
  return (
    <FormField label={label} hint={hint} errors={errors}>
      {(controlProps) => (
        <select {...controlProps} value={value} onChange={(event) => onChange(event.target.value)} {...rest}>
          {placeholder !== undefined ? <option value="">{placeholder}</option> : null}
          {options.map((option) => (
            <option key={option.value} value={option.value}>
              {option.label}
            </option>
          ))}
        </select>
      )}
    </FormField>
  )
}
