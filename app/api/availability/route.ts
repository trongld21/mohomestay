import { NextResponse } from 'next/server'
import { db,databaseEnabled } from '@/lib/db'
import { rooms } from '@/lib/rooms'
import { activeBookings,overnightConfig } from '@/lib/booking'
import { paymentsEnabled } from '@/lib/payment'
export const dynamic = 'force-dynamic'
export async function GET(request: Request) {
 const date = new URL(request.url).searchParams.get('date') || ''
 if(!/^\d{4}-\d{2}-\d{2}$/.test(date) || !Number.isFinite(new Date(date+'T00:00:00Z').getTime()) || new Date(date+'T00:00:00Z').toISOString().slice(0,10)!==date) return NextResponse.json({error:'Ngày không hợp lệ.'},{status:400})
 const config = {overnight:overnightConfig(),terms:process.env.BOOKING_TERMS || '',onlineBooking:databaseEnabled() && paymentsEnabled()}
 if(!databaseEnabled()) return NextResponse.json({...config,connected:false,rooms:rooms.map(r=>({id:r.id,intervals:[]}))},{headers:{'Cache-Control':'no-store'}})
 try {
 const start = new Date(date+'T00:00:00+07:00')
 // Includes following day so overnight windows can be checked accurately.
 const end = new Date(start.getTime()+2*86400000)
 const [bookings,blocks,inventory] = await Promise.all([
 db.booking.findMany({where:{checkInDate:{lt:end},checkOutDate:{gt:start},...activeBookings(new Date())},select:{roomId:true,checkInDate:true,checkOutDate:true,status:true}}),
 db.roomAvailability.findMany({where:{available:false,date:{gte:new Date(date+'T00:00:00Z'),lt:new Date(new Date(date+'T00:00:00Z').getTime()+2*86400000)}},select:{roomId:true,date:true}}),
 db.room.findMany({select:{id:true,isActive:true}})])
 return NextResponse.json({...config,connected:true,rooms:rooms.map(r=>({id:r.id,active:inventory.some(i=>i.id===r.id&&i.isActive),intervals:[
 ...bookings.filter(b=>b.roomId===r.id).map(b=>({start:b.checkInDate,end:b.checkOutDate,status:b.status})),
 ...blocks.filter(b=>b.roomId===r.id).map(b=>{const start=new Date(b.date.toISOString().slice(0,10)+'T00:00:00+07:00');return {start,end:new Date(start.getTime()+86400000),status:'BLOCKED'}})
 ]}))},{headers:{'Cache-Control':'no-store'}})
 } catch { return NextResponse.json({...config,connected:false,onlineBooking:false,error:'Chưa tải được lịch phòng. Bạn thử lại hoặc liên hệ Lặng nhé.',rooms:[]},{status:503}) }
}

