import Image from 'next/image'
import Link from 'next/link'
import { notFound } from 'next/navigation'
import { rooms,packages,money,StayPackage } from '@/lib/rooms'
export function generateStaticParams() { return rooms.map(r => ({ slug:r.id })) }
export function generateMetadata({ params }: { params:{slug:string} }) { const room=rooms.find(r=>r.id===params.slug); return {title:room ? `${room.name} Room` : 'Không tìm thấy phòng'} }
export default function RoomPage({ params }: { params:{slug:string} }) {
const room=rooms.find(r=>r.id===params.slug); if(!room) notFound()
return <div className="container section"><Link className="text-link" href="/rooms">← Tất cả phòng</Link><div className="detail-grid"><div className="detail-image"><Image src={room.image} fill priority sizes="(max-width: 700px) 100vw, 55vw" alt={`Ảnh minh họa phòng ${room.name}`}/><span className="image-caption">Ảnh minh họa — ảnh thực tế đang cập nhật</span></div><div><span className="eyebrow">{room.tag}</span><h1>{room.name} Room</h1><p>{room.description}</p><p className="muted">2 khách · 1 giường đôi · Self check-in</p><div className="detail-prices">{Object.entries(packages).map(([key,label])=><Link key={key} href={`/bookings?room=${room.id}&package=${key}`}><span>{label}</span><strong>{money(room.prices[key as StayPackage])} ↗</strong></Link>)}</div><Link className="button" href={`/calendar?room=${room.id}`}>Xem lịch phòng trống</Link><p className="muted">Cần tư vấn? <a href="tel:0357907153">0357 907 153</a></p></div></div></div>
}
