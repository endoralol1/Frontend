import { useEffect, useState } from 'react'
import { HashRouter, Navigate, Outlet, Route, Routes } from 'react-router-dom'
import { api, setCsrf } from '@/lib/api'
import { AdminLayout } from '@/components/layout'
import { LoginPage } from '@/pages/Login'
import { DashboardPage } from '@/pages/Dashboard'
import { DiscoveryPage } from '@/pages/Discovery'
import { DomainsPage } from '@/pages/Domains'
import { ReportsPage } from '@/pages/Reports'
import { SettingsPage } from '@/pages/Settings'
import { ScoringPage } from '@/pages/Scoring'
import { ApiKeysPage } from '@/pages/ApiKeys'
import { ActivityPage } from '@/pages/Activity'

function RequireAuth({ authed, username }: { authed: boolean; username: string }) {
  if (!authed) return <Navigate to="/login" replace />
  return (
    <AdminLayout username={username}>
      <Outlet />
    </AdminLayout>
  )
}

export default function App() {
  const [ready, setReady] = useState(false)
  const [authed, setAuthed] = useState(false)
  const [username, setUsername] = useState('')

  useEffect(() => {
    api
      .session()
      .then((res) => {
        setAuthed(!!res.authenticated)
        setUsername(res.username || '')
        if (res.csrf) setCsrf(res.csrf)
      })
      .finally(() => setReady(true))
  }, [])

  if (!ready) {
    return <div className="flex min-h-screen items-center justify-center text-sm text-muted-foreground">Loading admin…</div>
  }

  return (
    <HashRouter>
      <Routes>
        <Route
          path="/login"
          element={
            authed ? (
              <Navigate to="/" replace />
            ) : (
              <LoginPage
                onAuthed={(u) => {
                  setAuthed(true)
                  setUsername(u)
                }}
              />
            )
          }
        />
        <Route element={<RequireAuth authed={authed} username={username} />}>
          <Route path="/" element={<DashboardPage />} />
          <Route path="/discovery" element={<DiscoveryPage />} />
          <Route path="/domains" element={<DomainsPage />} />
          <Route path="/reports" element={<ReportsPage />} />
          <Route path="/settings" element={<SettingsPage />} />
          <Route path="/scoring" element={<ScoringPage />} />
          <Route path="/api-keys" element={<ApiKeysPage />} />
          <Route path="/activity" element={<ActivityPage />} />
        </Route>
        <Route path="*" element={<Navigate to="/" replace />} />
      </Routes>
    </HashRouter>
  )
}
