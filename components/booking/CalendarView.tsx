'use client'
import { useState } from 'react'
import Link from 'next/link'
import { ChevronLeft,ChevronRight,ArrowUpRight,RefreshCw } from 'lucide-react'
import { rooms,packages,money,todayVN,StayPackage } from '@/lib/rooms'
import { stayWindow,overlaps } from '@/lib/booking'
import { useAvailability } from './useAvailability'
export default function CalendarView({initialDate,initialRoom,initialPackage,guests}:{initialDate?:string;initialRoom?:string;initialPackage?:string;guests?:string}) {
 const initial=initialDate && /^\d{4}-\d{2}-\d{2}$/.test(initialDate) && Number.isFinite(new Date(initialDate).getTime()) ? initialDate : todayVN()
 const [date,setDate]=useState(initial)
 const [month,setMonth]=useState(initial.slice(0,7))
 const [filter,setFilter]=useState(rooms.some(r=>r.id===initialRoom)?initialRoom!:'all')
 const [stay,setStay]=useState<StayPackage>(['3h','6h','overnight'].includes(initialPackage || '')?initialPackage as StayPackage:'overnight')
 const [time,setTime]=useState('14:00')
 const {data,loading,refresh}=useAvailability(date)
 const [year,m]=month.split('-').map(Number)
 const first=(new Date(year,m-1,1).getDay()+6)%7
 const days=new Date(year,m,0).getDate()
 const shift=(n:number)=>{const d=new Date(year,m-1+n,1);setMonth(d.getFullYear()+'-'+String(d.getMonth()+1).padStart(2,'0'))}
 let window: {start:Date;end:Date}|null=null
 try {window=stayWindow(date,time,stay,data?.overnight)}catch{}
 const displayTime=(value:string)=>new Date(value).toLocaleString('vi-VN',{timeZone:'Asia/Ho_Chi_Minh',day:'2-digit',month:'2-digit',hour:'2-digit',minute:'2-digit'})
 return <><div className="calendar-layout"><section className="panel"><div className="calendar-nav"><button className="icon-button" onClick={()=>shift(-1)} aria-label="Tháng trước"><ChevronLeft size={17}/></button><h2>Tháng {m}, {year}</h2><button className="icon-button" onClick={()=>shift(1)} aria-label="Tháng sau"><ChevronRight size={17}/></button></div><div className="month-grid">{['T2','T3','T4','T5','T6','T7','CN'].map(d=><span className="weekday" key={d}>{d}</span>)}{Array.from({length:first},(_,i)=><span key={'blank'+i}/>)}{Array.from({length:days},(_,i)=>{const value=month+'-'+String(i+1).padStart(2,'0');return <button key={value} aria-label={'Ngày '+value} aria-pressed={date===value} disabled={value<todayVN()} className={'day'+(value===date?' selected':'')+(value===todayVN()?' today':'')} onClick={()=>setDate(value)}>{i+1}</button>})}</div><p className="muted">Chọn ngày để xem các khung giờ đã đặt. Giờ hiển thị theo Việt Nam (UTC+7).</p><div className="form-grid form-section"><label className="field">Gói lưu trú<select value={stay} onChange={e=>setStay(e.target.value as StayPackage)}>{Object.entries(packages).map(([id,label])=><option value={id} key={id}>{label}</option>)}</select></label><label className="field">{stay==='overnight'?'Giờ nhận qua đêm':'Giờ nhận phòng'}<input type="time" value={stay==='overnight'?data?.overnight?.checkIn||'':time} disabled={stay==='overnight'} onChange={e=>setTime(e.target.value)}/></label></div>{stay==='overnight' && !window && <p className="notice">Giờ qua đêm cần xác nhận với home. Gọi <a href="tel:0357907153">0357 907 153</a> để được tư vấn.</p>}</section><section className="panel"><div className="calendar-nav"><h2>Ngày {date.split('-').reverse().join('/')}</h2><button className="icon-button" onClick={refresh} aria-label="Làm mới lịch" disabled={loading}><RefreshCw size={16}/></button></div><label className="field">Không gian<select value={filter} onChange={e=>setFilter(e.target.value)}><option value="all">Tất cả phòng</option>{rooms.map(r=><option value={r.id} key={r.id}>{r.name} Room</option>)}</select></label><div aria-live="polite">{loading?<p className="notice">Đang tải lịch phòng…</p>:!data?.connected?<p className="notice">{data?.error || 'Lịch trực tuyến chưa mở. Liên hệ Lặng để kiểm tra phòng trống; các ngày trên lịch chưa thể hiện tình trạng còn phòng.'}</p>:null}{rooms.filter(r=>filter==='all'||r.id===filter).map(room=>{
 const info=data?.rooms?.find(r=>r.id===room.id)
 const canCheck=Boolean(!loading&&data?.connected&&window&&info?.active)
 const busy=canCheck && info?.intervals.some(i=>overlaps(window!.start,window!.end,new Date(i.start),new Date(i.end)))
 const past=Boolean(window && window.start<=new Date())
 const status=!canCheck?'Cần kiểm tra':past?'Đã qua giờ':busy?'Đã có lịch':'Còn trống'
 return <div className="availability-room" key={room.id}><div className="availability-title"><h3>{room.name} Room</h3><span className={'status-chip '+(canCheck&&!past?(busy?'busy':'free'):'')}>{status}</span></div><p>{money(room.prices[stay])} / {packages[stay].toLowerCase()}</p>{data?.connected && info?.intervals.filter(i=>new Date(i.start)<new Date(date+'T23:59:59+07:00')&&new Date(i.end)>new Date(date+'T00:00:00+07:00')).map((i,index)=><p key={index}>{i.status==='BLOCKED'?'Tạm khóa':i.status==='PENDING'?'Đang giữ chỗ':'Đã đặt'}: {displayTime(i.start)} — {displayTime(i.end)}</p>)}{canCheck&&!busy&&!past&&data?.onlineBooking?<Link className="text-link" href={`/bookings?room=${room.id}&date=${date}&package=${stay}&time=${time}&guests=${guests==='1'?'1':'2'}`}>Đặt khung giờ này <ArrowUpRight size={14}/></Link>:<a className="text-link" href="https://zalo.me/0357907153" target="_blank" rel="noreferrer">Nhắn Lặng tư vấn <ArrowUpRight size={14}/></a>}</div>
 })}</div></section></div></>
}

