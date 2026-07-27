import { useEffect, useState } from 'react'
import { api } from '@/lib/api'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'

export function ScoringPage() {
  const [rows, setRows] = useState<any[]>([])
  const [values, setValues] = useState<Record<string, number>>({})
  const [message, setMessage] = useState('')
  const [error, setError] = useState('')

  useEffect(() => {
    api.scoringGet().then((res) => {
      setRows(res.config || [])
      const next: Record<string, number> = {}
      for (const r of res.config || []) next[r.config_key] = Number(r.config_value)
      setValues(next)
    }).catch((e) => setError(e.message))
  }, [])

  async function save() {
    setMessage('')
    try {
      const res = await api.scoringSave(values)
      setMessage(res.message || 'Saved.')
    } catch (e) {
      setError(e instanceof Error ? e.message : 'Save failed')
    }
  }

  return (
    <div className="space-y-6">
      <div>
        <h1 className="text-2xl font-semibold tracking-tight">Scoring</h1>
        <p className="text-sm text-muted-foreground">Weights and thresholds for trust scores.</p>
      </div>
      {message ? <p className="text-sm text-emerald-700">{message}</p> : null}
      {error ? <p className="text-sm text-destructive">{error}</p> : null}
      <div className="grid gap-3 md:grid-cols-2">
        {rows.map((r) => (
          <Card key={r.config_key}>
            <CardHeader className="pb-2">
              <CardTitle className="text-sm">{r.config_key}</CardTitle>
              <CardDescription>{r.description}</CardDescription>
            </CardHeader>
            <CardContent>
              <Label className="sr-only">{r.config_key}</Label>
              <Input
                type="number"
                step="0.1"
                value={values[r.config_key] ?? 0}
                onChange={(e) => setValues((v) => ({ ...v, [r.config_key]: Number(e.target.value) }))}
              />
            </CardContent>
          </Card>
        ))}
      </div>
      <Button onClick={save}>Save scoring configuration</Button>
    </div>
  )
}
