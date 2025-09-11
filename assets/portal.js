/* RightWin QR Portal — portal.js (v1.6.0) */
(function(window, document){
  'use strict';

  /* ------------ Helpers ------------ */
  function qs(sel, ctx){ return (ctx||document).querySelector(sel); }
  function qsa(sel, ctx){ return (ctx||document).querySelectorAll(sel); }
  function on(el, ev, fn){ if(el) el.addEventListener(ev, fn, false); }

  /* ------------ Content-type toggles in Create Wizard ------------ */
  function initContentTypeToggle(){
    // Both classic (#qr_content_type) and generic [name=qr_content_type] supported
    var sel = qs('#qr_content_type') || qs('select[name="qr_content_type"]');
    if(!sel) return;

    var map = {
      link: '.rwqr-ct-link',
      text: '.rwqr-ct-text',
      vcard: '.rwqr-ct-vcard',
      file: '.rwqr-ct-file',
      catalogue: '.rwqr-ct-catalogue',
      price: '.rwqr-ct-price',
      social: '.rwqr-ct-social',
      greview: '.rwqr-ct-greview',
      form: '.rwqr-ct-form',
      image: '.rwqr-ct-image',     // NEW
      video: '.rwqr-ct-video'      // NEW
    };

    function showOne(val){
      try{
        Object.keys(map).forEach(function(k){
          var n = qs(map[k]);
          if(n) n.style.display = 'none';
        });
        var tgt = map[val];
        if (tgt) { var el = qs(tgt); if (el) el.style.display = ''; }
      }catch(e){}
    }

    on(sel, 'change', function(){ showOne((sel.value||'link').toLowerCase()); });
    // Initial
    showOne((sel.value||'link').toLowerCase());
  }

  /* ------------ Dynamic vs Static field toggle ------------ */
  function initModeToggle(){
    var mode = qs('#qr_mode') || qs('select[name="qr_type"]');
    if(!mode) return;
    var dynOnly = qsa('.rwqr-dynamic-only');
    function paint(){
      var isDyn = (mode.value || '').toLowerCase() === 'dynamic';
      dynOnly.forEach(function(n){ n.style.display = isDyn ? '' : 'none'; });
    }
    on(mode,'change',paint); paint();
  }

  /* ------------ Email share: open webmail or mailto reliably ------------ */
  // Matches what the PHP dashboard renders (button.rwqr-mailto[data-mailto="..."])
  window.rwqrOpenMail = function(btn){
    try{
      var url = btn && btn.getAttribute('data-mailto');
      if(!url) return false;
      // If it's a web mail URL, open a new tab; if mailto:, let browser handle
      if(/^https?:\/\//i.test(url)){
        window.open(url, '_blank', 'noopener,noreferrer');
        return false;
      }else{
        // Some themes block default action on <button>; create an <a> and click
        var a = document.createElement('a');
        a.href = url;
        a.style.display = 'none';
        document.body.appendChild(a);
        a.click();
        setTimeout(function(){ if(a && a.parentNode) a.parentNode.removeChild(a); }, 250);
        return false;
      }
    }catch(e){ return true; }
  };

  /* ------------ Disable actions when paused (defensive UX) ------------ */
  function disableWhenPaused(){
    // If a paused badge is present in dashboard, prevent clicks on action buttons nearby
    // (Server already enforces; this is UX sugar)
    qsa('.rwqr-status-paused').forEach(function(badge){
      var row = badge.closest('tr');
      if(!row) return;
      qsa('.rwqr-btn', row).forEach(function(btn){
        // Keep PNG/PDF/Entries clickable; disable stateful actions
        var label = (btn.textContent||'').toLowerCase();
        if(label.indexOf('pause')>-1 || label.indexOf('start')>-1 || label.indexOf('delete')>-1 || label.indexOf('quick edit')>-1){
          btn.classList.add('rwqr-btn-disabled');
          btn.setAttribute('disabled','disabled');
          btn.addEventListener('click', function(e){ e.preventDefault(); e.stopPropagation(); return false; });
        }
      });
    });
  }

  /* ------------ File size hint for logo (respect maxLogoMB localized from PHP) ------------ */
  function enforceLogoLimit(){
    var up = qs('input[type="file"][name="qr_logo"]');
    if(!up || !window.rwqrPortal) return;
    var maxMB = parseFloat(window.rwqrPortal.maxLogoMB || 2);
    if(!(maxMB>0)) return;

    on(up,'change', function(){
      if(!up.files || !up.files[0]) return;
      var sizeMB = up.files[0].size / (1024*1024);
      if(sizeMB > maxMB){
        alert('Logo exceeds maximum ' + maxMB + ' MB. Please choose a smaller file.');
        up.value = '';
      }
    });
  }

  /* ------------ Init on DOM ready ------------ */
  function ready(fn){ if(document.readyState!=='loading') fn(); else document.addEventListener('DOMContentLoaded', fn); }
  ready(function(){
    initContentTypeToggle();
    initModeToggle();
    disableWhenPaused();
    enforceLogoLimit();
  });

})(window, document);
