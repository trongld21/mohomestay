import { getServerSession } from 'next-auth'
import { redirect } from 'next/navigation'
import { authOptions } from '@/lib/auth'
import { db,databaseEnabled } from '@/lib/db'
import { money, bookingStatuses as statuses } from '@/lib/rooms'
import AdminActions,{Logout} from '@/components/booking/AdminActions'
export const dynamic='force-dynamic'
export const metadata={title:'Quản lý',robots:{index:false,follow:false}}
export default async function AdminPage(){
 const session=await getServerSession(authOptions)
 if(!session?.user?.email || session.user.email!==process.env.ADMIN_EMAIL?.toLowerCase())redirect('/auth/login')
 let bookings:Awaited<ReturnType<typeof getBookings>>=[]
 let error=''
 try{if(databaseEnabled())bookings=await getBookings();else error='Cơ sở dữ liệu chưa được bật.'}catch{error='Không thể kết nối cơ sở dữ liệu.'}
 return <div className="container section"><div className="section-heading"><div><span className="eyebrow">KHÔNG GIAN QUẢN LÝ</span><h1>Chào chủ nhà.</h1><p>100 đơn gần nhất · giờ Việt Nam</p></div><Logout/></div>{error?<p className="notice error">{error}</p>:<><div className="room-grid mb-8"><div className="panel"><p>Đã thu (trong danh sách)</p><h2 className="mt-3">{money(bookings.filter(b=>b.paymentStatus==='PAID').reduce((sum,b)=>sum+b.totalPrice,0))}</h2></div><div className="panel"><p>Đơn đã xác nhận</p><h2 className="mt-3">{bookings.filter(b=>b.status==='CONFIRMED').length}</h2></div><div className="panel"><p>Cần đối soát / hoàn tiền</p><h2 className="mt-3">{bookings.filter(b=>b.paymentStatus==='PAID'&&b.status==='CANCELLED').length}</h2></div></div><div className="table-scroll"><table><thead><tr><th>Đơn / khách</th><th>Phòng / lịch</th><th>Thanh toán</th><th>Trạng thái / thao tác</th></tr></thead><tbody>{bookings.map(b=><tr key={b.id}><td><strong>{b.bookingCode}</strong><p>{b.guestName}</p><a href={'tel:'+b.guestPhone}>{b.guestPhone}</a>{b.guestNote&&<p className="muted" style={{whiteSpace:'normal',maxWidth:240}}>{b.guestNote}</p>}</td><td>{b.room.name}<p>{b.checkInDate.toLocaleString('vi-VN',{timeZone:'Asia/Ho_Chi_Minh'})}</p><p>{b.checkOutDate.toLocaleString('vi-VN',{timeZone:'Asia/Ho_Chi_Minh'})}</p></td><td>{money(b.totalPrice)}<p>{b.paymentStatus==='PAID'?'Đã thanh toán':'Chưa thanh toán'}</p>{b.payment?.failureReason&&<p className="notice error" style={{whiteSpace:'normal',maxWidth:230}}>{b.payment.failureReason}</p>}</td><td><p className="mb-3">{b.status==='PENDING'&&b.holdExpiresAt&&b.holdExpiresAt<new Date()?'Đã hết hạn giữ phòng':statuses[b.status]}</p><AdminActions id={b.id} status={b.status}/></td></tr>)}</tbody></table>{!bookings.length&&<p className="notice">Chưa có đơn đặt phòng. Đơn mới sẽ xuất hiện tại đây.</p>}</div></>}</div>
}
async function getBookings(){return db.booking.findMany({take:100,orderBy:{createdAt:'desc'},include:{room:true,payment:true}})}

