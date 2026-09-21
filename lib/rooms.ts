export type StayPackage = '3h' | '6h' | 'overnight'
export const packages: Record<StayPackage, string> = { '3h': 'Gói 3 giờ', '6h': 'Gói 6 giờ', overnight: 'Qua đêm' }
export const rooms = [
{ id: 'pink', name: 'Pink', subtitle: 'Một chút ngọt ngào, một chút mơ mộng.', description: 'Dành một khoảng thời gian thật riêng cho nhau, trong không gian dịu dàng và ấm áp.', tag: 'Dịu dàng & lãng mạn', image: '/images/pink-illustration.jpg', prices: { '3h': 200000, '6h': 380000, overnight: 330000 } },
{ id: 'white', name: 'White', subtitle: 'Nhẹ nhàng như một ngày không vội.', description: 'Một căn phòng sáng, tinh giản và dễ chịu. Tạm gác những bộn bề để tận hưởng khoảng thời gian của riêng bạn.', tag: 'Tinh giản & thư thái', image: '/images/white-illustration.jpg', prices: { '3h': 150000, '6h': 350000, overnight: 250000 } },
{ id: 'black', name: 'Black', subtitle: 'Một không gian, một sắc thái riêng.', description: 'Không gian trầm ấm dành cho những ai yêu sự riêng tư. Thả mình nghỉ ngơi và để nhịp sống chậm lại.', tag: 'Cá tính & riêng tư', image: '/images/black-illustration.jpg', prices: { '3h': 180000, '6h': 300000, overnight: 320000 } },
]
export const money = (value: number) => new Intl.NumberFormat('vi-VN').format(value) + 'đ'
export const todayVN = () => new Intl.DateTimeFormat('en-CA', { timeZone: 'Asia/Ho_Chi_Minh', year: 'numeric', month: '2-digit', day: '2-digit' }).format(new Date())


export const bookingStatuses: Record<string, string> = { PENDING: "Đang giữ phòng, chờ thanh toán", CONFIRMED: "Đã xác nhận", CHECKED_IN: "Đã nhận phòng", CHECKED_OUT: "Đã trả phòng", CANCELLED: "Đã hủy", EXPIRED: "Hết thời gian giữ phòng" }
