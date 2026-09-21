import { NextResponse } from 'next/server'
import { randomBytes, randomInt } from 'node:crypto'
import { db,databaseEnabled } from '@/lib/db'
import { BookingError,quoteBooking,overnightConfig,activeBookings } from '@/lib/booking'
import { paymentsEnabled,createPayment } from '@/lib/payment'
export const dynamic = 'force-dynamic'
export async function POST(request: Request) {
 try {
  if(Number(request.headers.get('content-length') || 0)>8192) throw new BookingError('Dữ liệu quá lớn.',413)
  const body = await request.json()
  if(!body || typeof body !== 'object' || Array.isArray(body)) throw new BookingError('Dữ liệu không hợp lệ.')
  const quote = quoteBooking(body,overnightConfig())
  if(!databaseEnabled() || !paymentsEnabled()) throw new BookingError('Đặt phòng trực tuyến chưa mở. Vui lòng gọi 0357 907 153 hoặc nhắn Zalo để đặt phòng.',503)
  const now = new Date()
  const booking = await db.$transaction(async tx=>{
    // Lock the room row: all writers must take this same lock before overlap checks.
    const locked = await tx.$queryRaw<{id:string}[]>`SELECT id FROM rooms WHERE id = ${quote.room.id} FOR UPDATE`
    if(!locked.length) throw new BookingError('Phòng chưa sẵn sàng nhận đặt trực tuyến.',503)
    const room = await tx.room.findUnique({where:{id:quote.room.id}})
    if(!room?.isActive) throw new BookingError('Phòng đang tạm ngưng nhận khách.',409)
    const block = await tx.roomAvailability.findFirst({where:{roomId:room.id,available:false,date:{gte:new Date(new Date(quote.start.getTime()+7*3600000).toISOString().slice(0,10)),lte:new Date(new Date(quote.end.getTime()-1+7*3600000).toISOString().slice(0,10))}}})
    if(block) throw new BookingError('Phòng tạm khóa trong khoảng thời gian này.',409)
    const conflicting = await tx.booking.findFirst({where:{roomId:room.id,checkInDate:{lt:quote.end},checkOutDate:{gt:quote.start},...activeBookings(now)}})
    if(conflicting) throw new BookingError('Phòng vừa được đặt trong khung giờ này. Bạn chọn giờ khác nhé.',409)
    const holds = await tx.booking.count({where:{guestPhone:quote.guestPhone,status:'PENDING',holdExpiresAt:{gt:now}}})
    if(holds>=2) throw new BookingError('Bạn đang có đơn chờ thanh toán. Vui lòng hoàn tất hoặc đợi hết thời gian giữ phòng.',429)
    return tx.booking.create({data:{
      bookingCode:'LANG-'+randomBytes(5).toString('hex').toUpperCase(),accessToken:randomBytes(32).toString('hex'),
      roomId:room.id,checkInDate:quote.start,checkOutDate:quote.end,stayPackage:quote.stay,totalPrice:quote.totalPrice,
      guestName:quote.guestName,guestPhone:quote.guestPhone,guestEmail:quote.guestEmail,guestNote:quote.guestNote,
      numberOfGuests:quote.numberOfGuests,holdExpiresAt:new Date(now.getTime()+15*60000),
      orderCode:BigInt(Date.now()*1000+randomInt(1000)),paymentMethod:'payos',
    }})
  })
  try {
    const payment = await createPayment({orderCode:Number(booking.orderCode),amount:booking.totalPrice,bookingCode:booking.bookingCode,token:booking.accessToken,expires:booking.holdExpiresAt!})
    // A fast webhook can arrive before this write. Preserve its paid status.
    await db.payment.upsert({where:{bookingId:booking.id},create:{bookingId:booking.id,amount:booking.totalPrice,paymentMethod:'payos',status:'PENDING',qrCode:payment.qrCode,paymentGateway:payment.checkoutUrl},update:{qrCode:payment.qrCode,paymentGateway:payment.checkoutUrl}})
  } catch {
    // Keep the hold until expiry if a network timeout may have created a payable link.
    return NextResponse.json({error:'Chưa lấy được mã QR. Phòng được giữ tối đa 15 phút để đối soát; vui lòng liên hệ Lặng trước khi đặt lại.',bookingCode:booking.bookingCode},{status:502})
  }
  return NextResponse.json({bookingCode:booking.bookingCode,token:booking.accessToken},{status:201,headers:{'Cache-Control':'no-store'}})
 } catch(error) {
  if(error instanceof BookingError) return NextResponse.json({error:error.message},{status:error.status})
  if(error instanceof SyntaxError) return NextResponse.json({error:'Dữ liệu không hợp lệ.'},{status:400})
  return NextResponse.json({error:'Chưa thể tạo đặt phòng. Vui lòng thử lại hoặc liên hệ Lặng.'},{status:503})
 }
}
export async function GET() { return NextResponse.json({error:'Vui lòng dùng mã đặt phòng và số điện thoại để tra cứu.'},{status:401}) }
