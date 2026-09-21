import { Metadata } from 'next'
import PaymentView from '@/components/booking/PaymentView'
export const metadata:Metadata={title:'Thanh toán QR',robots:{index:false,follow:false},referrer:'no-referrer'}
export default function PaymentPage({searchParams}:{searchParams:{code?:string}}){return <div className="container section"><PaymentView code={searchParams.code||''}/></div>}

