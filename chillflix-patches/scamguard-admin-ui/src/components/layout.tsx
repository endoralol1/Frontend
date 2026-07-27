import { NavLink, Outlet, useNavigate } from 'react-router-dom'
import {
  Activity,
  Globe2,
  KeyRound,
  LayoutDashboard,
  LogOut,
  Radar,
  Scale,
  Settings,
  Flag,
  ExternalLink,
} from 'lucide-react'
import { cn } from '@/lib/utils'
import { Button } from '@/components/ui/button'
import { Separator } from '@/components/ui/separator'
import { api } from '@/lib/api'

const nav = [
  { to: '/', label: 'Dashboard', icon: LayoutDashboard },
  { to: '/domains', label: 'Domains', icon: Globe2 },
  { to: '/reports', label: 'Reports', icon: Flag },
  { to: '/discovery', label: 'Discovery', icon: Radar },
  { to: '/scoring', label: 'Scoring', icon: Scale },
  { to: '/api-keys', label: 'API Keys', icon: KeyRound },
  { to: '/settings', label: 'Settings', icon: Settings },
  { to: '/activity', label: 'Activity', icon: Activity },
]

export function AdminLayout({ username, children }: { username: string; children?: React.ReactNode }) {
  const navigate = useNavigate()

  async function logout() {
    await api.logout()
    navigate('/login')
    window.location.reload()
  }

  return (
    <div className="mx-auto flex min-h-screen max-w-7xl flex-col gap-0 md:flex-row md:gap-6 md:p-6">
      <aside className="w-full shrink-0 border-b border-border bg-card/90 backdrop-blur md:w-60 md:rounded-2xl md:border md:shadow-none">
        <div className="flex items-center justify-between px-5 py-4 md:block">
          <div>
            <div className="text-xs font-medium uppercase tracking-[0.16em] text-primary">ScamGuard</div>
            <div className="mt-1 text-lg font-semibold tracking-tight text-foreground">Admin</div>
          </div>
          <div className="hidden text-xs text-muted-foreground md:mt-2 md:block">{username}</div>
        </div>
        <Separator className="hidden md:block" />
        <nav className="flex gap-1 overflow-x-auto px-3 py-3 md:flex-col md:overflow-visible">
          {nav.map((item) => {
            const Icon = item.icon
            return (
              <NavLink
                key={item.to}
                to={item.to}
                end={item.to === '/'}
                className={({ isActive }) =>
                  cn(
                    'inline-flex items-center gap-2 rounded-lg px-3 py-2 text-sm whitespace-nowrap transition-colors',
                    isActive
                      ? 'bg-primary text-primary-foreground'
                      : 'text-muted-foreground hover:bg-accent hover:text-accent-foreground',
                  )
                }
              >
                <Icon className="h-4 w-4" />
                {item.label}
              </NavLink>
            )
          })}
        </nav>
        <Separator className="hidden md:block" />
        <div className="flex items-center gap-2 px-3 py-3">
          <Button variant="outline" size="sm" className="flex-1" asChild>
            <a href="/scamguard/" target="_blank" rel="noreferrer">
              <ExternalLink className="h-3.5 w-3.5" />
              Site
            </a>
          </Button>
          <Button variant="ghost" size="sm" onClick={logout}>
            <LogOut className="h-3.5 w-3.5" />
            Logout
          </Button>
        </div>
      </aside>

      <main className="min-w-0 flex-1 px-4 py-5 md:px-0 md:py-0">{children ?? <Outlet />}</main>
    </div>
  )
}
