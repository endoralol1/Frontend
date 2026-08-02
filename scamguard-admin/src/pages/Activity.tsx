import { useEffect, useState } from 'react'
import { api } from '@/lib/api'
import { Card, CardContent } from '@/components/ui/card'

export function ActivityPage() {
  const [rows, setRows] = useState<any[]>([])
  const [error, setError] = useState('')

  useEffect(() => {
    api.activity().then((res) => setRows(res.activity || [])).catch((e) => setError(e.message))
  }, [])

  return (
    <div className="space-y-6">
      <div>
        <h1 className="text-2xl font-semibold tracking-tight">Activity</h1>
        <p className="text-sm text-muted-foreground">Admin audit trail.</p>
      </div>
      {error ? <p className="text-sm text-destructive">{error}</p> : null}
      <Card>
        <CardContent className="overflow-x-auto pt-5">
          <table className="w-full text-left text-sm">
            <thead className="text-muted-foreground">
              <tr className="border-b border-border">
                <th className="py-2 pr-3 font-medium">When</th>
                <th className="py-2 pr-3 font-medium">User</th>
                <th className="py-2 pr-3 font-medium">Action</th>
                <th className="py-2 pr-3 font-medium">Target</th>
                <th className="py-2 font-medium">Details</th>
              </tr>
            </thead>
            <tbody>
              {rows.map((r) => (
                <tr key={r.id} className="border-b border-border/70">
                  <td className="py-2.5 pr-3 text-muted-foreground whitespace-nowrap">{r.created_at}</td>
                  <td className="py-2.5 pr-3">{r.username || '—'}</td>
                  <td className="py-2.5 pr-3 font-medium">{r.action}</td>
                  <td className="py-2.5 pr-3">{r.target || '—'}</td>
                  <td className="py-2.5 text-muted-foreground">{r.details || '—'}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </CardContent>
      </Card>
    </div>
  )
}
