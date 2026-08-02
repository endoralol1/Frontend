import { useEffect, useState } from 'react'
import { Link } from 'react-router-dom'
import { api } from '@/lib/api'
import { Badge, statusVariant } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'

export function DashboardPage() {
  const [data, setData] = useState<any>(null)
  const [error, setError] = useState('')

  useEffect(() => {
    api.dashboard().then(setData).catch((e) => setError(e.message))
  }, [])

  if (error) return <p className="text-destructive">{error}</p>
  if (!data) return <p className="text-muted-foreground">Loading dashboard…</p>

  const stats = [
    { label: 'Domains tracked', value: data.stats.total_domains },
    { label: 'Flagged scam', value: data.stats.flagged_scams },
    { label: 'Checked today', value: data.stats.checked_today },
    { label: 'Pending reports', value: data.pending_reports },
  ]

  return (
    <div className="space-y-6">
      <div className="flex flex-wrap items-end justify-between gap-3">
        <div>
          <h1 className="text-2xl font-semibold tracking-tight">Dashboard</h1>
          <p className="text-sm text-muted-foreground">Live overview of checks and discovery.</p>
        </div>
        <Button asChild>
          <Link to="/discovery">Open discovery</Link>
        </Button>
      </div>

      <div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
        {stats.map((s) => (
          <Card key={s.label}>
            <CardHeader className="pb-2">
              <CardDescription>{s.label}</CardDescription>
              <CardTitle className="text-3xl">{Number(s.value).toLocaleString()}</CardTitle>
            </CardHeader>
          </Card>
        ))}
      </div>

      <div className="grid gap-4 lg:grid-cols-2">
        <Card>
          <CardHeader>
            <CardTitle>Recently checked</CardTitle>
          </CardHeader>
          <CardContent className="space-y-3">
            {data.recent_domains.map((d: any) => (
              <div key={d.domain} className="flex items-center justify-between gap-3 text-sm">
                <div className="min-w-0">
                  <div className="truncate font-medium">{d.domain}</div>
                  <div className="text-xs text-muted-foreground">{d.discovered_via || '—'} · score {d.trust_score}</div>
                </div>
                <Badge variant={statusVariant(d.status)}>{d.status}</Badge>
              </div>
            ))}
            {!data.recent_domains.length ? <p className="text-sm text-muted-foreground">No domains yet.</p> : null}
          </CardContent>
        </Card>

        <Card>
          <CardHeader>
            <CardTitle>Recent discovery runs</CardTitle>
            <CardDescription>
              Interval {data.discovery.interval_minutes}m · batch {data.discovery.batch_size}
              {data.discovery.last_run_at ? ` · last ${data.discovery.last_run_at}` : ''}
            </CardDescription>
          </CardHeader>
          <CardContent className="space-y-3">
            {data.recent_runs.map((r: any, i: number) => (
              <div key={`${r.source_name}-${r.started_at}-${i}`} className="flex items-center justify-between gap-3 text-sm">
                <div>
                  <div className="font-medium">{r.source_name}</div>
                  <div className="text-xs text-muted-foreground">{r.started_at}</div>
                </div>
                <div className="text-right text-xs text-muted-foreground">
                  found {r.domains_found} · {r.status}
                </div>
              </div>
            ))}
            {!data.recent_runs.length ? <p className="text-sm text-muted-foreground">No runs yet.</p> : null}
          </CardContent>
        </Card>
      </div>
    </div>
  )
}
