'use client'
import { CalendarDays, Moon, Users, ArrowRight } from 'lucide-react'
import { todayVN } from '@/lib/rooms'
export default function SearchBar() {
return <form action="/calendar" className="search-bar"><label><span><CalendarDays size={16}/>NGÀY GHÉ LẶNG</span><input aria-label="Ngày nhận phòng" type="date" name="date" min={todayVN()} required defaultValue={todayVN()}/></label><label><span><Moon size={16}/>BẠN MUỐN Ở LẠI</span><select name="package" defaultValue="overnight"><option value="overnight">Qua đêm</option><option value="3h">Gói 3 giờ</option><option value="6h">Gói 6 giờ</option></select></label><label><span><Users size={16}/>SỐ KHÁCH</span><select name="guests" defaultValue="2"><option value="1">1 người lớn</option><option value="2">2 người lớn</option></select></label><button className="button" type="submit">Tìm phòng trống <ArrowRight size={17}/></button></form>
}
