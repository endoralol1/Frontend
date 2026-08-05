/**
 * Vuflix-style cinematic aurora background for Chillflix.
 * Warm orange/red wash on deep ink — same visual language as vuflix.co.
 */
export function StarfieldBackground() {
  return (
    <div
      className="site-ambient-sky cf-ambient fixed inset-0 overflow-hidden pointer-events-none"
      style={{ zIndex: 0 }}
      aria-hidden
    >
      <div className="cf-bgfx">
        <div className="cf-bgfx-aurora" />
        <div className="cf-bgfx-wave cf-bgfx-wave--a" />
        <div className="cf-bgfx-wave cf-bgfx-wave--b" />
        <div className="cf-bgfx-glow cf-bgfx-glow--tl" />
        <div className="cf-bgfx-glow cf-bgfx-glow--tr" />
        <div className="cf-bgfx-glow cf-bgfx-glow--bl" />
        <div className="cf-bgfx-glow cf-bgfx-glow--br" />
        <div className="cf-bgfx-glow cf-bgfx-glow--mid" />
        <div className="cf-bgfx-grain" />
        <div className="cf-bgfx-vignette" />
      </div>
    </div>
  )
}
