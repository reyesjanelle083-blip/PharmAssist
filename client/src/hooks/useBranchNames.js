import { useEffect, useState } from 'react'
import { getBranches } from '../api/index.js'

// The reservation answers carry a branch id only. The names come from the public branch list, fetched once and kept.
let cache = null
let request = null

function load() {
  if (cache) return Promise.resolve(cache)
  request ??= getBranches()
    .then(({ data }) => {
      cache = Object.fromEntries((data.items ?? []).map((branch) => [branch.id, branch.name]))
      return cache
    })
    .catch(() => {
      request = null // try again next time
      return {}
    })
  return request
}

/** { [branchId]: name }. Empty until loaded (or when the list cannot be loaded): use branchLabel() for the fallback. */
export function useBranchNames() {
  const [names, setNames] = useState(cache ?? {})

  useEffect(() => {
    let active = true
    load().then((loaded) => {
      if (active) setNames(loaded)
    })
    return () => {
      active = false
    }
  }, [])

  return names
}

export function branchLabel(names, id) {
  return names[id] ?? `Branch #${id}`
}
