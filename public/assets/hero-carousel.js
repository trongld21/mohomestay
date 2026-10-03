document.querySelectorAll('[data-hero-carousel]').forEach(root => {
  const slides = [...root.querySelectorAll('[data-hero-slide]')];
  const dots = [...root.querySelectorAll('[data-hero-dot]')];
  const pause = root.querySelector('[data-hero-pause]');
  const reduced = matchMedia('(prefers-reduced-motion: reduce)');
  let index = 0, timer, paused = reduced.matches, hovered = false, focused = false;
  const show = (next, manual = false) => {
    index = (next + slides.length) % slides.length;
    slides.forEach((slide,i) => {slide.classList.toggle('is-active',i===index);slide.setAttribute('aria-hidden',String(i!==index));});
    dots.forEach((dot,i) => dot.setAttribute('aria-pressed',String(i===index)));
    if(manual) root.querySelector('[data-hero-status]').textContent = `Ảnh ${index+1} / ${slides.length}`;
  };
  const schedule = () => {
    clearInterval(timer);
    pause.textContent = paused ? '▷' : 'Ⅱ';
    pause.setAttribute('aria-label',paused?'Tiếp tục chuyển ảnh':'Tạm dừng chuyển ảnh');
    if(!paused&&!hovered&&!focused&&!document.hidden)timer=setInterval(()=>show(index+1),4000);
  };
  const manual = next => {show(next,true);schedule();};
  root.querySelector('[data-hero-prev]').onclick=()=>manual(index-1);
  root.querySelector('[data-hero-next]').onclick=()=>manual(index+1);
  dots.forEach((dot,i)=>dot.onclick=()=>manual(i));
  pause.onclick=()=>{paused=!paused;schedule();};
  root.addEventListener('mouseenter',()=>{hovered=true;schedule();});
  root.addEventListener('mouseleave',()=>{hovered=false;schedule();});
  root.addEventListener('focusin',()=>{focused=true;schedule();});
  root.addEventListener('focusout',()=>setTimeout(()=>{focused=root.contains(document.activeElement);schedule();},0));
  root.addEventListener('keydown',event=>{
    if(!event.target.closest('[data-hero-controls]'))return;
    if(event.key==='ArrowLeft'||event.key==='ArrowRight'){event.preventDefault();manual(index+(event.key==='ArrowRight'?1:-1));}
  });
  let start;
  root.addEventListener('touchstart',event=>{if(!event.target.closest('a,button'))start=event.touches[0].clientX;},{passive:true});
  root.addEventListener('touchend',event=>{if(start!==undefined){const distance=event.changedTouches[0].clientX-start;if(Math.abs(distance)>50)manual(index+(distance<0?1:-1));start=undefined;}},{passive:true});
  document.addEventListener('visibilitychange',schedule);
  reduced.addEventListener('change',()=>{paused=reduced.matches;schedule();});
  root.querySelector('[data-hero-controls]').hidden=false;
  schedule();
});
