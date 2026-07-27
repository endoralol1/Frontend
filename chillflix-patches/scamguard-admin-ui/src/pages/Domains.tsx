import { useEffect, useState } from 'react'
import { api } from '@/lib/api'
import { Badge, statusVariant } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'
import { Input } from '@/components/ui/input'

export function DomainsPage() {
  const [search, setSearch] = useState('')
  const [status, setStatus] = useState('')
  const [page, setPage] = useState(1)
  const [data, setData] = useState<any>(null)
  const [bulk, setBulk] = useState('')
  const [message, setMessage] = useState('')
  const [error, setError] = useState('')

  async function load(p = page) {
    const res = await api.domains({ search, status, page: p })
    setData(res)
  }

  useEffect(() => {
    load(1).then(() => setPage(1)).catch((e) => setError(e.message))
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [])

  async function filter(e: React.FormEvent) {
    e.preventDefault()
    setPage(1)
    try {
      await load(1)
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Failed')
    }
  }

  async function act(body: Record<string, unknown>) {
    setMessage('')
    setError('')
    try {
      const res = await api.domainAction(body)
      setMessage(res.message || 'Done.')
      await load(page)
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Action failed')
    }
  }

  if (!data && !error) return <p className="text-muted-foreground">Loading domains…</p>

  return (
    <div className="space-y-6">
      <div>
        <h1 className="text-2xl font-semibold tracking-tight">Domains</h1>
        <p className="text-sm text-muted-foreground">Search, override, and re-check tracked hosts.</p>
      </div>

      {message ? <p className="text-sm text-emerald-700">{message}</p> : null}
      {error ? <p className="text-sm text-destructive">{error}</p> : null}

      <Card>
        <CardContent className="pt-5">
          <form className="flex flex-wrap gap-2" onSubmit={filter}>
            <Input className="max-w-xs" placeholder="Search domain…" value={search} onChange={(e) => setSearch(e.target.value)} />
            <select
              className="h-9 rounded-md border border-input bg-card px-3 text-sm"
              value={status}
              onChange={(e) => setStatus(e.target.value)}
            >
              <option value="">All statuses</option>
              {['safe', 'caution', 'risky', 'scam', 'whitelisted', 'blacklisted', 'unknown'].map((s) => (
                <option key={s} value={s}>{s}</option>
              ))}
            </select>
            <Button type="submit" variant="secondary">Filter</Button>
          </form>
        </CardContent>
      </Card>

      <Card>
        <CardHeader>
          <CardTitle>Bulk add</CardTitle>
        </CardHeader>
        <CardContent className="space-y-3">
          <textarea
            className="min-h-20 w-full rounded-md border border-input bg-card px-3 py-2 text-sm"
            placeholder="Paste domains, one per line or comma-separated"
            value={bulk}
            onChange={(e) => setBulk(e.target.value)}
          />
          <Button
            onClick={() => {
              act({ op: 'bulk_add', bulk_domains: bulk }).then(() => setBulk(''))
            }}
          >
            Add & check
          </Button>
        </CardContent>
      </Card>

      <Card>
        <CardContent className="overflow-x-auto pt-5">
          <table className="w-full text-left text-sm">
            <thead className="text-muted-foreground">
              <tr className="border-b border-border">
                <th className="py-2 pr-3 font-medium">Domain</th>
                <th className="py-2 pr-3 font-medium">Score</th>
                <th className="py-2 pr-3 font-medium">Status</th>
                <th className="py-2 pr-3 font-medium">Age</th>
                <th className="py-2 pr-3 font-medium">Checked</th>
                <th className="py-2 font-medium">Actions</th>
              </tr>
            </thead>
            <tbody>
              {(data?.domains || []).map((d: any) => (
                <tr key={d.id} className="border-b border-border/70 align-top">
                  <td className="py-2.5 pr-3">
                    <a className="font-medium hover:underline" href={`/scamguard/check.php?d=${encodeURIComponent(d.domain)}`} target="_blank" rel="noreferrer">
                      {d.domain}
                    </a>
                  </td>
                  <td className="py-2.5 pr-3">{d.trust_score}</td>
                  <td className="py-2.5 pr-3"><Badge variant={statusVariant(d.status)}>{d.status}</Badge></td>
                  <td className="py-2.5 pr-3">{d.domain_age_days != null ? `${d.domain_age_days}d` : '—'}</td>
                  <td className="py-2.5 pr-3 text-muted-foreground whitespace-nowrap">{d.last_checked || '—'}</td>
                  <td className="py-2.5">
                    <div className="flex flex-wrap gap-1">
                      <Button size="sm" variant="outline" onClick={() => act({ op: 'rescan', id: d.id })}>Re-check</Button>
                      <Button size="sm" variant="secondary" onClick={() => act({ op: 'override', id: d.id, status: 'blacklisted', score: 5, notes: 'Admin blacklist' })}>Blacklist</Button>
                      <Button size="sm" variant="destructive" onClick={() => act({ op: 'delete', id: d.id })}>Delete</Button>
                    </div>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
          <div className="mt-4 flex items-center gap-2">
            <Button size="sm" variant="outline" disabled={page <= 1} onClick={() => { const p = page - 1; setPage(p); load(p) }}>Prev</Button>
            <Button size="sm" variant="outline" disabled={!data || page * data.per_page >= data.total} onClick={() => { const p = page + 1; setPage(p); load(p) }}>Next</Button>
            <span className="text-xs text-muted-foreground">Page {page} · {data?.total ?? 0} total</span>
          </div>
        </CardContent>
      </Card>
    </div>
  )
}
