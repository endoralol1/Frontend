<?php
$_ssrJson = json_encode([
    'games'      => $initialGames ?? [],
    'total'      => (int) ($initialTotal ?? 0),
    'totalPages' => (int) ($initialPages ?? 1),
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG);
$categories = [
    ['key' => '',            'label' => 'All',         'icon' => 'uil-apps'],
    ['key' => 'Action',      'label' => 'Action',       'icon' => 'uil-bolt-alt'],
    ['key' => 'Adventure',   'label' => 'Adventure',    'icon' => 'uil-compass'],
    ['key' => 'RPG',         'label' => 'RPG',          'icon' => 'uil-dice-five'],
    ['key' => 'Strategy',    'label' => 'Strategy',     'icon' => 'uil-list-ul'],
    ['key' => 'Simulation',  'label' => 'Simulation',   'icon' => 'uil-desktop'],
    ['key' => 'Sports',      'label' => 'Sports',       'icon' => 'uil-football'],
    ['key' => 'Horror',      'label' => 'Horror',       'icon' => 'uil-moon'],
    ['key' => 'Puzzle',      'label' => 'Puzzle',       'icon' => 'uil-puzzle-piece'],
    ['key' => 'Multiplayer', 'label' => 'Multiplayer',  'icon' => 'uil-users-alt'],
    ['key' => 'Indie',       'label' => 'Indie',        'icon' => 'uil-star'],
];
$heroGame = ($initialGames[0] ?? null);
$heroBackdrop = $heroGame['image'] ?? null;
?>
<script>window.__GAMES_SSR__=<?= $_ssrJson ?>;</script>

<main class="page-pad-top games-page">

<div class="container">
    <div class="section games-head-section">
        <div class="head">
            <div class="start">
                <h1 class="title gardiently"><?= e(t('games.title')) ?></h1>
                <span class="games-count" id="games-count-label"></span>
            </div>
        </div>
        <p class="games-lead"><?= e(t('games.lead')) ?></p>
    </div>

    <div class="games-toolbar">
        <form class="games-search-form" id="games-search-form" autocomplete="off" role="search">
            <div class="games-search-wrap">
                <i class="uil uil-search games-search-icon" aria-hidden="true"></i>
                <input type="search" id="games-search-input" name="search"
                    class="games-search-input"
                    placeholder="<?= e(t('games.search')) ?>" aria-label="<?= e(t('games.search')) ?>" autocomplete="off">
            </div>
        </form>
        <div class="games-sort-wrap">
            <select id="games-sort" class="games-sort-select" aria-label="<?= e(t('games.sort')) ?>">
                <option value="newest"><?= e(t('games.newest')) ?></option>
                <option value="az"><?= e(t('games.az')) ?></option>
                <option value="size"><?= e(t('games.largest')) ?></option>
            </select>
        </div>
    </div>

    <div class="games-cats-row">
        <div class="games-cats" id="games-cats" role="group" aria-label="<?= e(t('games.category')) ?>">
            <?php foreach ($categories as $i => $cat): ?>
            <button type="button" class="games-cat<?= $i === 0 ? ' is-active' : '' ?>" data-cat="<?= e($cat['key']) ?>">
                <i class="uil <?= e($cat['icon']) ?>" aria-hidden="true"></i><span><?= e($cat['label']) ?></span>
            </button>
            <?php endforeach; ?>
        </div>
    </div>

    <div id="games-grid" class="games-grid" aria-live="polite" aria-label="<?= e(t('games.title')) ?>">
        <div id="games-ssr-ph" data-ssr="1"></div>
    </div>

    <div class="games-pagination" id="games-pagination" hidden>
        <button type="button" class="games-page-btn" id="games-prev" disabled aria-label="<?= e(t('games.prev_page')) ?>"><i class="uil uil-angle-left" aria-hidden="true"></i></button>
        <span class="games-page-info" id="games-page-info"><?= e(str_replace('{n}', '1', t('games.page_n'))) ?></span>
        <button type="button" class="games-page-btn" id="games-next" aria-label="<?= e(t('games.next_page')) ?>"><i class="uil uil-angle-right" aria-hidden="true"></i></button>
    </div>
</div>
</main>

<div id="games-cd-bar" class="games-cooldown-bar" hidden>
    <i class="uil uil-clock" aria-hidden="true"></i>
    <span><?php $cd = explode('{time}', t('games.cooldown'), 2); echo e($cd[0] ?? ''); ?><strong class="games-cd-time">2:00</strong><?php echo e($cd[1] ?? ''); ?></span>
</div>
<div id="games-toast" class="games-toast" hidden role="status" aria-live="polite"></div>

<style>
/* Page sits on the site aurora — no solid gray wash */
.games-page{
  position:relative;
  background:transparent !important;
  padding-bottom:calc(5.5rem + env(safe-area-inset-bottom,0px));
}
.games-head-section{margin-bottom:.35rem}
.games-count{
  margin-left:auto;
  font-size:.8rem;
  font-weight:600;
  color:rgba(255,255,255,.55);
  white-space:nowrap;
}
.games-lead{
  margin:-.35rem 0 1rem;
  font-size:.9rem;
  color:rgba(255,255,255,.55);
  max-width:36rem;
}

/* toolbar — glass, not solid gray */
.games-toolbar{display:flex;gap:.55rem;margin-bottom:.75rem}
.games-search-form{flex:1;min-width:0}
.games-search-wrap{position:relative}
.games-search-icon{position:absolute;left:.85rem;top:50%;transform:translateY(-50%);color:rgba(255,255,255,.45);pointer-events:none;font-size:1rem}
.games-search-input{
  width:100%;height:2.7rem;padding:0 .9rem 0 2.4rem;
  background:rgba(255,255,255,.04);
  border:1px solid rgba(255,255,255,.1);
  border-radius:.85rem;
  color:#fff;font-size:.9rem;outline:none;
  transition:border-color .15s,background .15s,box-shadow .15s;
  font-family:inherit;backdrop-filter:blur(10px);
}
.games-search-input:focus{
  border-color:rgba(var(--cf-orange-rgb,219,105,55),.55);
  background:rgba(255,255,255,.06);
  box-shadow:0 0 0 3px rgba(var(--cf-orange-rgb,219,105,55),.12);
}
.games-search-input::placeholder{color:rgba(255,255,255,.38)}
.games-sort-wrap{position:relative;flex-shrink:0}
.games-sort-select{
  height:2.7rem;padding:0 2rem 0 .95rem;
  background:rgba(255,255,255,.04);
  border:1px solid rgba(255,255,255,.1);
  border-radius:.85rem;color:rgba(255,255,255,.85);
  font-size:.82rem;font-weight:650;outline:none;cursor:pointer;
  font-family:inherit;-webkit-appearance:none;appearance:none;
  backdrop-filter:blur(10px);
  background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='10' height='6'%3E%3Cpath d='M0 0l5 6 5-6z' fill='%23ffffff99'/%3E%3C/svg%3E");
  background-repeat:no-repeat;background-position:right .8rem center;
}
.games-sort-select:focus{border-color:rgba(var(--cf-orange-rgb,219,105,55),.55)}
.games-sort-select option{background:#12141b;color:#fff}
@media(max-width:560px){
  .games-toolbar{flex-wrap:wrap}
  .games-search-form{flex:1 1 100%}
  .games-sort-wrap{flex:1}
  .games-sort-select{width:100%}
}

/* category chips */
.games-cats-row{margin-bottom:1.1rem;overflow-x:auto;-ms-overflow-style:none;scrollbar-width:none}
.games-cats-row::-webkit-scrollbar{display:none}
.games-cats{display:flex;gap:.4rem;width:max-content;padding-bottom:.1rem}
.games-cat{
  display:inline-flex;align-items:center;gap:.35rem;
  padding:.4rem .8rem;
  background:rgba(255,255,255,.03);
  border:1px solid rgba(255,255,255,.1);
  border-radius:999px;font-size:.78rem;font-weight:650;
  color:rgba(255,255,255,.6);cursor:pointer;transition:all .18s;
  white-space:nowrap;font-family:inherit;backdrop-filter:blur(8px);
}
.games-cat i{font-size:.92rem}
.games-cat:hover{border-color:rgba(var(--cf-orange-rgb,219,105,55),.4);color:var(--cf-orange-soft,#ffd2b8);background:rgba(var(--cf-orange-rgb,219,105,55),.08)}
.games-cat.is-active{
  background:linear-gradient(90deg,var(--cf-orange,#db6937),var(--cf-orange-deep,#c43c2e));
  border-color:transparent;color:#fff;
  box-shadow:0 6px 18px rgba(var(--cf-orange-rgb,219,105,55),.28);
}

/* grid */
.games-grid{
  display:grid;
  grid-template-columns:repeat(auto-fill,minmax(140px,1fr));
  gap:.85rem 0.7rem;
  min-height:200px;
}
@media(min-width:480px){.games-grid{grid-template-columns:repeat(auto-fill,minmax(150px,1fr))}}
@media(min-width:768px){.games-grid{grid-template-columns:repeat(auto-fill,minmax(165px,1fr));gap:1.05rem .85rem}}
@media(min-width:1100px){.games-grid{grid-template-columns:repeat(auto-fill,minmax(175px,1fr))}}
@media(min-width:1400px){.games-grid{grid-template-columns:repeat(auto-fill,minmax(185px,1fr))}}

.games-empty{grid-column:1/-1;text-align:center;padding:3.5rem 1rem;color:rgba(255,255,255,.5)}
.games-empty i{font-size:2.6rem;opacity:.28;display:block;margin-bottom:.75rem}
.games-empty p{margin:0;font-size:.92rem}

/* skeleton — translucent, not gray blocks */
.game-skel{border-radius:.85rem;overflow:hidden;border:1px solid rgba(255,255,255,.06);background:rgba(255,255,255,.03)}
.game-skel-poster{aspect-ratio:2/3;background:linear-gradient(100deg,rgba(255,255,255,.03) 30%,rgba(255,255,255,.07) 50%,rgba(255,255,255,.03) 70%);background-size:200% 100%;animation:g-shimmer 1.6s ease-in-out infinite}
@keyframes g-shimmer{0%{background-position:200% 0}100%{background-position:-200% 0}}

/* cards — poster-first overlay (title/genre on photo, download on hover) */
.game-card{
  position:relative;display:block;border-radius:.85rem;overflow:hidden;
  background:transparent;border:1px solid rgba(255,255,255,.08);
  transition:transform .25s ease,border-color .25s ease,box-shadow .25s ease;
}
.game-card:hover{
  transform:translateY(-5px);
  border-color:rgba(var(--cf-orange-rgb,219,105,55),.4);
  box-shadow:0 16px 36px -10px rgba(0,0,0,.55),0 0 0 1px rgba(var(--cf-orange-rgb,219,105,55),.12);
}
.game-card-poster{position:relative;aspect-ratio:2/3;overflow:hidden;background:rgba(255,255,255,.04)}
.game-card-poster img{width:100%;height:100%;object-fit:cover;display:block;transition:transform .5s ease}
.game-card:hover .game-card-poster img{transform:scale(1.06)}
.game-card-ph{width:100%;height:100%;display:flex;align-items:center;justify-content:center;color:rgba(255,255,255,.25);font-size:2.2rem}
.game-card-shade{
  position:absolute;inset:0;
  background:linear-gradient(to top,rgba(0,0,0,.88) 0%,rgba(0,0,0,.2) 45%,transparent 72%);
  pointer-events:none;
}
.game-card-meta{
  position:absolute;left:0;right:0;bottom:0;
  padding:.55rem .55rem .5rem;
  display:flex;flex-direction:column;gap:.3rem;
}
.game-card-title{
  margin:0;font-size:.78rem;font-weight:700;color:#fff;line-height:1.25;
  display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden;
  text-shadow:0 1px 6px rgba(0,0,0,.85);
}
.game-card-chips{display:flex;flex-wrap:wrap;gap:.2rem}
.game-card-chip{
  font-size:.58rem;font-weight:650;padding:.1rem .34rem;
  background:rgba(0,0,0,.45);color:rgba(255,255,255,.88);
  border-radius:.25rem;backdrop-filter:blur(4px);white-space:nowrap;
}
.game-card-genres{display:flex;flex-wrap:wrap;gap:.18rem}
.game-card-genre{
  font-size:.58rem;font-weight:650;padding:.1rem .34rem;
  background:rgba(var(--cf-orange-rgb,219,105,55),.18);border:1px solid rgba(var(--cf-orange-rgb,219,105,55),.3);
  color:var(--cf-orange-soft,#ffd2b8);border-radius:.25rem;white-space:nowrap;
}
.game-card-dl{
  position:absolute;left:.5rem;right:.5rem;bottom:.5rem;
  display:flex;align-items:center;justify-content:center;gap:.3rem;
  padding:.48rem;border:0;border-radius:.55rem;
  background:linear-gradient(90deg,var(--cf-orange,#db6937),var(--cf-orange-deep,#c43c2e));
  color:#fff;font-size:.72rem;font-weight:750;cursor:pointer;
  opacity:0;transform:translateY(8px);
  transition:opacity .2s ease,transform .2s ease,filter .15s;
  font-family:inherit;text-decoration:none;
  box-shadow:0 8px 18px rgba(0,0,0,.35);
  z-index:2;
}
.game-card:hover .game-card-dl,
.game-card:focus-within .game-card-dl{opacity:1;transform:translateY(0)}
.game-card-dl:hover:not(:disabled){filter:brightness(1.08)}
.game-card-dl:disabled{opacity:.55;cursor:not-allowed;filter:none}
.game-card-dl-spin{width:.7rem;height:.7rem;border:1.5px solid rgba(255,255,255,.35);border-top-color:#fff;border-radius:50%;animation:g-spin .7s linear infinite}
@keyframes g-spin{to{transform:rotate(360deg)}}

/* phone: keep overlay info, show download without needing hover */
@media(hover:none),(max-width:768px){
  .game-card-dl{opacity:1;transform:none}
  .game-card-meta{padding-bottom:2.6rem}
}

/* pagination */
.games-pagination{display:flex;align-items:center;justify-content:center;gap:.85rem;margin:1.5rem 0 2.5rem}
.games-page-btn{
  width:2.5rem;height:2.5rem;display:flex;align-items:center;justify-content:center;
  background:rgba(255,255,255,.04);border:1px solid rgba(255,255,255,.1);
  border-radius:.65rem;color:rgba(255,255,255,.7);cursor:pointer;
  transition:all .15s;font-family:inherit;font-size:1.05rem;backdrop-filter:blur(8px);
}
.games-page-btn:hover:not(:disabled){border-color:rgba(var(--cf-orange-rgb,219,105,55),.5);color:var(--cf-orange-soft,#ffd2b8);background:rgba(var(--cf-orange-rgb,219,105,55),.1)}
.games-page-btn:disabled{opacity:.28;cursor:not-allowed}
.games-page-info{font-size:.84rem;font-weight:700;color:rgba(255,255,255,.75);min-width:6rem;text-align:center}

/* floating cooldown / toast */
.games-cooldown-bar{
  position:fixed;bottom:5.5rem;left:50%;transform:translateX(-50%);
  display:flex;align-items:center;gap:.5rem;padding:.55rem 1rem;
  background:rgba(20,16,14,.82);border:1px solid rgba(var(--cf-orange-rgb,219,105,55),.35);
  backdrop-filter:blur(14px);border-radius:999px;font-size:.8rem;color:var(--cf-orange-soft,#ffd2b8);
  z-index:9998;box-shadow:0 10px 28px rgba(0,0,0,.45);
}
.games-toast{
  position:fixed;bottom:5.5rem;left:50%;transform:translateX(-50%);
  background:rgba(20,16,14,.88);border:1px solid rgba(255,255,255,.12);
  backdrop-filter:blur(14px);color:#fff;font-size:.82rem;padding:.55rem 1.1rem;
  border-radius:999px;white-space:nowrap;z-index:9999;box-shadow:0 10px 28px rgba(0,0,0,.5);
  pointer-events:none;
}
.games-toast.err{border-color:rgba(220,53,69,.45);color:#f0848f}
</style>

<script>
(function(){
    var BASE='<?= rtrim(e(url('')),'/') ?>';
    var COOLDOWN_KEY='games:lastDownloadAt';
    var COOLDOWN_MS=120000;
    var page=1,totalPages=1,category='',search='',sort='newest',debTimer,toastTimer,cdTimer;

    function getCooldownRemaining(){
        var last=Number(localStorage.getItem(COOLDOWN_KEY)||0);
        return Math.max(0,COOLDOWN_MS-(Date.now()-last));
    }
    function isOnCooldown(){ return getCooldownRemaining()>0; }
    function markCooldown(){
        localStorage.setItem(COOLDOWN_KEY,String(Date.now()));
        window.dispatchEvent(new Event('game-download-cooldown'));
        startCooldownUI();
    }
    function fmtCooldown(ms){
        var s=Math.ceil(ms/1000),m=Math.floor(s/60);
        return m+':'+(s%60).toString().padStart(2,'0');
    }
    function updateCooldownBar(){
        var bar=document.getElementById('games-cd-bar');
        var rem=getCooldownRemaining();
        if(!bar) return;
        if(rem>0){
            bar.hidden=false;
            var t=bar.querySelector('.games-cd-time');
            if(t) t.textContent=fmtCooldown(rem);
        } else bar.hidden=true;
    }
    function startCooldownUI(){
        clearInterval(cdTimer);
        updateCooldownBar();
        cdTimer=setInterval(function(){
            if(getCooldownRemaining()<=0) clearInterval(cdTimer);
            updateCooldownBar();
            refreshAllDLButtons();
        },1000);
    }
    function refreshAllDLButtons(){
        var on=isOnCooldown(), rem=getCooldownRemaining();
        document.querySelectorAll('.game-card-dl[data-gid]').forEach(function(btn){
            if(btn.dataset.downloading) return;
            if(on){ btn.disabled=true; btn.innerHTML='&#9203; '+fmtCooldown(rem); }
            else { btn.disabled=false; btn.innerHTML='&#8595; Download'; }
        });
    }

    function qs(s){return document.querySelector(s);}
    function esc(s){var d=document.createElement('div');d.textContent=String(s||'');return d.innerHTML;}
    function stripDL(t){return t.replace(/\s*free\s+download\s*$/i,'').trim();}
    function canvasId(){
        try{
            var c=document.createElement('canvas'),ctx=c.getContext('2d');
            ctx.textBaseline='top';ctx.font='14px Arial';
            ctx.fillStyle='#f60';ctx.fillRect(125,1,62,20);
            ctx.fillStyle='#069';ctx.fillText('CF',2,15);
            return c.toDataURL().slice(-32);
        }catch(e){return Math.random().toString(36).slice(2);}
    }
    function toast(msg,err){
        var t=qs('#games-toast');
        t.textContent=msg;t.className='games-toast'+(err?' err':'');t.hidden=false;
        clearTimeout(toastTimer);toastTimer=setTimeout(function(){t.hidden=true;},4500);
    }

    function handleDL(btn,id){
        if(btn.disabled)return;
        if(isOnCooldown()){ toast('Download cooldown active \u2014 '+fmtCooldown(getCooldownRemaining())+' remaining',true); return; }
        btn.disabled=true; btn.dataset.downloading='1';
        btn.innerHTML='<span class="game-card-dl-spin"></span> Preparing\u2026';
        fetch(BASE+'/api/games/download',{
            method:'POST', headers:{'Content-Type':'application/json'},
            body:JSON.stringify({id:id,canvasId:canvasId()})
        }).then(function(r){return r.json();}).then(function(d){
            delete btn.dataset.downloading;
            if(d.downloadUrl){
                var a=document.createElement('a');
                a.href=d.downloadUrl;a.download=d.fileName||'';a.target='_blank';a.rel='noopener';
                document.body.appendChild(a);a.click();
                setTimeout(function(){document.body.removeChild(a);},200);
                markCooldown(); toast('\u2193 Download started'); btn.innerHTML='\u2713 Started';
            } else {
                toast(d.message||d.error||'Download failed',true);
                btn.disabled=false;btn.innerHTML='\u2193 Download';
            }
        }).catch(function(){
            delete btn.dataset.downloading;
            toast('Network error \u2014 please retry',true);
            btn.disabled=false;btn.innerHTML='\u2193 Download';
        });
    }

    function card(g){
        var t=stripDL(g.title||'');
        var genres=(g.genres||[]).slice(0,2);
        var chips='';
        if(g.releaseDate) chips+='<span class="game-card-chip">'+esc(g.releaseDate)+'</span>';
        if(g.fileSize) chips+='<span class="game-card-chip">'+esc(g.fileSize)+'</span>';
        var genreHtml=genres.map(function(gr){return '<span class="game-card-genre">'+esc(gr)+'</span>';}).join('');
        var img=g.image
            ? '<img src="'+esc(g.image)+'" alt="'+esc(t)+'" loading="lazy" decoding="async">'
            : '<div class="game-card-ph"><i class="uil uil-gamepad"></i></div>';
        var dl=g.id
            ? '<button type="button" class="game-card-dl" data-gid="'+esc(g.id)+'">&#8595; Download</button>'
            : (g.downloadUrl?'<a class="game-card-dl" href="'+esc(g.downloadUrl)+'" target="_blank" rel="noopener noreferrer">&#8595; Download</a>':'');
        return '<article class="game-card">'
            +'<div class="game-card-poster">'+img
            +'<div class="game-card-shade"></div>'
            +'<div class="game-card-meta">'
            +'<p class="game-card-title">'+esc(t)+'</p>'
            +(chips?'<div class="game-card-chips">'+chips+'</div>':'')
            +(genreHtml?'<div class="game-card-genres">'+genreHtml+'</div>':'')
            +'</div>'+dl+'</div></article>';
    }

    function skeletons(n){
        var s=''; for(var i=0;i<n;i++) s+='<div class="game-skel"><div class="game-skel-poster"></div></div>';
        return s;
    }

    function load(){
        var grid=qs('#games-grid'),pag=qs('#games-pagination'),lbl=qs('#games-count-label');
        grid.innerHTML=skeletons(12); pag.hidden=true;
        var params=new URLSearchParams({page:page,limit:20,sort:sort});
        if(category) params.set('category',category);
        if(search.trim()) params.set('search',search.trim());
        fetch(BASE+'/api/games/list?'+params)
            .then(function(r){return r.json();})
            .then(function(data){
                if(!data.success||!data.games||!data.games.length){
                    grid.innerHTML='<div class="games-empty"><i class="uil uil-search-alt"></i><p><?= e(t('games.no_games')) ?></p></div>';
                    if(lbl) lbl.textContent='0 games'; return;
                }
                totalPages=data.totalPages||1;
                grid.innerHTML=data.games.map(card).join('');
                qs('#games-prev').disabled=page<=1;
                qs('#games-next').disabled=page>=totalPages;
                qs('#games-page-info').textContent='Page '+page+' / '+totalPages;
                pag.hidden=false;
                if(lbl) lbl.textContent=data.total.toLocaleString()+' games';
                refreshAllDLButtons();
            })
            .catch(function(){
                grid.innerHTML='<div class="games-empty"><i class="uil uil-exclamation-circle"></i><p>Failed to load \u2014 please refresh.</p></div>';
            });
    }

    document.addEventListener('DOMContentLoaded',function(){
        window.addEventListener('game-download-cooldown', startCooldownUI);
        window.addEventListener('storage', function(e){ if(e.key===COOLDOWN_KEY) startCooldownUI(); });
        if(isOnCooldown()) startCooldownUI();

        var ssr=qs('#games-ssr-ph'), init=window.__GAMES_SSR__;
        if(ssr && init && init.games && init.games.length){
            totalPages=init.totalPages||1;
            qs('#games-grid').innerHTML=init.games.map(card).join('');
            qs('#games-prev').disabled=true;
            qs('#games-next').disabled=page>=totalPages;
            qs('#games-page-info').textContent='Page 1 / '+totalPages;
            qs('#games-pagination').hidden=false;
            qs('#games-count-label').textContent=init.total.toLocaleString()+' games';
            refreshAllDLButtons();
        } else load();

        qs('#games-search-form').addEventListener('submit',function(e){e.preventDefault();search=qs('#games-search-input').value;page=1;load();});
        qs('#games-search-input').addEventListener('input',function(){clearTimeout(debTimer);debTimer=setTimeout(function(){search=qs('#games-search-input').value;page=1;load();},420);});
        qs('#games-sort').addEventListener('change',function(){sort=this.value;page=1;load();});
        qs('#games-cats').addEventListener('click',function(e){
            var btn=e.target.closest('.games-cat'); if(!btn) return;
            qs('#games-cats .is-active').classList.remove('is-active');
            btn.classList.add('is-active'); category=btn.dataset.cat; page=1; load();
        });
        qs('#games-prev').addEventListener('click',function(){if(page>1){page--;load();window.scrollTo({top:0,behavior:'smooth'});}});
        qs('#games-next').addEventListener('click',function(){if(page<totalPages){page++;load();window.scrollTo({top:0,behavior:'smooth'});}});
        document.addEventListener('click',function(e){
            var btn=e.target.closest('.game-card-dl[data-gid]');
            if(btn) handleDL(btn, btn.dataset.gid);
        });
    });
})();
</script>
