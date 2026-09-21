'use client'
import Link from 'next/link'
import { usePathname } from 'next/navigation'
import { useState } from 'react'
import { Menu, X, ArrowUpRight, Phone } from 'lucide-react'
import Logo from '@/components/Logo'
export default function Navbar() {
const [open, setOpen] = useState(false)
const path = usePathname()
const links = [['/', 'Trang chủ'], ['/#ve-lang', 'Về Lặng'], ['/rooms', 'Phòng & giá'], ['/calendar', 'Lịch phòng'], ['/#lien-he', 'Liên hệ']]
return <header className="site-header"><div className="container nav-inner"><Link href="/" aria-label="Lặng Home — Trang chủ"><Logo /></Link><nav className={open ? 'nav-links open' : 'nav-links'} aria-label="Điều hướng chính">{links.map(([href,label]) => <Link onClick={() => setOpen(false)} key={href} href={href} className={path === href ? 'active' : ''}>{label}</Link>)}</nav><div className="nav-actions"><a href="tel:0357907153" className="nav-phone"><Phone size={15}/>0357 907 153</a><Link className="button small" href="/bookings">Đặt phòng <ArrowUpRight size={16}/></Link><button className="menu-toggle" aria-label={open ? 'Đóng menu' : 'Mở menu'} aria-expanded={open} onClick={() => setOpen(!open)}>{open ? <X/> : <Menu/>}</button></div></div></header>
}
