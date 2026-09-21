'use client'
import { useState,FormEvent } from 'react'
import Image from 'next/image'
import { ArrowRight,ShieldCheck } from 'lucide-react'
import { rooms,packages,StayPackage,money,todayVN } from '@/lib/rooms'
import { stayWindow,overlaps } from '@/lib/booking'
import { useAvailability } from './useAvailability'
import Lookup from './Lookup'
export default function BookingForm({initial}:{initial:Record<string,string|undefined>}) {
 const [roomId,setRoomId]=useState(rooms.some(r=>r.id===initial.room)?initial.room!:'white')
 const [stay,setStay]=useState<StayPackage>(['3h','6h','overnight'].includes(initial.package||'')?initial.package as StayPackage:'overnight')
 const [date,setDate]=useState(initial.date&&/^\d{4}-\d{2}-\d{2}$/.test(initial.date)?initial.date:todayVN())
 const [time,setTime]=useState(initial.time&&/^\d{2}:\d{2}$/.test(initial.time)?initial.time:'14:00')
 const [submitting,setSubmitting]=useState(false)
 const [error,setError]=useState('')
 const {data,loading}=useAvailability(date)
 const room=rooms.find(r=>r.id===roomId)!
 let window:{start:Date;end:Date}|null=null
 try{window=stayWindow(date,time,stay,data?.overnight)}catch{}
 const info=data?.rooms?.find(r=>r.id===roomId)
 const busy=Boolean(window&&info?.intervals.some(i=>overlaps(window!.start,window!.end,new Date(i.start),new Date(i.end))))
 const canBook=Boolean(data?.onlineBooking&&data.connected&&info?.active&&window&&window.start>new Date()&&!busy)
 async function submit(event:FormEvent<HTMLFormElement>){
 event.preventDefault();if(!canBook||submitting)return;setSubmitting(true);setError('')
 const form=new FormData(event.currentTarget)
 try{
 const r=await fetch('/api/bookings',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({roomId,stayPackage:stay,date,time,numberOfGuests:Number(form.get('numberOfGuests')),guestName:form.get('guestName'),guestPhone:form.get('guestPhone'),guestEmail:form.get('guestEmail'),guestNote:form.get('guestNote'),acceptTerms:form.get('acceptTerms')==='on'})})
 const result=await r.json();if(!r.ok)throw new Error(result.error+(result.bookingCode?' Mã: '+result.bookingCode:''))
 location.assign('/payment?code='+encodeURIComponent(result.bookingCode)+'#'+result.token)
 }catch(e){setError(e instanceof Error?e.message:'Không thể tạo đơn. Vui lòng thử lại.');setSubmitting(false)}
 }
 const dateTime=(d:Date)=>d.toLocaleString('vi-VN',{timeZone:'Asia/Ho_Chi_Minh',day:'2-digit',month:'2-digit',hour:'2-digit',minute:'2-digit'})
 return <><div className="steps"><span className="current">01 — Chọn phòng & thông tin</span><span>02 — Thanh toán QR</span><span>03 — Hẹn gặp tại Lặng</span></div><div className="booking-grid"><form className="panel" onSubmit={submit}><h2>Cuộc hẹn của bạn</h2><div className="form-grid"><label className="field">Không gian<select value={roomId} onChange={e=>setRoomId(e.target.value)}>{rooms.map(r=><option key={r.id} value={r.id}>{r.name} Room</option>)}</select></label><label className="field">Gói lưu trú<select value={stay} onChange={e=>setStay(e.target.value as StayPackage)}>{Object.entries(packages).map(([id,label])=><option key={id} value={id}>{label}</option>)}</select></label><label className="field">Ngày ghé Lặng<input type="date" value={date} min={todayVN()} required onChange={e=>setDate(e.target.value)}/></label><label className="field">Giờ nhận phòng<input type="time" value={stay==='overnight'?data?.overnight?.checkIn||'':time} disabled={stay==='overnight'} required={stay!=='overnight'} onChange={e=>setTime(e.target.value)}/></label></div><div className="form-section"><h2>Mình làm quen nhé</h2><div className="form-grid"><label className="field">Họ và tên *<input name="guestName" autoComplete="name" placeholder="Tên của bạn" minLength={2} maxLength={100} required/></label><label className="field">Số điện thoại *<input name="guestPhone" autoComplete="tel" type="tel" placeholder="Để Lặng liên hệ với bạn" maxLength={20} required/></label><label className="field">Email (không bắt buộc)<input name="guestEmail" autoComplete="email" type="email" placeholder="ban@email.com" maxLength={254}/></label><label className="field">Số khách<select name="numberOfGuests" defaultValue={initial.guests==='1'?'1':'2'}><option value="1">1 người lớn</option><option value="2">2 người lớn</option></select></label><label className="field full">Một lời nhắn cho Lặng<textarea name="guestNote" rows={3} maxLength={1000} placeholder="Bạn có mong muốn gì cho lần ghé này không?"/></label></div></div>{data?.terms&&<div className="notice"><strong>Điều kiện đặt phòng</strong><p style={{whiteSpace:'pre-line'}}>{data.terms}</p></div>}<label className="flex items-start gap-3 text-xs leading-6"><input type="checkbox" name="acceptTerms" required className="mt-1"/>Tôi đã đọc điều kiện đặt phòng và đồng ý để Lặng sử dụng thông tin liên hệ để xử lý đơn này.</label>{error&&<p className="notice error" role="alert">{error}</p>}{!loading&&!canBook&&<p className="notice">{busy?'Khung giờ này đã có lịch đặt. Bạn chọn giờ hoặc phòng khác nhé.':!window&&stay==='overnight'?'Bạn liên hệ Lặng để xác nhận giờ qua đêm nhé.':window&&window.start<=new Date()?'Vui lòng chọn giờ nhận phòng trong tương lai.':'Đặt phòng trực tuyến chưa mở. Gọi 0357 907 153 hoặc nhắn Zalo để được giữ phòng.'}</p>}<button className="button w-full mt-6" type="submit" disabled={!canBook||loading||submitting}>{submitting?'Đang giữ phòng…':loading?'Đang kiểm tra lịch…':'Tiếp tục thanh toán'}<ArrowRight size={16}/></button><a className="text-link mt-5" href="https://zalo.me/0357907153" target="_blank" rel="noreferrer">Đặt phòng qua Zalo ↗</a></form><aside className="panel"><div className="summary-image"><Image src={room.image} fill sizes="400px" alt={'Ảnh minh họa phòng '+room.name}/><span className="image-caption">Ảnh minh họa</span></div><h2>{room.name} Room</h2><p className="muted">{room.subtitle}</p><div className="summary-row"><span>Gói lưu trú</span><strong>{packages[stay]}</strong></div><div className="summary-row"><span>Nhận phòng</span><span>{window?dateTime(window.start):'Chờ xác nhận giờ'}</span></div><div className="summary-row"><span>Trả phòng</span><span>{window?dateTime(window.end):'Chờ xác nhận giờ'}</span></div><div className="summary-row total"><span>Tổng tiền</span><strong>{money(room.prices[stay])}</strong></div><p className="muted">Thanh toán toàn bộ giá gói qua QR. Đơn chỉ được xác nhận sau khi hệ thống nhận thanh toán.</p><p className="flex gap-2 items-center muted"><ShieldCheck size={16}/>Giữ phòng 15 phút khi tạo đơn.</p><p className="muted">82 đường B18, KDC 91B<br/>Ninh Kiều, Cần Thơ</p></aside></div><Lookup/></>
}

