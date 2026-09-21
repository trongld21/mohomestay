import type { Metadata } from 'next'
import './globals.css'
import Navbar from '@/components/layout/Navbar'
import Footer from '@/components/layout/Footer'
export const metadata: Metadata = {
metadataBase: new URL(process.env.NEXT_PUBLIC_SITE_URL || 'http://localhost:3000'),
title: { default: 'Lặng Home — Một chốn riêng, một nhịp chậm', template: '%s | Lặng Home' },
description: 'Lặng Home tại 82 đường B18, KDC 91B, Ninh Kiều, Cần Thơ. Khám phá Pink, White, Black với giá từ 150.000đ, lịch phòng theo giờ và qua đêm.',
openGraph: { title: 'Lặng Home — Một chốn riêng, một nhịp chậm', description: 'Không gian riêng tư dành cho những ngày bạn muốn sống chậm lại.', locale: 'vi_VN', type: 'website' },
}
export default function RootLayout({ children }: { children: React.ReactNode }) {
return <html lang="vi"><body><a className="skip-link" href="#main-content">Đến nội dung chính</a><Navbar/><main id="main-content">{children}</main><Footer/></body></html>
}
