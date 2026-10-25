import { useContext } from 'react'
import { SearchContext } from './searchContextObject.js'

export function useSearch() {
  const value = useContext(SearchContext)
  if (value === null) throw new Error('useSearch must be used inside <SearchProvider>')
  return value
}
