import { NextResponse } from 'next/server'
import { db } from '@/lib/db'
import { verifySignature } from '@/lib/payment'
import { activeBookings } from '@/lib/booking'
export const dynamic = 'force-dynamic'
export async function POST(request: Request) {
 try {
 const payload = await request.json()
 const data = payload?.data
 if(!data || typeof data !== 'object' || typeof payload.signature !== 'string' || !verifySignature(data,payload.signature,process.env.PAYOS_CHECKSUM_KEY || '')) return NextResponse.json({error:'Invalid signature'},{status:401})
 if(data.code !== '00' || data.currency !== 'VND') return NextResponse.json({ok:true})
 if(!Number.isSafeInteger(data.orderCode) || !Number.isSafeInteger(data.amount) || typeof data.reference !== 'string') return NextResponse.json({error:'Invalid payment data'},{status:400})
 const booking = await db.booking.findUnique({where:{orderCode:BigInt(data.orderCode)}})
 // payOS registration sends a signed sample for a non-existing order.
 if(!booking) return NextResponse.json({ok:true})
 if(data.amount !== booking.totalPrice) return NextResponse.json({error:'Amount mismatch'},{status:400})
 await db.$transaction(async tx=>{
   await tx.$queryRaw`SELECT id FROM rooms WHERE id = ${booking.roomId} FOR UPDATE`
   const current = await tx.booking.findUniqueOrThrow({where:{id:booking.id}})
   if(current.paymentStatus === 'PAID') return
   const conflict = await tx.booking.findFirst({where:{id:{not:booking.id},roomId:booking.roomId,checkInDate:{lt:booking.checkOutDate},checkOutDate:{gt:booking.checkInDate},...activeBookings(new Date())}})
   // Late or cancelled payments are recorded but never silently re-confirmed.
   const validHold = current.status==='PENDING' && current.holdExpiresAt && current.holdExpiresAt>new Date() && !conflict
   await tx.booking.update({where:{id:booking.id},data:{paymentStatus:'PAID',status:validHold?'CONFIRMED':'CANCELLED',transactionId:data.reference}})
   await tx.payment.upsert({where:{bookingId:booking.id},create:{bookingId:booking.id,amount:data.amount,paymentMethod:'payos',status:'PAID',transactionId:data.reference,paidAt:new Date(),failureReason:validHold?null:'Đã nhận tiền sau thời hạn hoặc đơn đã hủy. Cần đối soát/hoàn tiền.'},update:{status:'PAID',transactionId:data.reference,paidAt:new Date(),failureReason:validHold?null:'Đã nhận tiền sau thời hạn hoặc đơn đã hủy. Cần đối soát/hoàn tiền.'}})
 })
 return NextResponse.json({ok:true})
 } catch { return NextResponse.json({error:'Webhook processing failed'},{status:500}) }
}

