import { rooms, StayPackage } from './rooms'
export class BookingError extends Error { constructor(message: string, public status = 400) { super(message) } }
export function stayWindow(date: string, time: string, stay: StayPackage, overnight?: { checkIn: string; checkOut: string }) {
 if (!/^\d{4}-\d{2}-\d{2}$/.test(date)) throw new BookingError('Ngày không hợp lệ.')
 if (!['3h','6h','overnight'].includes(stay)) throw new BookingError('Gói lưu trú không hợp lệ.')
 const startTime = stay === 'overnight' ? overnight?.checkIn : time
 if (!startTime || !/^([01]\d|2[0-3]):[0-5]\d$/.test(startTime)) throw new BookingError('Vui lòng liên hệ Lặng để xác nhận giờ nhận phòng.')
 const start = new Date(date + 'T' + startTime + ':00+07:00')
 if (!Number.isFinite(start.getTime()) || new Date(start.getTime()+7*3600000).toISOString().slice(0,10) !== date) throw new BookingError('Ngày không hợp lệ.')
 let end: Date
 if(stay === 'overnight') {
   if (!overnight?.checkOut || !/^([01]\d|2[0-3]):[0-5]\d$/.test(overnight.checkOut)) throw new BookingError('Giờ trả phòng qua đêm chưa được xác nhận.')
   const nextDate = new Date(new Date(date+'T00:00:00Z').getTime()+86400000).toISOString().slice(0,10)
   end = new Date(nextDate+'T'+overnight.checkOut+':00+07:00')
 } else end = new Date(start.getTime()+(stay === '3h' ? 3 : 6)*3600000)
 return { start, end }
}
export function quoteBooking(input: Record<string, unknown>, overnight?: { checkIn: string; checkOut: string }, now = new Date()) {
 const room = rooms.find(r=>r.id === input.roomId)
 if(!room) throw new BookingError('Phòng không hợp lệ.')
 const stay = input.stayPackage as StayPackage
 const {start,end} = stayWindow(String(input.date || ''),String(input.time || ''),stay,overnight)
 if(start <= now) throw new BookingError('Vui lòng chọn giờ nhận phòng trong tương lai.')
 if(start.getTime()-now.getTime()>366*86400000) throw new BookingError('Chỉ nhận đặt phòng trong vòng 12 tháng.')
 if(!Number.isInteger(input.numberOfGuests) || Number(input.numberOfGuests)<1 || Number(input.numberOfGuests)>2) throw new BookingError('Mỗi phòng nhận từ 1 đến 2 khách.')
 const guestName = typeof input.guestName === 'string' ? input.guestName.trim() : ''
 const guestPhone = typeof input.guestPhone === 'string' ? input.guestPhone.replace(/[\s.-]/g,'') : ''
 const guestEmail = typeof input.guestEmail === 'string' ? input.guestEmail.trim() : ''
 if(guestName.length<2 || guestName.length>100) throw new BookingError('Vui lòng nhập họ tên từ 2 đến 100 ký tự.')
 if(!/^(?:0[35789]\d{8}|\+84[35789]\d{8})$/.test(guestPhone)) throw new BookingError('Số điện thoại Việt Nam chưa hợp lệ.')
 if(guestEmail.length>254 || (guestEmail && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(guestEmail))) throw new BookingError('Email chưa hợp lệ.')
 if(input.acceptTerms !== true) throw new BookingError('Vui lòng đồng ý điều kiện đặt phòng.')
 const guestNote = typeof input.guestNote === 'string' ? input.guestNote.trim() : ''
 if(guestNote.length>1000) throw new BookingError('Lời nhắn tối đa 1.000 ký tự.')
 return {room,start,end,stay,totalPrice:room.prices[stay],guestName,guestPhone,guestEmail,guestNote,numberOfGuests:Number(input.numberOfGuests)}
}
export const overlaps = (aStart:Date,aEnd:Date,bStart:Date,bEnd:Date) => aStart < bEnd && aEnd > bStart
export function overnightConfig() { return { checkIn:process.env.OVERNIGHT_CHECK_IN || '',checkOut:process.env.OVERNIGHT_CHECK_OUT || '' } }
export const activeBookings = (now:Date) => ({ OR: [{status:{in:['CONFIRMED','CHECKED_IN'] as ('CONFIRMED'|'CHECKED_IN')[]}},{status:'PENDING' as const,holdExpiresAt:{gt:now}}] })

