import { createHmac, timingSafeEqual } from 'node:crypto'
export function generateSignature(data: Record<string, unknown>, key: string): string {
 const normalized = Object.keys(data).sort().map(k=>{
   let value = data[k]
   if(value == null || value === 'null' || value === 'undefined') value = ''
   if(Array.isArray(value)) value = JSON.stringify(value.map(item => item && typeof item === 'object' ? Object.fromEntries(Object.keys(item).sort().map(key=>[key,item[key]])) : item))
   return k+'='+String(value)
 }).join('&')
 return createHmac('sha256',key).update(normalized).digest('hex')
}
export function verifySignature(data: Record<string,unknown>, signature: string, key: string) {
 if(!key || !/^[a-f0-9]{64}$/i.test(signature)) return false
 return timingSafeEqual(Buffer.from(generateSignature(data,key),'hex'),Buffer.from(signature,'hex'))
}
export const paymentsEnabled = () => Boolean(process.env.PAYOS_CLIENT_ID && process.env.PAYOS_API_KEY && process.env.PAYOS_CHECKSUM_KEY && process.env.NEXT_PUBLIC_SITE_URL && process.env.BOOKING_TERMS)
export async function createPayment(input: { orderCode:number; amount:number; bookingCode:string; token:string; expires:Date }) {
 if(!paymentsEnabled()) throw new Error('Payment is not configured')
 const url = new URL('/payment',process.env.NEXT_PUBLIC_SITE_URL)
 url.searchParams.set('code',input.bookingCode)
 // The capability token is kept in the fragment, never sent in URL logs or referrers.
 url.hash = input.token
 const fields = {amount:input.amount,cancelUrl:url.toString(),description:'LANG'+input.bookingCode.slice(-5),orderCode:input.orderCode,returnUrl:url.toString()}
 const response = await fetch('https://api-merchant.payos.vn/v2/payment-requests',{
 method:'POST',headers:{'Content-Type':'application/json','x-client-id':process.env.PAYOS_CLIENT_ID!,'x-api-key':process.env.PAYOS_API_KEY!},
 body:JSON.stringify({...fields,expiredAt:Math.floor(input.expires.getTime()/1000),signature:generateSignature(fields,process.env.PAYOS_CHECKSUM_KEY!)}),
 signal:AbortSignal.timeout(15000),cache:'no-store'
 })
 const result = await response.json()
 if(!response.ok || result.code !== '00' || !result.data?.qrCode || !verifySignature(result.data,result.signature || '',process.env.PAYOS_CHECKSUM_KEY!)) throw new Error('Unable to create a verified payment link')
 if(result.data.amount !== input.amount || result.data.orderCode !== input.orderCode) throw new Error('Payment response mismatch')
 const checkout = new URL(result.data.checkoutUrl)
 if(checkout.protocol !== 'https:' || checkout.hostname !== 'pay.payos.vn') throw new Error('Invalid checkout URL')
 return {qrCode:String(result.data.qrCode),checkoutUrl:checkout.toString(),paymentLinkId:String(result.data.paymentLinkId)}
}

