document.querySelectorAll('.search-bar input[type="date"]').forEach(input => {
  const label = input.closest('label');
  const field = document.createElement('div');
  field.className = 'date-picker';
  label.after(field);
  field.append(label);
  input.hidden = true;
  const trigger = document.createElement('button');
  trigger.type = 'button';
  trigger.className = 'date-picker-trigger';
  trigger.setAttribute('aria-label', 'Chọn ngày ghé Mơ');
  trigger.setAttribute('aria-expanded', 'false');
  label.append(trigger);
  const panel = document.createElement('div');
  panel.className = 'date-picker-panel';
  panel.hidden = true;
  panel.setAttribute('role', 'dialog');
  panel.setAttribute('aria-label', 'Chọn ngày ghé Mơ');
  field.append(panel);
  const parse = value => new Date(value + 'T12:00:00');
  const iso = date => `${date.getFullYear()}-${String(date.getMonth()+1).padStart(2,'0')}-${String(date.getDate()).padStart(2,'0')}`;
  const minimum = input.min;
  let month = parse(input.value || minimum);
  month.setDate(1);
  const updateTrigger = () => {
    trigger.textContent = input.value ? new Intl.DateTimeFormat('vi-VN').format(parse(input.value)) : 'Chọn ngày';
    trigger.insertAdjacentHTML('beforeend', '<svg aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6"><rect x="4" y="5" width="16" height="16" rx="2"/><path d="M8 3v4m8-4v4M4 10h16"/></svg>');
  };
  const close = focus => { panel.hidden = true; trigger.setAttribute('aria-expanded','false'); if(focus) trigger.focus(); };
  const select = value => { input.value = value; updateTrigger(); input.dispatchEvent(new Event('change',{bubbles:true})); close(true); };
  const render = () => {
    panel.replaceChildren();
    const header = document.createElement('div');
    header.className = 'date-picker-heading';
    const title = document.createElement('strong');
    title.textContent = new Intl.DateTimeFormat('vi-VN',{month:'long',year:'numeric'}).format(month);
    for(const [direction,text] of [[-1,'‹'],[1,'›']]) {
      const button = document.createElement('button'); button.type='button'; button.textContent=text;
      button.setAttribute('aria-label',direction<0?'Tháng trước':'Tháng sau');
      button.disabled = direction<0 && iso(month).slice(0,7)<=minimum.slice(0,7);
      button.onclick=()=>{month.setMonth(month.getMonth()+direction);render();panel.querySelector(`button[aria-label="${direction<0?'Tháng trước':'Tháng sau'}"]`).focus();};
      if(direction<0) header.append(button,title); else header.append(button);
    }
    panel.append(header);
    const grid=document.createElement('div');grid.className='date-picker-grid';
    for(const day of ['T2','T3','T4','T5','T6','T7','CN']){const heading=document.createElement('span');heading.textContent=day;grid.append(heading);}
    const start=new Date(month);start.setDate(1-((month.getDay()+6)%7));
    for(let i=0;i<42;i++){
      const date=new Date(start);date.setDate(start.getDate()+i);const value=iso(date);
      const button=document.createElement('button');button.type='button';button.textContent=date.getDate();
      button.disabled=value<minimum;button.dataset.otherMonth=String(date.getMonth()!==month.getMonth());
      button.setAttribute('aria-label',new Intl.DateTimeFormat('vi-VN',{dateStyle:'full'}).format(date));
      button.setAttribute('aria-pressed',String(value===input.value));
      button.onclick=()=>select(value);grid.append(button);
    }
    panel.append(grid);
    const today=document.createElement('button');today.type='button';today.className='date-picker-today';today.textContent='Hôm nay';
    const todayValue=iso(new Date());today.disabled=todayValue<minimum;today.onclick=()=>select(todayValue);panel.append(today);
  };
  trigger.onclick=()=>{
    if(!panel.hidden){close(false);return;}
    month=parse(input.value||minimum);month.setDate(1);render();panel.hidden=false;trigger.setAttribute('aria-expanded','true');
    panel.querySelector('button[aria-pressed="true"]:not(:disabled)')?.focus();
  };
  document.addEventListener('click',event=>{if(!field.contains(event.target))close(false);});
  field.addEventListener('keydown',event=>{if(event.key==='Escape'){event.preventDefault();close(true);}});
  field.addEventListener('focusout',()=>setTimeout(()=>{if(!field.contains(document.activeElement))close(false);},0));
  updateTrigger();
});
