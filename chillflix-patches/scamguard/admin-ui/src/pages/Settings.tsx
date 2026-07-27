import { useEffect, useState } from 'react'
import { api } from '@/lib/api'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { Switch } from '@/components/ui/switch'

export function SettingsPage() {
  const [siteName, setSiteName] = useState('')
  const [tagline, setTagline] = useState('')
  const [banner, setBanner] = useState('')
  const [enabled, setEnabled] = useState(false)
  const [message, setMessage] = useState('')
  const [error, setError] = useState('')

  useEffect(() => {
    api.settingsGet().then((res) => {
      setSiteName(res.settings.site_name || '')
      setTagline(res.settings.site_tagline || '')
      setBanner(res.settings.announcement_banner || '')
      setEnabled(!!res.settings.announcement_enabled)
    }).catch((e) => setError(e.message))
  }, [])

  async function save() {
    setMessage('')
    setError('')
    try {
      const res = await api.settingsSave({
        site_name: siteName,
        site_tagline: tagline,
        announcement_banner: banner,
        announcement_enabled: enabled,
      })
      setMessage(res.message || 'Saved.')
    } catch (e) {
      setError(e instanceof Error ? e.message : 'Save failed')
    }
  }

  return (
    <div className="space-y-6">
      <div>
        <h1 className="text-2xl font-semibold tracking-tight">Settings</h1>
        <p className="text-sm text-muted-foreground">Public site copy and announcement banner.</p>
      </div>
      {message ? <p className="text-sm text-emerald-700">{message}</p> : null}
      {error ? <p className="text-sm text-destructive">{error}</p> : null}
      <Card className="max-w-xl">
        <CardHeader><CardTitle>Site</CardTitle></CardHeader>
        <CardContent className="space-y-4">
          <div className="space-y-2"><Label>Site name</Label><Input value={siteName} onChange={(e) => setSiteName(e.target.value)} /></div>
          <div className="space-y-2"><Label>Tagline</Label><Input value={tagline} onChange={(e) => setTagline(e.target.value)} /></div>
          <div className="flex items-center justify-between rounded-lg border border-border p-3">
            <Label>Show announcement banner</Label>
            <Switch checked={enabled} onCheckedChange={setEnabled} />
          </div>
          <div className="space-y-2">
            <Label>Announcement text</Label>
            <textarea className="min-h-20 w-full rounded-md border border-input bg-card px-3 py-2 text-sm" value={banner} onChange={(e) => setBanner(e.target.value)} />
          </div>
          <Button onClick={save}>Save settings</Button>
        </CardContent>
      </Card>
    </div>
  )
}
