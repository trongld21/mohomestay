// Reusable select enhancement. The original select owns form values and validation.
(() => {
  let nextId = 0;
  const enhance = select => {
    if (select.dataset.selectEnhanced || select.multiple || select.size > 1) return;
    select.dataset.selectEnhanced = 'true';
    const root = document.createElement('div');
    root.className = 'mo-select';
    select.before(root);
    root.append(select);
    select.classList.add('mo-select-native');
    select.tabIndex = -1;
    select.setAttribute('aria-hidden', 'true');
    const trigger = document.createElement('button');
    trigger.type = 'button'; trigger.className = 'mo-select-trigger';
    trigger.setAttribute('role', 'combobox');
    trigger.setAttribute('aria-haspopup', 'listbox');
    trigger.setAttribute('aria-expanded', 'false');
    const label = select.labels?.[0];
    if (label) {
      const text = label.querySelector('span')?.textContent || label.firstChild?.textContent;
      if (text?.trim()) trigger.setAttribute('aria-label', text.trim());
    }
    if (!trigger.hasAttribute('aria-label')) trigger.setAttribute('aria-label', select.getAttribute('aria-label') || select.name || 'Chọn mục');
    const list = document.createElement('div');
    list.className = 'mo-select-list'; list.id = `mo-select-${++nextId}`;
    list.setAttribute('role', 'listbox'); list.hidden = true;
    list.setAttribute('aria-label', trigger.getAttribute('aria-label'));
    trigger.setAttribute('aria-controls', list.id);
    root.append(trigger, list);
    let active = 0;
    const options = () => Array.from(select.options);
    const unavailable = option => option.disabled || option.parentElement?.disabled;
    const close = () => { list.hidden = true; trigger.setAttribute('aria-expanded', 'false'); trigger.removeAttribute('aria-activedescendant'); };
    const mark = () => {
      [...list.children].forEach((item,index) => item.dataset.active = String(index === active));
      const item = list.children[active];
      if (item) { trigger.setAttribute('aria-activedescendant', item.id); item.scrollIntoView({block:'nearest'}); }
    };
    const sync = () => {
      trigger.disabled = select.disabled;
      trigger.replaceChildren();
      const text = document.createElement('span'); text.textContent = select.selectedOptions[0]?.textContent || 'Chọn mục';
      trigger.append(text);
      trigger.insertAdjacentHTML('beforeend','<svg aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"><path d="m6 9 6 6 6-6"/></svg>');
      list.replaceChildren();
      options().forEach((option,index) => {
        const item = document.createElement('div'); item.className = 'mo-select-option';
        item.id = `${list.id}-${index}`; item.textContent = option.textContent;
        item.setAttribute('role','option'); item.setAttribute('aria-selected',String(option.selected));
        item.setAttribute('aria-disabled',String(!!unavailable(option))); item.hidden = option.hidden;
        item.addEventListener('click', event => { event.preventDefault(); choose(index); }); list.append(item);
      });
    };
    const choose = index => {
      const option = options()[index]; if (!option || unavailable(option) || option.hidden) return;
      select.selectedIndex = index;
      select.dispatchEvent(new Event('input',{bubbles:true}));
      select.dispatchEvent(new Event('change',{bubbles:true}));
      sync(); close(); trigger.focus();
    };
    const open = () => { sync(); active = Math.max(0,select.selectedIndex); list.hidden = false; trigger.setAttribute('aria-expanded','true'); mark(); };
    trigger.addEventListener('click', event => { event.preventDefault(); list.hidden ? open() : close(); });
    trigger.addEventListener('keydown', event => {
      if (event.key === 'Escape' || event.key === 'Tab') { close(); return; }
      if (['ArrowDown','ArrowUp','Home','End'].includes(event.key)) {
        event.preventDefault(); if (list.hidden) open();
        const enabled = options().map((o,i)=>!unavailable(o)&&!o.hidden?i:-1).filter(i=>i>=0);
        if (!enabled.length) return;
        const position = enabled.indexOf(active);
        active = event.key==='Home'?enabled[0]:event.key==='End'?enabled.at(-1):enabled[Math.max(0,Math.min(enabled.length-1,position+(event.key==='ArrowDown'?1:-1)))];
        mark();
      } else if (event.key==='Enter'||event.key===' ') {
        event.preventDefault(); list.hidden ? open() : choose(active);
      } else if (event.key.length===1 && !event.ctrlKey && !event.metaKey) {
        const index = options().findIndex(o=>!unavailable(o)&&!o.hidden&&o.textContent.trim().toLocaleLowerCase().startsWith(event.key.toLocaleLowerCase()));
        if(index>=0){event.preventDefault();if(list.hidden)open();active=index;mark();}
      }
    });
    document.addEventListener('click',event=>{if(!event.composedPath().includes(root))close();});
    document.addEventListener('focusin',event=>{if(!root.contains(event.target))close();});
    select.addEventListener('change',sync);
    select.addEventListener('invalid',()=>trigger.focus());
    select.form?.addEventListener('reset',()=>setTimeout(()=>{sync();close();},0));
    new MutationObserver(sync).observe(select,{childList:true,subtree:true,attributes:true,characterData:true});
    sync();
  };
  const init = root => {
    if(root.matches?.('select'))enhance(root);
    root.querySelectorAll?.('select').forEach(enhance);
  };
  init(document);
  new MutationObserver(records => records.forEach(record => record.addedNodes.forEach(node=>{if(node.nodeType===1)init(node);}))).observe(document.body,{childList:true,subtree:true});
  window.MoSelect = {init};
})();
