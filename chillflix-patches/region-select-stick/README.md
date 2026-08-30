# Fix: country/region picker snaps back to Spain

## Cause
`RegionSelect` was a controlled Radix Select bound to the server `region` prop.
On change it wrote the cookie then `router.refresh()`, but until that finished
the `value` prop was still the old country — so the UI snapped back to Spain.

## Fix
- Optimistic local state in `region-select.tsx` / `language-select.tsx`
- Cookie `path=/`, `sameSite=lax`, and `revalidatePath` on region change

## Apply
```bash
bash chillflix-patches/region-select-stick/apply.sh
```
