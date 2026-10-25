import { useEffect, useState } from 'react'
import { getMedicineCategories } from '../api/index.js'

/** The categories in the catalog, as select options. Optional: if the call fails, the filter just offers "All categories". */
export function useCategoryOptions() {
  const [categories, setCategories] = useState([])

  useEffect(() => {
    const controller = new AbortController()
    getMedicineCategories({ signal: controller.signal })
      .then(({ data }) => setCategories(data.items ?? []))
      .catch(() => {})
    return () => controller.abort()
  }, [])

  return categories.map((name) => ({ value: name, label: name }))
}
