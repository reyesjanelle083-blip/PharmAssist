import { useEffect, useState } from 'react'
import { getBranches } from '../api/index.js'

// The public branch list (names and coordinates), fetched once and kept, like useBranchNames.
let cache = null
let request = null

function load() {
  if (cache) return Promise.resolve(cache)
  request ??= getBranches()
    .then(({ data }) => {
      cache = data.items ?? []
      return cache
    })
    .catch(() => {
      request = null // try again next time
      return []
    })
  return request
}

/** The active branches: [{ id, name, latitude, longitude, ... }]. Empty until loaded (or when they cannot be loaded). */
export function useBranches() {
  const [branches, setBranches] = useState(cache ?? [])

  useEffect(() => {
    let active = true
    load().then((loaded) => {
      if (active) setBranches(loaded)
    })
    return () => {
      active = false
    }
  }, [])

  return branches
}
