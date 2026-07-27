import { useEffect, useState } from 'react'
import { Play, RefreshCw, Save } from 'lucide-react'
import { api } from '@/lib/api'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { Switch } from '@/components/ui/switch'

export function DiscoveryPage() {
  const [data, setData] = useState<any>(null)
  const [batch, setBatch] = useState(80)
  const [interval, setIntervalMinutes] = useState(5)
  const [rate, setRate] = useState(500)
  const [message, setMessage] = useState('')
  const [error, setError] = useState('')
  const [busy, setBusy] = useState(false)

  async function load() {
    const res = await api.discoveryGet()
    setData(res)
    setBatch(res.settings.discovery_batch_size)
    setIntervalMinutes(res.settings.discovery_interval_minutes)
    setRate(res.settings.discovery_rate_limit_per_hour)
  }

  useEffect(() => {
    load().catch((e) => setError(e.message))
  }, [])

  useEffect(() => {
    if (!data?.pull_running) return
    const t = window.setInterval(() => {
      load().catch(() => undefined)
    }, 4000)
    return () => window.clearInterval(t)
  }, [data?.pull_running])

  async function saveSettings() {
    setBusy(true)
    setMessage('')
    setError('')
    try {
      const res = await api.discoverySave({
        discovery_batch_size: batch,
        discovery_interval_minutes: interval,
        discovery_rate_limit_per_hour: rate,
      })
      setMessage(res.message || 'Saved.')
      await load()
    } catch (e) {
      setError(e instanceof Error ? e.message : 'Save failed')
    } finally {
      setBusy(false)
    }
  }

  async function pullNow() {
    setBusy(true)
    setMessage('')
    setError('')
    try {
      const res = await api.discoveryPullNow()
      setMessage(res.message || 'Pull started.')
      await load()
    } catch (e) {
      setError(e instanceof Error ? e.message : 'Pull failed')
    } finally {
      setBusy(false)
    }
  }

  async function toggleSource(source: string) {
    setBusy(true)
    try {
      await api.discoveryToggle(source)
      await load()
    } catch (e) {
      setError(e instanceof Error ? e.message : 'Toggle failed')
    } finally {
      setBusy(false)
    }
  }

  if (error && !data) return <p className="text-destructive">{error}</p>
  if (!data) return <p className="text-muted-foreground">Loading discovery…</p>

  return (
    <div className="space-y-6">
      <div className="flex flex-wrap items-end justify-between gap-3">
        <div>
          <h1 className="text-2xl font-semibold tracking-tight">Discovery</h1>
          <p className="text-sm text-muted-foreground">
            Cron ticks every minute; runs only when your interval elapses. Pull now bypasses the timer.
          </p>
        </div>
        <div className="flex gap-2">
          <Button variant="outline" onClick={() => load()} disabled={busy}>
            <RefreshCw className="h-4 w-4" />
            Refresh
          </Button>
          <Button onClick={pullNow} disabled={busy || data.pull_running}>
            <Play className="h-4 w-4" />
            {data.pull_running ? 'Pulling…' : 'Pull now'}
          </Button>
        </div>
      </div>

      {message ? <p className="text-sm text-emerald-700">{message}</p> : null}
      {error ? <p className="text-sm text-destructive">{error}</p> : null}

      <div className="grid gap-4 lg:grid-cols-2">
        <Card>
          <CardHeader>
            <CardTitle>Pipeline settings</CardTitle>
            <CardDescription>
              Last run: {data.settings.discovery_last_run_at || 'never'}
              {data.pull_running ? ' · running now' : ''}
            </CardDescription>
          </CardHeader>
          <CardContent className="space-y-4">
            <div className="space-y-2">
              <Label htmlFor="batch">Domains processed per run</Label>
              <Input
                id="batch"
                type="number"
                min={1}
                max={500}
                value={batch}
                onChange={(e) => setBatch(Number(e.target.value))}
              />
            </div>
            <div className="space-y-2">
              <Label htmlFor="interval">Timer (minutes between automatic pulls)</Label>
              <Input
                id="interval"
                type="number"
                min={1}
                max={1440}
                value={interval}
                onChange={(e) => setIntervalMinutes(Number(e.target.value))}
              />
            </div>
            <div className="space-y-2">
              <Label htmlFor="rate">Max domains checked per hour</Label>
              <Input
                id="rate"
                type="number"
                min={1}
                value={rate}
                onChange={(e) => setRate(Number(e.target.value))}
              />
            </div>
            <Button onClick={saveSettings} disabled={busy}>
              <Save className="h-4 w-4" />
              Save settings
            </Button>
          </CardContent>
        </Card>

        <Card>
          <CardHeader>
            <CardTitle>Sources</CardTitle>
            <CardDescription>Enable or disable automatic discovery sources.</CardDescription>
          </CardHeader>
          <CardContent className="space-y-4">
            {data.sources.map((s: any) => (
              <div key={s.name} className="flex items-center justify-between gap-3 rounded-lg border border-border p-3">
                <div>
                  <div className="font-medium">{s.label || s.name}</div>
                  <div className="text-xs text-muted-foreground">
                    Last: {s.last_run_at || 'never'}
                    {s.last_run_found != null ? ` · found ${s.last_run_found}` : ''}
                  </div>
                </div>
                <Switch checked={!!Number(s.enabled)} onCheckedChange={() => toggleSource(s.name)} disabled={busy} />
              </div>
            ))}
          </CardContent>
        </Card>
      </div>

      <Card>
        <CardHeader>
          <CardTitle>Run history</CardTitle>
        </CardHeader>
        <CardContent>
          <div className="overflow-x-auto">
            <table className="w-full text-left text-sm">
              <thead className="text-muted-foreground">
                <tr className="border-b border-border">
                  <th className="py-2 pr-3 font-medium">Source</th>
                  <th className="py-2 pr-3 font-medium">Started</th>
                  <th className="py-2 pr-3 font-medium">Found</th>
                  <th className="py-2 pr-3 font-medium">Queued</th>
                  <th className="py-2 font-medium">Status</th>
                </tr>
              </thead>
              <tbody>
                {data.runs.map((r: any) => (
                  <tr key={r.id} className="border-b border-border/70">
                    <td className="py-2.5 pr-3 font-medium">{r.source_name}</td>
                    <td className="py-2.5 pr-3 text-muted-foreground">{r.started_at}</td>
                    <td className="py-2.5 pr-3">{r.domains_found}</td>
                    <td className="py-2.5 pr-3">{r.domains_queued}</td>
                    <td className="py-2.5">
                      <Badge variant={r.status === 'completed' ? 'success' : r.status === 'failed' ? 'danger' : 'muted'}>
                        {r.status}
                      </Badge>
                    </td>
                  </tr>
                ))}
                {!data.runs.length ? (
                  <tr>
                    <td colSpan={5} className="py-6 text-muted-foreground">
                      No discovery runs logged yet.
                    </td>
                  </tr>
                ) : null}
              </tbody>
            </table>
          </div>
        </CardContent>
      </Card>
    </div>
  )
}
