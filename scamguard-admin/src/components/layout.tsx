import { useEffect, useState, type ReactNode } from 'react'
import { Link, NavLink, useLocation, useNavigate } from 'react-router-dom'
import {
  Activity,
  ExternalLink,
  KeyRound,
  LayoutDashboard,
  LogOut,
  Menu,
  MessageSquare,
  PanelLeftClose,
  PanelLeftOpen,
  Radar,
  Scale,
  Settings,
  ShieldAlert,
  Users,
  X,
} from 'lucide-react'
import { api } from '@/lib/api'
import { Button } from '@/components/ui/button'
import { Separator } from '@/components/ui/separator'
import { cn } from '@/lib/utils'

type NavItem =
  | { type: 'route'; to: string; label: string; icon: typeof LayoutDashboard }
  | { type: 'href'; href: string; label: string; icon: typeof LayoutDashboard }

const NAV: NavItem[] = [
  { type: 'route', to: '/', label: 'Dashboard', icon: LayoutDashboard },
  { type: 'route', to: '/domains', label: 'Domains', icon: ShieldAlert },
  { type: 'route', to: '/reports', label: 'Reports', icon: Activity },
  { type: 'href', href: '/scamguard/admin/community.php', label: 'Community', icon: Users },
  { type: 'href', href: '/scamguard/admin/chat.php', label: 'Support Chat', icon: MessageSquare },
  { type: 'route', to: '/discovery', label: 'Discovery', icon: Radar },
  { type: 'route', to: '/scoring', label: 'Scoring', icon: Scale },
  { type: 'route', to: '/api-keys', label: 'API Keys', icon: KeyRound },
  { type: 'route', to: '/settings', label: 'Settings', icon: Settings },
  { type: 'route', to: '/activity', label: 'Activity', icon: Activity },
]

const SIDEBAR_KEY = 'scamguard-admin-sidebar'

export function AdminLayout({ username, children }: { username: string; children: ReactNode }) {
  const navigate = useNavigate()
  const location = useLocation()
  const [collapsed, setCollapsed] = useState(false)
  const [mobileOpen, setMobileOpen] = useState(false)

  useEffect(() => {
    try {
      setCollapsed(localStorage.getItem(SIDEBAR_KEY) === '1')
    } catch {
      /* ignore */
    }
  }, [])

  useEffect(() => {
    setMobileOpen(false)
  }, [location.pathname])

  useEffect(() => {
    if (!mobileOpen) return
    const onKey = (e: KeyboardEvent) => {
      if (e.key === 'Escape') setMobileOpen(false)
    }
    window.addEventListener('keydown', onKey)
    return () => window.removeEventListener('keydown', onKey)
  }, [mobileOpen])

  function toggleCollapsed() {
    setCollapsed((prev) => {
      const next = !prev
      try {
        localStorage.setItem(SIDEBAR_KEY, next ? '1' : '0')
      } catch {
        /* ignore */
      }
      return next
    })
  }

  async function logout() {
    await api.logout()
    navigate('/login')
    window.location.reload()
  }

  const itemClass = (active: boolean) =>
    cn(
      'inline-flex items-center gap-2 rounded-lg px-3 py-2 text-sm transition-colors',
      collapsed ? 'justify-center md:px-2' : 'w-full',
      active
        ? 'bg-primary text-primary-foreground'
        : 'text-muted-foreground hover:bg-accent hover:text-accent-foreground',
    )

  const sidebar = (
    <>
      <div className={cn('flex items-center justify-between gap-2 px-4 py-4', collapsed && 'md:justify-center md:px-2')}>
        <div className={cn(collapsed && 'md:hidden')}>
          <div className="text-xs font-medium uppercase tracking-[0.16em] text-primary">ScamGuard</div>
          <div className="mt-1 text-lg font-semibold tracking-tight text-foreground">Admin</div>
          <div className="mt-1 text-xs text-muted-foreground">{username}</div>
        </div>
        <Button
          type="button"
          variant="ghost"
          size="icon"
          className="hidden md:inline-flex"
          onClick={toggleCollapsed}
          aria-label={collapsed ? 'Expand sidebar' : 'Collapse sidebar'}
          title={collapsed ? 'Expand' : 'Collapse'}
        >
          {collapsed ? <PanelLeftOpen className="h-4 w-4" /> : <PanelLeftClose className="h-4 w-4" />}
        </Button>
        <Button
          type="button"
          variant="ghost"
          size="icon"
          className="md:hidden"
          onClick={() => setMobileOpen(false)}
          aria-label="Close menu"
        >
          <X className="h-4 w-4" />
        </Button>
      </div>
      <Separator />
      <nav className="flex flex-1 flex-col gap-1 overflow-y-auto px-2 py-3">
        {NAV.map((item) => {
          const Icon = item.icon
          if (item.type === 'href') {
            return (
              <a key={item.href} href={item.href} title={item.label} className={itemClass(false)}>
                <Icon className="h-4 w-4 shrink-0" />
                <span className={cn(collapsed && 'md:hidden')}>{item.label}</span>
              </a>
            )
          }
          return (
            <NavLink
              key={item.to}
              to={item.to}
              end={item.to === '/'}
              title={item.label}
              className={({ isActive }) => itemClass(isActive)}
            >
              <Icon className="h-4 w-4 shrink-0" />
              <span className={cn(collapsed && 'md:hidden')}>{item.label}</span>
            </NavLink>
          )
        })}
      </nav>
      <Separator />
      <div className={cn('flex items-center gap-2 px-3 py-3', collapsed && 'md:flex-col')}>
        <Button variant="outline" size="sm" className={cn('flex-1', collapsed && 'md:w-9 md:px-0')} asChild>
          <a href="/scamguard/" target="_blank" rel="noreferrer" title="View site">
            <ExternalLink className="h-3.5 w-3.5" />
            <span className={cn(collapsed && 'md:hidden')}>Site</span>
          </a>
        </Button>
        <Button variant="ghost" size="sm" className={cn(collapsed && 'md:w-9 md:px-0')} onClick={logout} title="Logout">
          <LogOut className="h-3.5 w-3.5" />
          <span className={cn(collapsed && 'md:hidden')}>Logout</span>
        </Button>
      </div>
    </>
  )

  return (
    <div className="min-h-screen md:flex">
      <div className="sticky top-0 z-40 flex items-center justify-between border-b border-border bg-card/95 px-3 py-2 backdrop-blur md:hidden">
        <div className="flex items-center gap-2">
          <Button type="button" variant="outline" size="icon" onClick={() => setMobileOpen(true)} aria-label="Open menu">
            <Menu className="h-4 w-4" />
          </Button>
          <div>
            <div className="text-[10px] font-medium uppercase tracking-[0.14em] text-primary">ScamGuard</div>
            <div className="text-sm font-semibold">Admin</div>
          </div>
        </div>
        <Link to="/discovery" className="text-xs text-muted-foreground hover:text-foreground">
          Discovery
        </Link>
      </div>

      {mobileOpen ? (
        <div className="fixed inset-0 z-50 md:hidden">
          <button type="button" className="absolute inset-0 bg-black/50" aria-label="Close menu overlay" onClick={() => setMobileOpen(false)} />
          <aside className="relative flex h-full w-[min(18rem,86vw)] flex-col border-r border-border bg-card shadow-xl">
            {sidebar}
          </aside>
        </div>
      ) : null}

      <aside
        className={cn(
          'sticky top-0 hidden h-screen shrink-0 flex-col border-r border-border bg-card md:flex',
          collapsed ? 'w-[4.25rem]' : 'w-60',
        )}
      >
        {sidebar}
      </aside>

      <main className="min-w-0 flex-1 px-4 py-5 md:px-6 md:py-6">{children}</main>
    </div>
  )
}
