const API_BASE = '/scamguard/admin/api.php'

export type ApiResult<T = Record<string, unknown>> = T & {
  ok: boolean
  error?: string
  message?: string
  csrf?: string
}

let csrfToken: string | null = null

export function setCsrf(token: string | null | undefined) {
  csrfToken = token ?? null
}

export function getCsrf() {
  return csrfToken
}

async function request<T>(
  action: string,
  options: { method?: string; body?: Record<string, unknown>; query?: Record<string, string | number | undefined> } = {},
): Promise<ApiResult<T>> {
  const params = new URLSearchParams({ action })
  if (options.query) {
    for (const [k, v] of Object.entries(options.query)) {
      if (v !== undefined && v !== '') params.set(k, String(v))
    }
  }

  const res = await fetch(`${API_BASE}?${params.toString()}`, {
    method: options.method ?? 'GET',
    credentials: 'include',
    headers: options.body ? { 'Content-Type': 'application/json' } : undefined,
    body: options.body ? JSON.stringify({ ...options.body, csrf: options.body.csrf ?? csrfToken }) : undefined,
  })

  const data = (await res.json()) as ApiResult<T>
  if (data.csrf) setCsrf(data.csrf)
  if (!res.ok && data.ok !== true) {
    throw new Error(data.error || `Request failed (${res.status})`)
  }
  return data
}

export const api = {
  session: () => request<{ authenticated: boolean; username?: string }>('session'),
  login: (username: string, password: string) =>
    request<{ username: string }>('login', { method: 'POST', body: { username, password } }),
  logout: () => request('logout', { method: 'POST', body: {} }),
  dashboard: () => request<any>('dashboard'),
  discoveryGet: () => request<any>('discovery_get'),
  discoverySave: (body: Record<string, unknown>) =>
    request('discovery_save', { method: 'POST', body }),
  discoveryToggle: (source: string) =>
    request('discovery_toggle', { method: 'POST', body: { source } }),
  discoveryPullNow: () => request('discovery_pull_now', { method: 'POST', body: {} }),
  domains: (query: Record<string, string | number | undefined>) =>
    request<any>('domains', { query }),
  domainAction: (body: Record<string, unknown>) =>
    request('domain_action', { method: 'POST', body }),
  reports: (status: string) => request<any>('reports', { query: { status } }),
  reportReview: (id: number, op: 'approve' | 'reject') =>
    request('report_review', { method: 'POST', body: { id, op } }),
  settingsGet: () => request<any>('settings_get'),
  settingsSave: (body: Record<string, unknown>) =>
    request('settings_save', { method: 'POST', body }),
  scoringGet: () => request<any>('scoring_get'),
  scoringSave: (values: Record<string, number>) =>
    request('scoring_save', { method: 'POST', body: { values } }),
  apiKeysGet: () => request<any>('api_keys_get'),
  apiKeysAction: (body: Record<string, unknown>) =>
    request('api_keys_action', { method: 'POST', body }),
  activity: () => request<any>('activity'),
}
