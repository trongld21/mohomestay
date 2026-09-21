import type { Metadata } from 'next'
import RoomCollection from '@/components/home/RoomCollection'
import { rooms,money } from '@/lib/rooms'
export const metadata: Metadata = { title: 'Phòng & bảng giá' }
export default function RoomsPage() { return <div className="container section"><div className="center-heading"><span className="eyebrow">CHỌN MỘT GÓC NHỎ CHO RIÊNG MÌNH</span><h1>Phòng & bảng giá</h1><p>Ba sắc thái. Một cảm giác thân quen.</p></div><RoomCollection/><section className="price-section"><h2>Một khoảng nghỉ, vừa với bạn.</h2><div className="table-scroll"><table><caption>Bảng giá phòng Lặng Home · đơn vị VNĐ</caption><thead><tr><th>Gói lưu trú</th>{rooms.map(r => <th key={r.id}>{r.name}</th>)}</tr></thead><tbody>{(['3h','6h','overnight'] as const).map(k => <tr key={k}><td>{k === 'overnight' ? 'Qua đêm' : k === '3h' ? '3 giờ' : '6 giờ'}</td>{rooms.map(r => <td key={r.id}>{money(r.prices[k])}</td>)}</tr>)}</tbody></table></div><p className="muted">Giờ qua đêm, phụ thu ngày lễ và chính sách hủy cần xác nhận với home.</p></section></div> }

