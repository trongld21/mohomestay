'use client'
import { useEffect,useState } from 'react'
import Link from 'next/link'
import QRCode from 'react-qr-code'
import { money, bookingStatuses as statuses } from '@/lib/rooms'
import type { BookingResult } from './Lookup'
export default function PaymentView({code}:{code:string}) {
 const [booking,setBooking]=useState<BookingResult|null>(null)
 const [error,setError]=useState('')
 const [now,setNow]=useState(Date.now())
 useEffect(()=>{
 let stopped=false
 const controller=new AbortController()
 const token=location.hash.slice(1)
 if(!token){setError('Vui lòng tra cứu bằng mã đặt phòng và số điện thoại để tiếp tục.');return}
 async function poll(){
 try{const r=await fetch('/api/bookings/lookup',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({code,token}),signal:controller.signal});const data=await r.json();if(!r.ok)throw new Error(data.error);if(!stopped){setBooking(data);setError('')}}catch(e){if(!stopped)setError(e instanceof Error?e.message:'Không thể kiểm tra thanh toán.')}
 }
 poll();const interval=setInterval(poll,5000);const tick=setInterval(()=>setNow(Date.now()),1000)
 return ()=>{stopped=true;controller.abort();clearInterval(interval);clearInterval(tick)}
 },[code])
 const remaining=booking?Math.max(0,Math.floor((new Date(booking.expires).getTime()-now)/1000)):0
 return <div className="payment-container panel text-center"><span className="eyebrow">HOÀN TẤT CUỘC HẸN</span><h1 style={{fontSize:38}}>Thanh toán đặt phòng</h1>{error&&<p className="notice error" role="alert">{error}</p>}{!booking&&!error&&<p className="notice">Đang tải thông tin thanh toán…</p>}{booking&&<><p className="muted">{booking.bookingCode} · {booking.room}</p><h2>{money(booking.total)}</h2><p className={'notice '+(booking.status==='CONFIRMED'?'success':'')} role="status">{statuses[booking.status]}{booking.paymentStatus==='PAID'&&booking.status==='CANCELLED'&&' — Đã nhận tiền, cần liên hệ Lặng để đối soát/hoàn tiền.'}</p>{booking.status==='PENDING'&&remaining>0&&booking.qrCode&&<><div className="qr-wrap"><QRCode value={booking.qrCode} size={220}/></div><p>Mở ứng dụng ngân hàng để quét mã QR.</p><p className="muted">Giữ phòng còn {Math.floor(remaining/60)}:{String(remaining%60).padStart(2,'0')}. Trạng thái tự cập nhật khi nhận thanh toán.</p>{booking.checkoutUrl&&<a className="button" href={booking.checkoutUrl}>Mở trang thanh toán payOS ↗</a>}</>}{booking.status==='PENDING'&&remaining===0&&<p className="notice">Đã hết thời gian giữ phòng. Không chuyển thêm tiền; liên hệ Lặng nếu bạn vừa thanh toán.</p>}{booking.status==='CONFIRMED'&&<p className="notice success">Hẹn gặp bạn! Lặng sẽ liên hệ riêng để hướng dẫn nhận phòng và gửi mã khóa cửa.</p>}</>}<div className="flex justify-center gap-5 mt-6"><Link className="text-link" href="/bookings#tra-cuu">Tra cứu đơn</Link><a className="text-link" href="tel:0357907153">Gọi Lặng hỗ trợ</a></div></div>
}

