import type { Metadata } from 'next'
import BookingForm from '@/components/booking/BookingForm'
export const metadata:Metadata={title:'Đặt phòng',robots:{index:false,follow:true}}
export default function BookingPage({searchParams}:{searchParams:Record<string,string|undefined>}){return <div className="container section"><div className="center-heading"><span className="eyebrow">MỘT KHOẢNG NGHỈ DÀNH CHO BẠN</span><h1>Hẹn gặp bạn tại Lặng.</h1><p>Chọn không gian, để lại lời nhắn. Mình chuẩn bị một cuộc hẹn nhé.</p></div><BookingForm initial={searchParams}/></div>}

