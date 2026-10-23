import axios from 'axios'

// Only the public gateway URL lives in client code. Never put keys or secrets here.
const client = axios.create({
  baseURL: import.meta.env.VITE_API_BASE_URL,
})

client.interceptors.request.use((config) => {
  config.headers.Accept = 'application/json'
  return config
})

// TODO (later steps): handle 401 (redirect to login) and unwrap the response
// envelope { status, service, request_id, data, error, warnings } here.
client.interceptors.response.use((response) => response)

export default client
