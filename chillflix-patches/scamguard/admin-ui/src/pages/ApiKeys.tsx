import { useEffect, useState } from 'react'
import { api } from '@/lib/api'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'

export function ApiKeysPage() {
  const [keys, setKeys] = useState<any[]>([])
  const [label, setLabel] = useState('')
  const [email, setEmail] = useState('')
  const [rate, setRate] = useState(1000)
  const [created, setCreated] = useState('')
  const [error, setError] = useState('')

  async function load() {
    const res = await api.apiKeysGet()
    setKeys(res.keys || [])
  }

  useEffect(() => {
    load().catch((e) => setError(e.message))
  }, [])

  async function create() {
    setError('')
    setCreated('')
    try {
      const res = await api.apiKeysAction({ op: 'create', label, owner_email: email, rate_limit_per_day: rate })
      setCreated((res as any).api_key || '')
      setLabel('')
      setEmail('')
      await load()
    } catch (e) {
      setError(e instanceof Error ? e.message : 'Failed')
    }
  }

  return (
    <div className="space-y-6">
      <div>
        <h1 className="text-2xl font-semibold tracking-tight">API Keys</h1>
        <p className="text-sm text-muted-foreground">Issue keys for external domain lookups.</p>
      </div>
      {created ? <p className="rounded-md border border-emerald-200 bg-emerald-50 px-3 py-2 text-sm text-emerald-800">Created key: <code>{created}</code></p> : null}
      {error ? <p className="text-sm text-destructive">{error}</p> : null}
      <Card>
        <CardHeader><CardTitle>Create key</CardTitle></CardHeader>
        <CardContent className="grid gap-3 md:grid-cols-3">
          <div className="space-y-2"><Label>Label</Label><Input value={label} onChange={(e) => setLabel(e.target.value)} /></div>
          <div className="space-y-2"><Label>Owner email</Label><Input value={email} onChange={(e) => setEmail(e.target.value)} /></div>
          <div className="space-y-2"><Label>Rate / day</Label><Input type="number" value={rate} onChange={(e) => setRate(Number(e.target.value))} /></div>
          <Button className="md:col-span-3 w-fit" onClick={create}>Generate key</Button>
        </CardContent>
      </Card>
      <Card>
        <CardContent className="overflow-x-auto pt-5">
          <table className="w-full text-left text-sm">
            <thead className="text-muted-foreground">
              <tr className="border-b border-border">
                <th className="py-2 pr-3 font-medium">Label</th>
                <th className="py-2 pr-3 font-medium">Key</th>
                <th className="py-2 pr-3 font-medium">Rate</th>
                <th className="py-2 pr-3 font-medium">Status</th>
                <th className="py-2 font-medium">Actions</th>
              </tr>
            </thead>
            <tbody>
              {keys.map((k) => (
                <tr key={k.id} className="border-b border-border/70">
                  <td className="py-2.5 pr-3">{k.label || '—'}</td>
                  <td className="py-2.5 pr-3 font-mono text-xs">{k.api_key}</td>
                  <td className="py-2.5 pr-3">{k.rate_limit_per_day}</td>
                  <td className="py-2.5 pr-3"><Badge variant={Number(k.active) ? 'success' : 'muted'}>{Number(k.active) ? 'active' : 'revoked'}</Badge></td>
                  <td className="py-2.5">
                    {Number(k.active) ? (
                      <Button size="sm" variant="destructive" onClick={() => api.apiKeysAction({ op: 'revoke', id: k.id }).then(load)}>Revoke</Button>
                    ) : null}
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </CardContent>
      </Card>
    </div>
  )
}
