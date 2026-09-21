import type { Metadata } from 'next'
import CalendarView from '@/components/booking/CalendarView'
export const metadata: Metadata={title:'Lịch phòng trống'}
export default function CalendarPage({searchParams}:{searchParams:Record<string,string|undefined>}) {return <div className="container section"><div className="center-heading"><span className="eyebrow">HẸN MỘT NGÀY THẬT THẢNH THƠI</span><h1>Lịch phòng của Lặng</h1><p>Chọn ngày, tìm một khung giờ dành riêng cho bạn.</p></div><CalendarView initialDate={searchParams.date} initialRoom={searchParams.room} initialPackage={searchParams.package} guests={searchParams.guests}/></div>}

