'use client'
import { useState,FormEvent } from 'react'
import { money, bookingStatuses as statuses } from '@/lib/rooms'
export type BookingResult={bookingCode:string;room:string;checkIn:string;checkOut:string;total:number;status:string;paymentStatus:string;expires:string;qrCode?:string;checkoutUrl?:string}
export default function Lookup() {
 const [result,setResult]=useState<BookingResult|null>(null)
 const [error,setError]=useState('')
 const [loading,setLoading]=useState(false)
 async function submit(event:FormEvent<HTMLFormElement>) {event.preventDefault();const f=new FormData(event.currentTarget);setLoading(true);setError('');setResult(null);try{const r=await fetch('/api/bookings/lookup',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({code:String(f.get('code')).trim().toUpperCase(),phone:f.get('phone')})});const d=await r.json();if(!r.ok)throw new Error(d.error);setResult(d)}catch(e){setError(e instanceof Error?e.message:'Không thể tra cứu.')}finally{setLoading(false)}}
 return <section className="panel lookup-panel" id="tra-cuu"><h2>Bạn đã có một cuộc hẹn?</h2><p className="muted">Tra cứu bằng mã đặt phòng và số điện thoại đã sử dụng.</p><form onSubmit={submit} className="lookup-form"><label className="field">Mã đặt phòng<input name="code" required maxLength={30} placeholder="LANG-…"/></label><label className="field">Số điện thoại<input name="phone" type="tel" required autoComplete="tel" placeholder="Số điện thoại đặt phòng"/></label><button className="button" disabled={loading}>{loading?'Đang tra cứu…':'Tra cứu đặt phòng'}</button></form>{error&&<p role="alert" className="notice error">{error}</p>}{result&&<div className="notice" role="status"><strong>{result.bookingCode} · {result.room}</strong><p>{statuses[result.status] || result.status} · {money(result.total)}</p><p>{new Date(result.checkIn).toLocaleString('vi-VN',{timeZone:'Asia/Ho_Chi_Minh'})} → {new Date(result.checkOut).toLocaleString('vi-VN',{timeZone:'Asia/Ho_Chi_Minh'})}</p>{result.paymentStatus==='PAID'&&result.status==='CANCELLED'?<p>Đã nhận thanh toán nhưng đơn chưa được xác nhận. Liên hệ Lặng để đối soát hoặc hoàn tiền.</p>:<p>{result.paymentStatus==='PAID'?'Đã thanh toán':'Chưa thanh toán'}</p>}{result.checkoutUrl&&<a href={result.checkoutUrl} className="text-link">Tiếp tục thanh toán ↗</a>}</div>}</section>
}

