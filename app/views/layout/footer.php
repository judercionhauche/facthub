        </main>
    </div>
    <footer class="footer">
        <div class="footer-inner">
            <img src="assets/jwafs-logo.svg" alt="J-WAFS" class="footer-logo">
            <div>
                <div class="footer-title">Abdul Latif Jameel Water & Food Systems Lab</div>
                <div class="footer-text">Massachusetts Institute of Technology · 77 Massachusetts Avenue, E38-325 · Cambridge, MA 02139</div>
            </div>
        </div>
    </footer>
</div>

<style>
.xt-wrap{position:fixed!important;bottom:28px!important;right:28px!important;z-index:2147483647!important;display:flex!important;flex-direction:column-reverse!important;gap:10px!important;pointer-events:none!important;width:auto}
.xt{display:flex;align-items:stretch;background:#fff;border-radius:10px;box-shadow:0 1px 3px rgba(0,0,0,.06),0 8px 24px rgba(0,0,0,.12);min-width:340px;max-width:440px;pointer-events:all;position:relative;overflow:hidden;animation:xt-in .3s cubic-bezier(.16,1,.3,1) both;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif}
.xt.xt-out{animation:xt-out .22s ease forwards}
.xt-stripe{width:5px;flex-shrink:0;border-radius:10px 0 0 10px}
.xt-stripe.s{background:#16a34a}.xt-stripe.e{background:#dc2626}.xt-stripe.w{background:#d97706}.xt-stripe.i{background:#2563eb}
.xt-body{display:flex;align-items:flex-start;gap:12px;flex:1;padding:15px 10px 16px 16px}
.xt-icon{flex-shrink:0;width:22px;height:22px;border-radius:50%;display:flex;align-items:center;justify-content:center;margin-top:1px}
.xt-icon.s{background:#dcfce7}.xt-icon.e{background:#fee2e2}.xt-icon.w{background:#fef3c7}.xt-icon.i{background:#dbeafe}
.xt-text{flex:1;min-width:0}
.xt-title{font-size:13.5px;font-weight:700;letter-spacing:-.01em;color:#111;margin-bottom:3px;line-height:1.3}
.xt-msg{font-size:12.5px;color:#555;line-height:1.6;word-break:break-word}
.xt-close{background:none;border:none;color:#c5c5c5;font-size:22px;line-height:1;cursor:pointer;padding:12px 14px 0 6px;flex-shrink:0;align-self:flex-start;transition:color .15s}
.xt-close:hover{color:#555}
.xt-bar{position:absolute;bottom:0;left:5px;right:0;height:3px}
.xt-bar.s{background:#16a34a;animation:xt-bar 5s linear forwards}.xt-bar.e{background:#dc2626;animation:xt-bar 5s linear forwards}.xt-bar.w{background:#d97706;animation:xt-bar 5s linear forwards}.xt-bar.i{background:#2563eb;animation:xt-bar 5s linear forwards}
@keyframes xt-in{from{transform:translateX(20px) scale(.97);opacity:0}to{transform:translateX(0) scale(1);opacity:1}}
@keyframes xt-out{from{transform:translateX(0) scale(1);opacity:1}to{transform:translateX(16px) scale(.96);opacity:0}}
@keyframes xt-bar{from{width:100%}to{width:0%}}
</style>
<script>
(function(){
  var wrap = null;
  function getWrap(){
    if(!wrap){wrap=document.createElement('div');wrap.className='xt-wrap';document.body.appendChild(wrap);}
    return wrap;
  }
  var C={
    s:{title:'Success',k:'s',svg:'<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="#16a34a" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>'},
    e:{title:'Error',  k:'e',svg:'<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="#dc2626" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>'},
    w:{title:'Warning',k:'w',svg:'<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="#d97706" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/></svg>'},
    i:{title:'Info',   k:'i',svg:'<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="#2563eb" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>'}
  };
  function key(t){return C[t]?t:t==='success'?'s':t==='error'?'e':t==='warning'?'w':'i';}
  window.showToast=function(type,msg){
    var k=key(type),cfg=C[k],w=getWrap();
    var el=document.createElement('div');el.className='xt';el.setAttribute('role','alert');
    var stripe=document.createElement('div');stripe.className='xt-stripe '+cfg.k;el.appendChild(stripe);
    var body=document.createElement('div');body.className='xt-body';
    body.innerHTML='<div class="xt-icon '+cfg.k+'">'+cfg.svg+'</div><div class="xt-text"><div class="xt-title">'+cfg.title+'</div><div class="xt-msg">'+msg+'</div></div>';
    el.appendChild(body);
    var btn=document.createElement('button');btn.className='xt-close';btn.innerHTML='&times;';btn.setAttribute('aria-label','Dismiss');el.appendChild(btn);
    var bar=document.createElement('div');bar.className='xt-bar '+cfg.k;el.appendChild(bar);
    w.appendChild(el);
    var timer=setTimeout(dismiss,5000);
    function dismiss(){clearTimeout(timer);el.classList.add('xt-out');el.addEventListener('animationend',function(){if(el.parentNode)el.parentNode.removeChild(el);},{once:true});}
    btn.addEventListener('click',dismiss);
  };
  <?php if($flash): ?>
  document.addEventListener('DOMContentLoaded',function(){
    window.showToast(<?=json_encode($flash['type'])?>,<?=json_encode($flash['message'])?>);
  });
  <?php endif; ?>

  // Double-submit prevention: disable submit buttons on POST forms after submission
  document.addEventListener('submit', function(e) {
    var form = e.target;
    if (form.dataset.noLock) return;
    if ((form.method || '').toLowerCase() !== 'post') return;
    var btns = form.querySelectorAll('button[type="submit"], input[type="submit"]');
    setTimeout(function() {
      // If the submission was cancelled (e.g. confirm() dialog rejected), do nothing
      if (e.defaultPrevented) return;
      btns.forEach(function(b) {
        b.disabled = true;
        b.style.opacity = '0.65';
        b.style.cursor = 'not-allowed';
      });
    }, 0);
  }, true);
}());
</script>

</body>
</html>
