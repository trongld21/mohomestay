document.querySelectorAll('.nav-phone').forEach(phone => {
  const handset = phone.querySelector('.phone-handset');
  if (!handset) return;
  let settling;
  phone.addEventListener('mouseenter', () => {
    settling?.cancel();
    handset.style.animation = '';
  });
  phone.addEventListener('mouseleave', () => {
    const transform = getComputedStyle(handset).transform;
    handset.style.animation = 'none';
    if (matchMedia('(prefers-reduced-motion: reduce)').matches) return;
    settling = handset.animate([{transform}, {transform:'rotate(0deg)'}], {
      duration:150, easing:'ease-out'
    });
  });
});
