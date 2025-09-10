/*!
 * RightWin QR Portal — content type switcher (core build)
 * - Matches #qr_content_type and .rwqr-ct-* blocks used in shortcode form
 * - Safe: no crash if some fieldsets not present
 */
(function () {
  'use strict';

  function $(sel, root){ try { return (root||document).querySelector(sel); } catch(e){ return null; } }
  function show(el){ if(el) el.style.display=''; }
  function hide(el){ if(el) el.style.display='none'; }
  function on(el,ev,fn){ if(el && el.addEventListener) el.addEventListener(ev,fn,false); }

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
    image: '.rwqr-ct-image',
    video: '.rwqr-ct-video'
  };

  function update(){
    var sel = $('#qr_content_type') || document.querySelector('select[name="qr_content_type"]');
    if(!sel) return;

    Object.keys(map).forEach(function(k){
      var el = document.querySelector(map[k]);
      hide(el);
    });

    var val = (sel.value || '').toLowerCase();
    if (map[val]){
      show(document.querySelector(map[val]));
    }
  }

  on(document,'DOMContentLoaded',update);
  on(document,'change',function(e){
    var sel = $('#qr_content_type') || document.querySelector('select[name="qr_content_type"]');
    if (e && e.target === sel) update();
  });

  window.updateContentType = update;
})();
