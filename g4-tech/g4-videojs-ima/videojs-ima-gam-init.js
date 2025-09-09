(function(){
  function moveIMAInto(player){
    var root = player.el();
    function handle(node){
      if (!node) return;
      var id = node.id || '';
      var cls = node.className || '';
      var isAd = (id.indexOf('ima-ad-container') === 0) ||
                 (typeof cls === 'string' && (cls.indexOf('ima-ad-container') !== -1 || cls.indexOf('vjs-ima-ad-container') !== -1));
      if (isAd) {
        if (node.parentNode !== root) root.appendChild(node);
        node.classList.remove('ima-visible');
      }
    }
    handle(document.getElementById('ima-ad-container'));
    var obs = new MutationObserver(function(muts){
      muts.forEach(function(m){
        m.addedNodes && m.addedNodes.forEach(function(n){ handle(n); });
      });
    });
    try { obs.observe(document.documentElement || document.body, { childList:true, subtree:true }); } catch(e){}
  }

  function setSlotVisible(wrapper, visible){
    var slot = wrapper.querySelector('.vjs-slot');
    var ph   = wrapper.querySelector('.vjs-placeholder');
    if (!slot || !ph) return;
    if (visible) {
      slot.classList.add('vjs-visible');
      ph.style.visibility = 'hidden';
    } else {
      slot.classList.remove('vjs-visible');
      ph.style.visibility = 'visible';
    }
  }

  function freshTag(base){
    var sep = (base.indexOf('?') !== -1) ? '&' : '?';
    return base + sep + 'correlator=' + Date.now();
  }

  function initPlayer(video){
    var wrapper = video.closest('.vjs-aspect');
    var adTagUrlBase = video.getAttribute('data-vast') || '';
    var adTagUrl = adTagUrlBase;
    var fallback = (video.getAttribute('data-fallback') || 'placeholder');
    var locale = (video.getAttribute('data-locale') || 'pt');

    var loopAds   = video.getAttribute('data-loopads') === 'true';
    var loopDelay = Math.max(0, parseInt(video.getAttribute('data-loop-delay') || '5', 10)) * 1000;
    var loopMax   = Math.max(0, parseInt(video.getAttribute('data-loop-max') || '0', 10));
    var loopCount = 0;

    var player = videojs(video.id, {
      html5: { vhs: { overrideNative: true } },
      autoplay: video.hasAttribute('autoplay'),
      muted: video.hasAttribute('muted'),
      controls: video.hasAttribute('controls'),
      inactivityTimeout: 0
    });

    player.ima({ id: video.id, adTagUrl: adTagUrl, locale: locale });

    player.ready(function(){
      moveIMAInto(player);
      setSlotVisible(wrapper, false);
      if (player.autoplay()) {
        player.play().catch(function(){
          player.muted(true);
          player.play().catch(function(){ /* interação necessária */ });
        });
      }
    });

    // Exibe player quando o ad inicia
    player.on('adstart', function(){
      var adCont = player.el().querySelector('.ima-ad-container, .vjs-ima-ad-container, div[id^="ima-ad-container"]');
      if (adCont) adCont.classList.add('ima-visible');
      setSlotVisible(wrapper, true);
    });

    function scheduleNextCycle(){
      if (!loopAds) return;
      if (loopMax > 0 && loopCount >= loopMax) return;
      loopCount++;
      setTimeout(function(){
        try { player.pause(); } catch(e){}
        try { player.currentTime(0); } catch(e){}
        adTagUrl = freshTag(adTagUrlBase);
        try { player.ima && player.ima.changeAdTag && player.ima.changeAdTag(adTagUrl); } catch(e){}
        try { player.ima && player.ima.requestAds && player.ima.requestAds(); } catch(e){}
        setSlotVisible(wrapper, false);
        try { player.play(); } catch(e){}
      }, loopDelay);
    }

    player.on('adend', function(){
      setSlotVisible(wrapper, false);
      scheduleNextCycle();
    });

    player.on('adserror', function(){
      if (fallback === 'content') {
        setSlotVisible(wrapper, true);
        try { player.play(); } catch(e){}
      } else {
        setSlotVisible(wrapper, false);
      }
      scheduleNextCycle();
    });

    player.on('ended', function(){
      if (!loopAds) setSlotVisible(wrapper, false);
    });

    return player;
  }

  document.addEventListener('DOMContentLoaded', function(){
    document.querySelectorAll('.vjs-slot > video.video-js[data-vast]').forEach(initPlayer);
  });
})();