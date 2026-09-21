import { getServerSession } from 'next-auth'
import { NextResponse } from 'next/server'
import { authOptions } from '@/lib/auth'
import { db,databaseEnabled } from '@/lib/db'
export async function PATCH(request:Request){
 const session=await getServerSession(authOptions)
 if(!session?.user?.email || session.user.email!==process.env.ADMIN_EMAIL?.toLowerCase())return NextResponse.json({error:'Không có quyền truy cập.'},{status:401})
 if(!databaseEnabled())return NextResponse.json({error:'Chưa kết nối cơ sở dữ liệu.'},{status:503})
 try{
 const {id,status}=await request.json()
 if(typeof id!=='string'||!['CANCELLED','CHECKED_IN','CHECKED_OUT'].includes(status))return NextResponse.json({error:'Thao tác không hợp lệ.'},{status:400})
 const booking=await db.booking.findUnique({where:{id}})
 if(!booking)return NextResponse.json({error:'Không tìm thấy đơn.'},{status:404})
 await db.$transaction(async tx=>{
 await tx.$queryRaw`SELECT id FROM rooms WHERE id = ${booking.roomId} FOR UPDATE`
 const current=await tx.booking.findUniqueOrThrow({where:{id}})
 const allowed:Record<string,string[]>={PENDING:['CANCELLED'],CONFIRMED:['CHECKED_IN','CANCELLED'],CHECKED_IN:['CHECKED_OUT']}
 if(!allowed[current.status]?.includes(status))throw new Error('Invalid transition')
 await tx.booking.update({where:{id},data:{status}})
 })
 return NextResponse.json({ok:true})
 }catch{return NextResponse.json({error:'Không thể cập nhật trạng thái. Hãy tải lại danh sách.'},{status:409})}
}

