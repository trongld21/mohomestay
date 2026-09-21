import { NextResponse } from 'next/server'
import { db,databaseEnabled } from '@/lib/db'
export const dynamic = 'force-dynamic'
export async function POST(request: Request) {
 try {
  if(!databaseEnabled()) return NextResponse.json({error:'Tra cứu trực tuyến chưa mở. Bạn liên hệ Lặng qua 0357 907 153 nhé.'},{status:503})
  const {code,phone,token} = await request.json()
  if(typeof code !== 'string' || !/^LANG-[A-F0-9]{10}$/.test(code) || (!token && !phone)) return NextResponse.json({error:'Vui lòng nhập mã đặt phòng và số điện thoại hợp lệ.'},{status:400})
  const booking = await db.booking.findFirst({where:{bookingCode:code,...(typeof token === 'string' && /^[a-f0-9]{64}$/.test(token) ? {accessToken:token} : {guestPhone:typeof phone === 'string' ? phone.replace(/[\s.-]/g,'') : ''})},include:{room:true,payment:true}})
  if(!booking) return NextResponse.json({error:'Không tìm thấy đơn khớp với thông tin đã nhập.'},{status:404})
  const expired = booking.status === 'PENDING' && Boolean(booking.holdExpiresAt && booking.holdExpiresAt <= new Date())
  return NextResponse.json({bookingCode:booking.bookingCode,room:booking.room.name,checkIn:booking.checkInDate,checkOut:booking.checkOutDate,total:booking.totalPrice,status:expired?'EXPIRED':booking.status,paymentStatus:booking.paymentStatus,expires:booking.holdExpiresAt,qrCode:!expired && booking.paymentStatus !== 'PAID'?booking.payment?.qrCode:null,checkoutUrl:!expired && booking.paymentStatus !== 'PAID'?booking.payment?.paymentGateway:null},{headers:{'Cache-Control':'no-store'}})
 } catch { return NextResponse.json({error:'Không thể tra cứu lúc này. Vui lòng thử lại.'},{status:503}) }
}

