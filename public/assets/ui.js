(()=>{
  const region=document.querySelector('[data-toast-region]');
  const dialogRoot=document.querySelector('[data-dialog-root]');
  const escape=value=>String(value??'').replace(/[&<>"']/g,char=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[char]));

  function toast({type='info',title='',message='',persistent=false}={}){
    if(!region)return null;
    const item=document.createElement('div');item.className=`ui-toast ui-toast--${type}`;item.setAttribute('role',type==='error'?'alert':'status');
    item.innerHTML=`<span class="ui-toast__icon" aria-hidden="true"></span><div><strong>${escape(title)}</strong><p>${escape(message)}</p></div><button type="button" aria-label="Đóng thông báo">×</button>`;
    const close=()=>{item.classList.add('is-leaving');setTimeout(()=>item.remove(),220)};item.querySelector('button').onclick=close;region.append(item);
    if(!persistent){let timer=setTimeout(close,4500);item.onmouseenter=()=>clearTimeout(timer);item.onmouseleave=()=>timer=setTimeout(close,2200)}
    return {close,element:item};
  }

  function confirm({title='Xác nhận thao tác',message='',confirmLabel='Xác nhận',cancelLabel='Quay lại',tone='danger'}={}){
    return new Promise(resolve=>{
      if(!dialogRoot){resolve(false);return}
      const previous=document.activeElement;let settled=false;
      dialogRoot.innerHTML=`<div class="ui-dialog-backdrop" data-dialog-cancel></div><section class="ui-dialog" role="dialog" aria-modal="true" aria-labelledby="ui-dialog-title"><button class="ui-dialog__close" type="button" data-dialog-cancel aria-label="Đóng">×</button><span class="ui-dialog__mark ui-dialog__mark--${escape(tone)}" aria-hidden="true">!</span><h2 id="ui-dialog-title">${escape(title)}</h2><p>${escape(message)}</p><div class="ui-dialog__actions"><button class="button outline" type="button" data-dialog-cancel>${escape(cancelLabel)}</button><button class="button ${tone==='danger'?'danger':''}" type="button" data-dialog-confirm>${escape(confirmLabel)}</button></div></section>`;
      dialogRoot.classList.add('is-open');document.body.classList.add('dialog-open');
      const panel=dialogRoot.querySelector('.ui-dialog'),confirmButton=dialogRoot.querySelector('[data-dialog-confirm]');
      const finish=value=>{if(settled)return;settled=true;dialogRoot.classList.remove('is-open');document.body.classList.remove('dialog-open');dialogRoot.innerHTML='';previous?.focus?.();resolve(value)};
      dialogRoot.querySelectorAll('[data-dialog-cancel]').forEach(button=>button.onclick=()=>finish(false));confirmButton.onclick=()=>finish(true);
      dialogRoot.onkeydown=event=>{if(event.key==='Escape'){event.preventDefault();finish(false)}if(event.key==='Tab'){const focusable=[...panel.querySelectorAll('button:not([disabled])')];const first=focusable[0],last=focusable.at(-1);if(event.shiftKey&&document.activeElement===first){event.preventDefault();last.focus()}else if(!event.shiftKey&&document.activeElement===last){event.preventDefault();first.focus()}}};
      requestAnimationFrame(()=>confirmButton.focus());
    });
  }
  window.LangUI={toast,confirm};
})();
