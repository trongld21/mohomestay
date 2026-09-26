(()=>{
  const rooms=Array.isArray(window.LANG_HOME?.rooms)?window.LANG_HOME.rooms:[];
  const activeRooms=rooms.filter(room=>room.status==='ACTIVE');
  const one=(selector,root=document)=>root.querySelector(selector),all=(selector,root=document)=>[...root.querySelectorAll(selector)];
  const esc=value=>String(value??'').replace(/[&<>"']/g,char=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[char]));
  const cash=value=>new Intl.NumberFormat('vi-VN').format(Number(value||0))+'đ';
  const params=()=>Object.fromEntries(new URLSearchParams(location.search));
  const bookableSlots=room=>window.MoBookingSlots?.configuredSlots(room)||[];
  async function request(url,options={}){const response=await fetch(url,{...options,headers:{'Content-Type':'application/json',...(options.headers||{})}});let data={};try{data=await response.json()}catch{}if(!response.ok)throw new Error(data.error||'Không thể kết nối máy chủ.');return data}
  function packageLabel(pkg){if(pkg.mode==='FIXED_TIME')return `${pkg.checkInTime} – ${pkg.checkOutTime}`;return pkg.durationMinutes%60===0?`${pkg.durationMinutes/60} giờ`:`${pkg.durationMinutes} phút`}
  function packageWindow(pkg,date,time){if(!pkg||!date)return null;let start,end;if(pkg.mode==='FIXED_TIME'){if(!pkg.checkInTime||!pkg.checkOutTime)return null;start=new Date(`${date}T${pkg.checkInTime}:00+07:00`);end=new Date(`${date}T${pkg.checkOutTime}:00+07:00`);if(end<=start)end=new Date(end.getTime()+86400000)}else{if(!time)return null;start=new Date(`${date}T${time}:00+07:00`);end=new Date(start.getTime()+Number(pkg.durationMinutes)*60000)}return {start,end}}
  const overlaps=(window,interval)=>window.start<new Date(interval.end)&&window.end>new Date(interval.start);

  all('[data-room-gallery]').forEach(gallery=>{const main=one('[data-gallery-main]',gallery);gallery.onclick=event=>{const button=event.target.closest('[data-gallery-image]');if(!button)return;all('[data-gallery-image]',gallery).forEach(item=>item.classList.toggle('selected',item===button));main.classList.add('is-changing');setTimeout(()=>{main.src=button.dataset.galleryImage;main.classList.remove('is-changing')},130)}});

  function initHomeSearch(){const form=one('.search-bar');if(!form)return;const roomSelect=form.elements.room,packageSelect=form.elements.package,submit=one('[type="submit"]',form);const refresh=()=>{const room=activeRooms.find(item=>item.id===roomSelect.value)||activeRooms[0],slots=bookableSlots(room);packageSelect.innerHTML=slots.length?slots.map(pkg=>`<option value="${esc(pkg.id)}">${esc(pkg.name)} · ${pkg.checkInTime}–${pkg.checkOutTime} · ${cash(pkg.price)}</option>`).join(''):'<option value="">Chưa có khung giờ online</option>';submit.disabled=!slots.length};roomSelect.innerHTML='<option value="">Chọn phòng</option>'+activeRooms.map(room=>`<option value="${esc(room.id)}">${esc(room.name)}</option>`).join('');roomSelect.onchange=refresh;refresh();form.onsubmit=event=>{event.preventDefault();if(submit.disabled)return;location.href=`/calendar?room=${encodeURIComponent(roomSelect.value)}&package=${encodeURIComponent(packageSelect.value)}&date=${encodeURIComponent(form.elements.date.value)}`}}

  function initCalendar(){const root=one('#calendar-app');if(!root)return;const query=params(),roomSelect=one('[data-cal-room]',root),packageSelect=one('[data-cal-package]',root),time=one('[data-cal-time]',root),grid=one('[data-calendar-grid]',root),output=one('[data-availability]',root);let date=/^\d{4}-\d{2}-\d{2}$/.test(query.date||'')?query.date:LANG_HOME.today,month=date.slice(0,7),availability=null;if(activeRooms.some(room=>room.id===query.room))roomSelect.value=query.room;
    const selectedRoom=()=>activeRooms.find(room=>room.id===roomSelect.value)||activeRooms[0];const selectedPackage=()=>selectedRoom()?.packages.find(pkg=>pkg.id===packageSelect.value);
    function packages(){const list=bookableSlots(selectedRoom());packageSelect.innerHTML=list.length?list.map(pkg=>`<option value="${esc(pkg.id)}">${esc(pkg.name)} · ${pkg.checkInTime}–${pkg.checkOutTime} · ${cash(pkg.price)}</option>`).join(''):'<option value="">Chưa có khung giờ online</option>';if(list.some(pkg=>pkg.id===query.package))packageSelect.value=query.package;updateTime()}
    function updateTime(){const fixed=selectedPackage()?.mode==='FIXED_TIME';time.disabled=fixed;if(fixed)time.value=selectedPackage().checkInTime||''}
    function draw(){const [year,number]=month.split('-').map(Number),offset=(new Date(year,number-1,1).getDay()+6)%7,days=new Date(year,number,0).getDate();one('[data-month-title]',root).textContent=`Tháng ${number}, ${year}`;one('[data-selected-title]',root).textContent='Ngày '+date.split('-').reverse().join('/');grid.innerHTML=['T2','T3','T4','T5','T6','T7','CN'].map(day=>`<span class="weekday">${day}</span>`).join('')+'<span></span>'.repeat(offset)+Array.from({length:days},(_,index)=>{const value=`${month}-${String(index+1).padStart(2,'0')}`;return `<button class="day${value===date?' selected':''}" data-date="${value}" ${value<LANG_HOME.today?'disabled':''}>${index+1}</button>`}).join('')}
    async function load(){output.innerHTML='<div class="skeleton" style="height:170px"></div>';try{availability=await request('/api/availability?date='+date);const room=selectedRoom(),pkg=selectedPackage(),window=packageWindow(pkg,date,time.value),info=availability.rooms.find(item=>item.id===room?.id),busy=!!(window&&info?.intervals.some(interval=>overlaps(window,interval))),past=!!(window&&window.start<=new Date()),open=availability.onlineBooking&&info?.active&&window&&!busy&&!past;output.innerHTML=room?`<article class="availability-focus"><img src="${esc(room.image)}" alt=""><div><span class="status-chip ${open?'free':busy?'busy':''}">${past?'Đã qua giờ':busy?'Đã có lịch':open?'Còn trống':'Liên hệ Mơ'}</span><h3>${esc(room.name)}</h3><p>${esc(pkg?.name||'')} · ${cash(pkg?.price)}</p>${window?`<p class="muted">${window.start.toLocaleString('vi-VN')} → ${window.end.toLocaleString('vi-VN')}</p>`:''}${open?`<a class="button" href="/bookings?room=${encodeURIComponent(room.id)}&package=${encodeURIComponent(pkg.id)}&date=${date}&time=${time.value}">Đặt khung giờ này</a>`:'<a class="text-link" href="https://zalo.me/0357907153">Nhắn Mơ tư vấn ↗</a>'}</div></article>`:'<div class="empty-state">Chưa có phòng đang mở.</div>'}catch(error){output.innerHTML=`<p class="notice error">${esc(error.message)}</p>`;LangUI.toast({type:'error',title:'Chưa tải được lịch',message:error.message})}}
    grid.onclick=event=>{const button=event.target.closest('[data-date]');if(button){date=button.dataset.date;draw();load()}};one('[data-month-prev]',root).onclick=()=>{const value=new Date(month+'-01T00:00:00');value.setMonth(value.getMonth()-1);month=value.toISOString().slice(0,7);draw()};one('[data-month-next]',root).onclick=()=>{const value=new Date(month+'-01T00:00:00');value.setMonth(value.getMonth()+1);month=value.toISOString().slice(0,7);draw()};one('[data-refresh]',root).onclick=load;roomSelect.onchange=()=>{packages();load()};packageSelect.onchange=()=>{updateTime();load()};time.onchange=load;packages();draw();load()}

  function initBooking(){
    const root=one('#booking-app');if(!root||!window.MoBookingSlots)return;
    const form=one('[data-booking-form]',root),summary=one('[data-summary]',root),message=one('[data-booking-message]',root),terms=one('[data-terms]',root),slotGrid=one('[data-slot-grid]',root),query=params();
    const roomSelect=form.elements.roomId,submit=one('.booking-submit',form),slotTools=window.MoBookingSlots;
    let availability=null,selectedId='';
    if(activeRooms.some(room=>room.id===query.room))roomSelect.value=query.room;
    if(/^\d{4}-\d{2}-\d{2}$/.test(query.date||''))form.date.value=query.date;
    if(!form.date.value)form.date.value=LANG_HOME.today;

    const room=()=>activeRooms.find(item=>item.id===roomSelect.value)||activeRooms[0];
    const slots=()=>slotTools.configuredSlots(room());
    const selectedSlot=()=>slots().find(slot=>slot.id===selectedId);
    const availabilityForRoom=()=>availability?.rooms?.find(item=>item.id===room()?.id);
    const prettyDate=value=>new Date(`${value}T12:00:00+07:00`).toLocaleDateString('vi-VN',{weekday:'long',day:'2-digit',month:'2-digit',year:'numeric'});

    function syncGuests(){
      const current=room(),previous=Number(form.numberOfGuests.value||2);
      form.numberOfGuests.innerHTML=Array.from({length:current?.maxGuests||1},(_,index)=>`<option value="${index+1}">${index+1} khách</option>`).join('');
      form.numberOfGuests.value=Math.min(previous,current?.maxGuests||1);
    }
    function clearSelection(){selectedId='';form.packageId.value='';form.time.value='';submit.disabled=true;submit.textContent='Chọn một khung giờ để tiếp tục'}
    function renderSummary(){
      const current=room(),slot=selectedSlot(),window=slotTools.slotWindow(slot,form.date.value);
      if(!current){summary.innerHTML='<div class="empty-state">Chưa có phòng đang mở.</div>';return}
      summary.innerHTML=`<div class="summary-image"><img src="${esc(current.image)}" alt="Phòng ${esc(current.name)}"></div><span class="summary-kicker">TÓM TẮT CUỘC HẸN</span><h2>${esc(current.name)}</h2><p class="muted">${esc(current.subtitle)}</p><div class="room-tag-chips">${current.tags.map(tag=>`<span>${esc(tag)}</span>`).join('')}</div><div class="summary-row"><span>Ngày ghé</span><strong>${prettyDate(form.date.value)}</strong></div><div class="summary-row"><span>Khung giờ</span><strong>${window?`${slot.checkInTime} – ${slot.checkOutTime}`:'Chưa chọn'}</strong></div><div class="summary-row"><span>Gói lưu trú</span><strong>${esc(slot?.name||'—')}</strong></div><div class="summary-row total"><span>Tổng tiền</span><strong>${slot?cash(slot.price):'—'}</strong></div>${slot?'<p class="summary-assurance"><span aria-hidden="true">✓</span> Khung giờ được giữ 15 phút khi tạo đơn.</p>':'<p class="summary-prompt">Chọn một khung giờ còn trống để xem đầy đủ chi tiết.</p>'}`;
    }
    function chooseSlot(slot){
      selectedId=slot.id;form.packageId.value=slot.id;form.time.value=slot.checkInTime;submit.disabled=false;submit.textContent='Tiếp tục thanh toán →';
      renderSlots();renderSummary();
    }
    function renderSlots(){
      const list=slots(),info=availabilityForRoom();slotGrid.setAttribute('aria-busy',availability?'false':'true');
      if(!availability){slotGrid.innerHTML='<div class="slot-skeleton"></div><div class="slot-skeleton"></div>';return}
      if(!list.length){slotGrid.innerHTML='<div class="slot-empty"><strong>Phòng chưa có khung giờ đặt online.</strong><span>Nhắn Mơ để được tư vấn lịch phù hợp nhé.</span></div>';return}
      slotGrid.innerHTML=list.map((slot,index)=>{
        const window=slotTools.slotWindow(slot,form.date.value),baseState=slotTools.slotState(window,info?.intervals||[]),state=!availability.onlineBooking||!info?.active?'closed':baseState,selected=state==='available'&&slot.id===selectedId;
        const label=state==='booked'?'Đã được đặt':state==='past'?'Đã qua giờ':state==='closed'?'Tạm ngưng':'Còn trống';
        return `<button class="booking-slot-card is-${state}${selected?' is-selected':''}" type="button" data-slot-id="${esc(slot.id)}" ${state!=='available'?'disabled':''} aria-pressed="${selected}" style="--slot-index:${index}"><span class="slot-card-top"><strong>${esc(slot.name)}</strong><span class="slot-state">${label}</span></span><span class="slot-time"><b>${esc(slot.checkInTime)}</b><i aria-hidden="true"></i><b>${esc(slot.checkOutTime)}</b></span><span class="slot-price"><small>Trọn gói</small><strong>${cash(slot.price)}</strong></span></button>`;
      }).join('');
      if(selectedId&&!one(`[data-slot-id="${CSS.escape(selectedId)}"]:not(:disabled)`,slotGrid))clearSelection();
    }
    async function loadAvailability(){
      availability=null;message.innerHTML='';clearSelection();renderSlots();renderSummary();
      try{
        availability=await request('/api/availability?date='+encodeURIComponent(form.date.value));
        terms.hidden=!availability.terms;terms.textContent=availability.terms?'Điều kiện đặt phòng: '+availability.terms:'';
        renderSlots();
        const preferred=slots().find(slot=>slot.id===query.package),button=preferred&&one(`[data-slot-id="${CSS.escape(preferred.id)}"]:not(:disabled)`,slotGrid);
        if(button)chooseSlot(preferred);else renderSummary();
      }catch(error){slotGrid.innerHTML=`<div class="slot-empty is-error"><strong>Chưa tải được lịch phòng.</strong><span>${esc(error.message)}</span></div>`;message.innerHTML=`<p class="notice error">${esc(error.message)}</p>`}
    }
    slotGrid.onclick=event=>{const button=event.target.closest('[data-slot-id]:not(:disabled)');if(!button)return;const slot=slots().find(item=>item.id===button.dataset.slotId);if(slot)chooseSlot(slot)};
    roomSelect.onchange=()=>{syncGuests();loadAvailability()};form.date.onchange=loadAvailability;
    form.onsubmit=async event=>{
      event.preventDefault();message.innerHTML='';
      if(!selectedSlot()){message.innerHTML='<p class="notice error">Vui lòng chọn một khung giờ còn trống.</p>';one('[data-slot-section]',form)?.focus();return}
      const data=Object.fromEntries(new FormData(form));data.numberOfGuests=Number(data.numberOfGuests);data.acceptTerms=!!data.acceptTerms;submit.disabled=true;submit.textContent='Đang giữ khung giờ…';
      try{const result=await request('/api/bookings',{method:'POST',body:JSON.stringify(data)});LangUI.toast({type:'success',title:'Đã giữ khung giờ',message:'Đang chuyển đến trang thanh toán.'});location.href='/payment?code='+encodeURIComponent(result.bookingCode)+'#'+result.token}
      catch(error){message.innerHTML=`<p class="notice error">${esc(error.message)}</p>`;LangUI.toast({type:'error',title:'Chưa thể đặt phòng',message:error.message});await loadAvailability()}
      finally{if(selectedSlot()){submit.disabled=false;submit.textContent='Tiếp tục thanh toán →'}}
    };
    syncGuests();loadAvailability();
  }
  initHomeSearch();initCalendar();initBooking();
})();
