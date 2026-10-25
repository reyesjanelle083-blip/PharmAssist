import { useEffect, useState } from 'react'
import { getBranches } from '../api/index.js'
import { useAuth } from '../context/useAuth.js'

/**
 * Which branch a staff page is about. Staff are tied to their own branch (the backend enforces it); the CEO has no branch of their
 * own and picks one from the public branch list. `branchId` is null until a choice exists.
 */
export function useStaffBranch() {
  const { user, role } = useAuth()
  const [branches, setBranches] = useState([])
  const [branchesFailed, setBranchesFailed] = useState(false)
  const [picked, setPicked] = useState(null)
  const isCeo = role === 'ceo'

  useEffect(() => {
    if (!isCeo) return undefined
    const controller = new AbortController()
    getBranches({ signal: controller.signal })
      .then(({ data }) => setBranches(data.items ?? []))
      .catch((error) => {
        if (error?.name !== 'CanceledError') setBranchesFailed(true)
      })
    return () => controller.abort()
  }, [isCeo])

  const branchId = isCeo ? (picked ?? branches[0]?.id ?? null) : (user?.branch_id ?? null)
  return { isCeo, branchId, branches, branchesFailed, setBranch: setPicked }
}
