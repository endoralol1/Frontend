import { useEffect, useState } from 'react'
import { api } from '@/lib/api'
import { Button } from '@/components/ui/button'
import { Card, CardContent } from '@/components/ui/card'

const labels: Record<string, string> = {
  phishing: 'Phishing',
  fake_shop: 'Fake Shop',
  crypto_scam: 'Crypto Scam',
  tech_support_scam: 'Tech Support Scam',
  identity_theft: 'Identity Theft',
  other: 'Other',
}

export function ReportsPage() {
  const [status, setStatus] = useState('pending')
  const [data, setData] = useState<any>(null)
  const [message, setMessage] = useState('')
  const [error, setError] = useState('')

  async function load(s = status) {
    setData(await api.reports(s))
  }

  useEffect(() => {
    load().catch((e) => setError(e.message))
  }, [status])

  async function review(id: number, op: 'approve' | 'reject') {
    setMessage('')
    try {
      const res = await api.reportReview(id, op)
      setMessage(res.message || 'Done.')
      await load()
    } catch (e) {
      setError(e instanceof Error ? e.message : 'Failed')
    }
  }

  return (
    <div className="space-y-6">
      <div>
        <h1 className="text-2xl font-semibold tracking-tight">Reports</h1>
        <p className="text-sm text-muted-foreground">Review user-submitted scam reports.</p>
      </div>
      <div className="flex gap-2">
        {['pending', 'approved', 'rejected'].map((s) => (
          <Button key={s} size="sm" variant={status === s ? 'default' : 'outline'} onClick={() => setStatus(s)}>
            {s}
          </Button>
        ))}
      </div>
      {message ? <p className="text-sm text-emerald-700">{message}</p> : null}
      {error ? <p className="text-sm text-destructive">{error}</p> : null}
      <Card>
        <CardContent className="overflow-x-auto pt-5">
          <table className="w-full text-left text-sm">
            <thead className="text-muted-foreground">
              <tr className="border-b border-border">
                <th className="py-2 pr-3 font-medium">Domain</th>
                <th className="py-2 pr-3 font-medium">Category</th>
                <th className="py-2 pr-3 font-medium">Description</th>
                <th className="py-2 pr-3 font-medium">Date</th>
                <th className="py-2 font-medium">Actions</th>
              </tr>
            </thead>
            <tbody>
              {(data?.reports || []).map((r: any) => (
                <tr key={r.id} className="border-b border-border/70 align-top">
                  <td className="py-2.5 pr-3 font-medium">{r.domain_text}</td>
                  <td className="py-2.5 pr-3">{labels[r.category] || r.category}</td>
                  <td className="py-2.5 pr-3 max-w-xs">{r.description || '—'}</td>
                  <td className="py-2.5 pr-3 text-muted-foreground whitespace-nowrap">{r.created_at}</td>
                  <td className="py-2.5">
                    {status === 'pending' ? (
                      <div className="flex gap-1">
                        <Button size="sm" onClick={() => review(r.id, 'approve')}>Approve</Button>
                        <Button size="sm" variant="outline" onClick={() => review(r.id, 'reject')}>Reject</Button>
                      </div>
                    ) : (
                      <span className="text-muted-foreground">Reviewed</span>
                    )}
                  </td>
                </tr>
              ))}
              {!data?.reports?.length ? (
                <tr><td colSpan={5} className="py-6 text-muted-foreground">No {status} reports.</td></tr>
              ) : null}
            </tbody>
          </table>
        </CardContent>
      </Card>
    </div>
  )
}
